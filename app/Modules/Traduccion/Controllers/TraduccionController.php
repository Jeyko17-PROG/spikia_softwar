<?php

namespace App\Modules\Traduccion\Controllers;

use App\Events\NuevaTraduccion;
use App\Http\Controllers\Controller;
use App\Models\Sesion;
use App\Models\Traduccion;
use App\Models\Transcripcion;
use App\Events\TranscripcionCreada;
use App\Services\OpenAITranslationService;
use App\Services\OpenAiRealtimeTokenService;
use App\Services\RealtimeBenchmarkMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stichoza\GoogleTranslate\GoogleTranslate;

class TraduccionController extends Controller
{
    public function __construct(
        private OpenAITranslationService $openai,
        private OpenAiRealtimeTokenService $realtimeTokens,
    ) {}

    public function store(Request $request)
    {
        $tStart = microtime(true);
        $data = $request->validate([
            'sesion_id' => ['required', 'integer'],
            'texto' => ['required', 'string'],
            'idioma' => ['required', 'string', 'max:10'],
            'variante' => ['nullable', 'string', 'max:10'],
            'publish_to_relay' => ['nullable', 'boolean'],
        ]);

        $sesion = Sesion::find($data['sesion_id']);

        if (! $sesion) {
            return response()->json(['error' => 'Sesion no encontrada'], 404);
        }

        $tTranslateStart = microtime(true);
        $textoTraducido = $this->translateText($sesion, $data['texto'], $data['idioma']);
        $tTranslateMs = (microtime(true) - $tTranslateStart) * 1000;
        Log::info('spikia.translate', [
            'sesion_id' => $sesion->id,
            'target' => $data['idioma'],
            'len' => mb_strlen($data['texto']),
            'duration_ms' => round($tTranslateMs, 1),
        ]);

        $traduccion = Traduccion::create([
            'sesion_id' => $sesion->id,
            'texto_original' => $data['texto'],
            'texto_traducido' => $textoTraducido,
            'idioma' => $data['idioma'],
        ]);

        $relayMessage = null;
        if (($data['publish_to_relay'] ?? true) && trim($textoTraducido) !== '') {
            $relayMessage = $this->publishToRelay($sesion->slug, [
                'texto' => $textoTraducido,
                'idioma' => strtolower(explode('-', $data['idioma'])[0] ?? $data['idioma']),
                'variante' => $data['variante'] ?? (str_contains($data['idioma'], '-') ? $data['idioma'] : null),
                'tipo' => 'traduccion',
            ]);
            $this->broadcastRelayMessage($sesion, $relayMessage);
        }

        $this->deferNuevaTraduccionEvent($sesion->slug, $traduccion->id, $textoTraducido, $data['idioma']);

        return response()->json([
            'success' => true,
            'traduccion' => $textoTraducido,
            'message' => $relayMessage,
        ]);
    }

    /**
     * NuevaTraduccion no tiene ningun listener en JS ni en PHP (canal/evento sin consumidor
     * hoy), pero es ShouldBroadcast: con QUEUE_CONNECTION=sync se ejecuta en linea igual que
     * ShouldBroadcastNow. Sin diferir, seria una llamada HTTPS a Pusher extra por idioma sin
     * ningun beneficio. Se mantiene (no se borra) por si algo externo ya lo consume, pero
     * fuera del camino critico de la respuesta.
     */
    private function deferNuevaTraduccionEvent(string $slug, int $traduccionId, string $texto, string $idioma): void
    {
        app()->terminating(function () use ($slug, $traduccionId, $texto, $idioma) {
            try {
                event(new NuevaTraduccion($slug, $traduccionId, $texto, $idioma));
            } catch (\Throwable $e) {
                Log::error('Error en evento: ' . $e->getMessage());
            }
        });
    }

    /**
     * Version por lotes de store(): resuelve TODOS los idiomas destino de una frase
     * en una sola peticion HTTP desde el cliente, con las N llamadas a OpenAI corriendo
     * concurrentes dentro del mismo request (via translateBatch), en vez de que el
     * navegador dispare N fetch() independientes que se serializan si el servidor no
     * tiene concurrencia real. Esta es la ruta que master.js usa en el flujo en vivo.
     */
    public function storeBatch(Request $request)
    {
        $data = $request->validate([
            'sesion_id' => ['required', 'integer'],
            'texto' => ['required', 'string'],
            'idiomas' => ['required', 'array', 'min:1'],
            'idiomas.*' => ['required', 'string', 'max:10'],
            'variante' => ['nullable', 'string', 'max:10'],
            'publish_to_relay' => ['nullable', 'boolean'],
        ]);

        $sesion = Sesion::find($data['sesion_id']);

        if (! $sesion) {
            return response()->json(['error' => 'Sesion no encontrada'], 404);
        }

        $targetLanguages = array_values(array_unique($data['idiomas']));

        $tStart = microtime(true);
        $translations = $this->translateTextBatch($sesion, $data['texto'], $targetLanguages);
        $durationMs = (microtime(true) - $tStart) * 1000;
        Log::info('spikia.translate_batch', [
            'sesion_id' => $sesion->id,
            'targets' => $targetLanguages,
            'len' => mb_strlen($data['texto']),
            'duration_ms' => round($durationMs, 1),
        ]);

        $results = [];

        foreach ($targetLanguages as $idioma) {
            $textoTraducido = (string) ($translations[$idioma] ?? '');

            if (trim($textoTraducido) === '') {
                $results[$idioma] = ['success' => false];
                continue;
            }

            $traduccion = Traduccion::create([
                'sesion_id' => $sesion->id,
                'texto_original' => $data['texto'],
                'texto_traducido' => $textoTraducido,
                'idioma' => $idioma,
            ]);

            $relayMessage = null;
            if ($data['publish_to_relay'] ?? true) {
                $relayMessage = $this->publishToRelay($sesion->slug, [
                    'texto' => $textoTraducido,
                    'idioma' => strtolower(explode('-', $idioma)[0] ?? $idioma),
                    'variante' => str_contains($idioma, '-') ? $idioma : null,
                    'tipo' => 'traduccion',
                ]);
                $this->broadcastRelayMessage($sesion, $relayMessage);
            }

            $this->deferNuevaTraduccionEvent($sesion->slug, $traduccion->id, $textoTraducido, $idioma);

            $results[$idioma] = [
                'success' => true,
                'traduccion' => $textoTraducido,
                'message' => $relayMessage,
            ];
        }

        return response()->json([
            'success' => true,
            'translations' => $results,
        ]);
    }

    /**
     * PoC/benchmark (ver informe "reduccion de delay a 1-2s"): el navegador YA tradujo el
     * texto con OpenAI Realtime (ver realtimeToken() + resources/js/realtime-translate-poc.js)
     * - este endpoint no llama a ningun motor de traduccion, solo reusa el MISMO camino de
     * persistencia/relay/broadcast que storeBatch() para que el oyente reciba el resultado
     * exactamente igual que hoy, y registra los timestamps de cada etapa para medir el
     * delay real end-to-end (ver comando spikia:realtime-benchmark:report).
     */
    public function storeRealtimeBenchmark(Request $request)
    {
        $data = $request->validate([
            'sesion_id' => ['required', 'integer'],
            'texto_original' => ['required', 'string'],
            'texto_traducido' => ['required', 'string'],
            'idioma' => ['required', 'string', 'max:10'],
            'variante' => ['nullable', 'string', 'max:10'],
            // 'text' (Web Speech API -> Realtime texto) | 'audio' (brazo A: audio ->
            // gpt-realtime-2.1 con VAD) | 'translate_dedicated' (brazo B: audio ->
            // gpt-realtime-translate, streaming continuo). Solo para etiquetar el reporte.
            'arm' => ['nullable', 'string', 'in:text,audio,translate_dedicated'],
            // Timestamps del navegador, epoch ms (Date.now()). t_speech_start: brazo texto
            // = primer interim (proxy); brazo audio = evento real
            // input_audio_buffer.speech_started del VAD; brazo translate_dedicated = primer
            // delta observado (proxy - este modelo no expone VAD, ver informe). Si no
            // llega, se usa t_stt_final.
            't_speech_start' => ['nullable', 'numeric'],
            't_stt_final' => ['required', 'numeric'],
            't_realtime_sent' => ['required', 'numeric'],
            't_realtime_received' => ['required', 'numeric'],
            // Primer resultado parcial de TRADUCCION (delta), para medir "first result
            // latency" por separado del resultado final.
            't_first_result' => ['nullable', 'numeric'],
            // Solo brazo B: primer delta de TRANSCRIPCION (idioma origen), distinto del
            // primer delta de traduccion - ver informe "A vs B".
            't_first_transcript_delta' => ['nullable', 'numeric'],
            'timeline' => ['nullable', 'array'],
            'publish_to_relay' => ['nullable', 'boolean'],
        ]);

        $sesion = Sesion::find($data['sesion_id']);

        if (! $sesion) {
            return response()->json(['error' => 'Sesion no encontrada'], 404);
        }

        $tServerReceived = microtime(true) * 1000;
        $textoTraducido = trim($data['texto_traducido']);

        Traduccion::create([
            'sesion_id' => $sesion->id,
            'texto_original' => $data['texto_original'],
            'texto_traducido' => $textoTraducido,
            'idioma' => $data['idioma'],
        ]);

        $relayMessage = null;
        if (($data['publish_to_relay'] ?? true) && $textoTraducido !== '') {
            $relayMessage = $this->publishToRelay($sesion->slug, [
                'texto' => $textoTraducido,
                'idioma' => strtolower(explode('-', $data['idioma'])[0] ?? $data['idioma']),
                'variante' => $data['variante'] ?? (str_contains($data['idioma'], '-') ? $data['idioma'] : null),
                'tipo' => 'traduccion',
            ]);
            $this->broadcastRelayMessage($sesion, $relayMessage);
        }

        $tSpeechStart = (float) ($data['t_speech_start'] ?? $data['t_stt_final']);
        $tSttFinal = (float) $data['t_stt_final'];
        $tRealtimeSent = (float) $data['t_realtime_sent'];
        $tRealtimeReceived = (float) $data['t_realtime_received'];
        $tFirstResult = isset($data['t_first_result']) ? (float) $data['t_first_result'] : null;
        $tFirstTranscriptDelta = isset($data['t_first_transcript_delta']) ? (float) $data['t_first_transcript_delta'] : null;
        $timeline = is_array($data['timeline'] ?? null) ? $data['timeline'] : [];
        $arm = (string) ($data['arm'] ?? 'text');
        $textoOriginal = $data['texto_original'];
        $idioma = $data['idioma'];
        $sesionId = $sesion->id;

        // El push real a Pusher/Echo se dispara DESPUES de responder (app()->terminating,
        // ver broadcastRelayMessage) - se registra el benchmark aqui mismo para que
        // "ms_server_to_pusher" refleje el momento real del push, no cuando este metodo
        // simplemente termina de ejecutar.
        app()->terminating(function () use ($sesionId, $arm, $idioma, $textoOriginal, $tSpeechStart, $tSttFinal, $tRealtimeSent, $tRealtimeReceived, $tFirstResult, $tFirstTranscriptDelta, $tServerReceived, $timeline) {
            $tRelayPublished = microtime(true) * 1000;
            $metrics = RealtimeBenchmarkMetrics::fromTimeline($timeline);

            Log::info('spikia.realtime_benchmark', [
                'sesion_id' => $sesionId,
                'arm' => $arm,
                'idioma' => $idioma,
                'texto' => Str::limit($textoOriginal, 80),
                'ms_speech_to_stt_final' => round($tSttFinal - $tSpeechStart, 1),
                'ms_stt_to_realtime_sent' => round($tRealtimeSent - $tSttFinal, 1),
                'ms_realtime_translate' => round($tRealtimeReceived - $tRealtimeSent, 1),
                'ms_network_to_server' => round($tServerReceived - $tRealtimeReceived, 1),
                'ms_server_to_pusher' => round($tRelayPublished - $tServerReceived, 1),
                'ms_total_speech_to_pusher' => round($tRelayPublished - $tSpeechStart, 1),
                // "First result latency": lo que pediste medir especialmente - cuanto tarda
                // en verse el PRIMER fragmento traducido, no la frase completa.
                'ms_speech_to_first_result' => $tFirstResult !== null ? round($tFirstResult - $tSpeechStart, 1) : null,
                'ms_first_to_final_result' => $tFirstResult !== null ? round($tRealtimeReceived - $tFirstResult, 1) : null,
                // Solo brazo B: primer delta de TRANSCRIPCION (idioma origen).
                'ms_speech_to_first_transcript' => $tFirstTranscriptDelta !== null ? round($tFirstTranscriptDelta - $tSpeechStart, 1) : null,
                'timeline' => $timeline,
                'metrics' => $metrics,
            ]);
        });

        return response()->json([
            'success' => true,
            'traduccion' => $textoTraducido,
            'message' => $relayMessage,
        ]);
    }

    /**
     * PoC/benchmark: emite un token efimero de OpenAI Realtime para que master.js abra UNA
     * conexion WebSocket directa que se mantiene viva durante TODA la sesion (requisito:
     * no abrir/cerrar una conexion por frase). El PoC solo cubre el idioma configurado en
     * spikia.realtime_translation.poc_target_language (hoy 'en').
     */
    public function realtimeToken(Request $request)
    {
        $data = $request->validate([
            'sesion_id' => ['required', 'integer'],
            'idioma' => ['required', 'string', 'max:10'],
            // 'text' (default) | 'audio' (brazo A: gpt-realtime-2.1 + VAD) |
            // 'translate_dedicated' (brazo B: gpt-realtime-translate, streaming continuo).
            'mode' => ['nullable', 'string', 'in:text,audio,translate_dedicated'],
        ]);

        $mode = (string) ($data['mode'] ?? 'text');

        // Brazos 'text' y 'translate_dedicated' siguen siendo solo PoC/benchmark, acotados
        // al idioma configurado en poc_target_language. El brazo 'audio' (Audio+VAD) ya es
        // el motor de produccion (ver config/spikia.php) y cubre CUALQUIER idioma de la
        // sesion: master.js abre una conexion Realtime por idioma destino.
        if ($mode !== 'audio') {
            $pocLanguage = (string) config('spikia.realtime_translation.poc_target_language', 'en');
            if ($data['idioma'] !== $pocLanguage) {
                return response()->json([
                    'success' => false,
                    'message' => "El PoC de Realtime solo cubre '{$pocLanguage}' por ahora.",
                ], 422);
            }
        }

        $sesion = Sesion::where('id', $data['sesion_id'])
            ->where('user_id', Auth::id())
            ->first();

        if (! $sesion) {
            return response()->json(['success' => false, 'message' => 'Sesion no encontrada'], 404);
        }

        if ($mode === 'translate_dedicated') {
            // Brazo B: este modelo no acepta instructions/prompt - no se construye.
            $result = $this->realtimeTokens->mintClientSecretForDedicatedTranslation($data['idioma']);
        } else {
            $instructions = $this->buildRealtimeInstructions($sesion, $data['idioma']);
            $result = $mode === 'audio'
                ? $this->realtimeTokens->mintClientSecretForAudio($instructions)
                : $this->realtimeTokens->mintClientSecret($instructions);
        }

        return response()->json($result, $result['success'] ? 200 : 502);
    }

    /**
     * Instrucciones para la sesion Realtime: mismo prompt maestro + glosario que usa hoy
     * translateBatch(), formateadas directo aqui (sin tocar OpenAITranslationService, cuyos
     * metodos de formato de glosario son privados) - simplificacion aceptada para el PoC,
     * documentada en el informe de resultados.
     */
    private function buildRealtimeInstructions(Sesion $sesion, string $targetLanguage): string
    {
        $settings = $this->resolveOpenAiSettings($sesion);
        [$glossaryTerms, $glossaryPairs] = $this->resolveGlossary($sesion);

        $prompt = $settings['prompt'] !== '' ? $settings['prompt'] : (string) config(
            'spikia.translation_simultaneous.master_translation_prompt',
            'Eres un traductor simultaneo. Traduce TODO el texto recibido de forma literal y completa.'
        );

        $instructions = $prompt
            . "\n\nIdioma de salida: {$targetLanguage}. Traduce cada mensaje del usuario a este idioma."
            . "\n- Responde SOLO con la traduccion, sin prefijos ni explicaciones."
            . "\n- Cada mensaje del usuario es una frase independiente, no continues una conversacion previa.";

        if (! empty($glossaryPairs)) {
            $lines = [];
            foreach ($glossaryPairs as $pair) {
                $src = trim((string) ($pair['source'] ?? ''));
                $tgt = trim((string) ($pair['target'] ?? ''));
                if ($src !== '' && $tgt !== '') {
                    $lines[] = "{$src}={$tgt}";
                }
            }
            if ($lines) {
                $instructions .= "\n\nPairs: " . implode(' | ', $lines);
            }
        }

        if (! empty($glossaryTerms)) {
            $clean = array_slice(array_values(array_unique(array_filter($glossaryTerms, fn ($t) => is_string($t) && trim($t) !== ''))), 0, 80);
            if ($clean) {
                $instructions .= "\n\nGlossary (preserve proper nouns, translate medical terms): " . implode(', ', $clean);
            }
        }

        return $instructions;
    }

    /**
     * Empuja el mensaje ya escrito en el cache de relay tambien por WebSocket (Echo/Pusher).
     * Antes, las traducciones (a diferencia del transcript original) solo llegaban al oyente
     * por polling: publishToRelay() nunca disparaba un evento de broadcast, asi que activar
     * BROADCAST_CONNECTION no las aceleraba. Reusa el mismo evento/canal que ya escucha
     * listener.js para el transcript original (transmision.{slug} / TranscripcionCreada).
     *
     * El evento se dispara DESPUES de enviar la respuesta HTTP (app()->terminating), no en
     * linea: TranscripcionCreada es ShouldBroadcastNow, y con QUEUE_CONNECTION=sync eso es
     * una llamada HTTPS bloqueante a Pusher por cada idioma. Emitirla en linea reintroduciria
     * exactamente el problema que este endpoint batch existe para resolver (ver .env, que
     * documentaba por que broadcasting estaba apagado). Con terminating() el master recibe su
     * respuesta al instante y el push a los oyentes sale unos milisegundos despues, sin
     * necesitar un worker de colas aparte.
     */
    private function broadcastRelayMessage(Sesion $sesion, array $message): void
    {
        $transcripcion = new Transcripcion([
            'sesion_id' => $sesion->id,
            'slug' => $sesion->slug,
            'texto' => $message['texto'] ?? '',
            'idioma' => $message['variante'] ?: ($message['idioma'] ?? 'es'),
            'audio_url' => $message['audio_url'] ?? null,
            'modo' => $message['tipo'] ?? 'texto',
        ]);
        $transcripcion->id = $message['id'] ?? (string) Str::uuid();
        $transcripcion->setAttribute('genero', $message['genero'] ?? null);
        $transcripcion->setAttribute('variante', $message['variante'] ?? null);
        $transcripcion->setAttribute('tipo', $message['tipo'] ?? 'texto');
        $transcripcion->setAttribute('published_at', $message['published_at'] ?? now()->timestamp);
        $transcripcion->setAttribute('available_at', $message['available_at'] ?? now()->timestamp);
        $transcripcion->setAttribute('revision', $message['revision'] ?? 1);
        $slug = $sesion->slug;

        app()->terminating(function () use ($transcripcion, $slug) {
            try {
                event(new TranscripcionCreada($transcripcion, $slug));
            } catch (\Throwable $e) {
                Log::error('No se pudo emitir la traduccion en vivo por broadcast: ' . $e->getMessage());
            }
        });
    }

    private function publishToRelay(string $slug, array $payload): array
    {
        $cacheKey = 'spikia:relay:' . Str::slug($slug);
        $messages = Cache::get($cacheKey, []);
        $now = now()->timestamp;
        $message = [
            'id' => (string) Str::uuid(),
            'texto' => (string) ($payload['texto'] ?? ''),
            'idioma' => (string) ($payload['idioma'] ?? 'es'),
            'variante' => $payload['variante'] ?? null,
            'genero' => $payload['genero'] ?? null,
            'tipo' => $payload['tipo'] ?? 'traduccion',
            'audio_url' => $payload['audio_url'] ?? null,
            'published_at' => $now,
            'available_at' => $now,
            'revision' => 1,
        ];

        $messages[] = $message;
        $messages = array_slice($messages, -200);
        Cache::put($cacheKey, $messages, now()->addHours(12));

        return $message;
    }

    private function translateText(Sesion $sesion, string $text, string $targetLanguage): string
    {
        $settings = $this->resolveOpenAiSettings($sesion);
        [$glossaryTerms, $glossaryPairs] = $this->resolveGlossary($sesion);

        if ($settings['usable']) {
            try {
                return $this->openai->translate(
                    $text,
                    'auto',
                    $targetLanguage,
                    $settings['model'],
                    $settings['prompt'],
                    $glossaryTerms,
                    $glossaryPairs
                );
            } catch (\Throwable $e) {
                $this->registerOpenAiFailure($e->getMessage());
            }
        }

        return $this->translateViaFallback($text, $targetLanguage, $glossaryTerms, $glossaryPairs);
    }

    /**
     * Version por lotes de translateText(): un solo round-trip a OpenAI (via translateBatch,
     * que resuelve todos los idiomas con promesas concurrentes) en vez de N llamadas
     * secuenciales/independientes. El glosario y el prompt se resuelven UNA vez para todos
     * los idiomas del lote, no una vez por idioma.
     *
     * @return array<string,string> idioma => texto traducido ('' si fallo)
     */
    private function translateTextBatch(Sesion $sesion, string $text, array $targetLanguages): array
    {
        $settings = $this->resolveOpenAiSettings($sesion);
        [$glossaryTerms, $glossaryPairs] = $this->resolveGlossary($sesion);

        $results = [];
        $pending = $targetLanguages;

        if ($settings['usable']) {
            $items = [];
            foreach ($targetLanguages as $lang) {
                $items[$lang] = ['text' => $text, 'from' => 'auto', 'to' => $lang];
            }

            try {
                $batchResults = $this->openai->translateBatch(
                    $items,
                    $settings['model'],
                    $settings['prompt'],
                    $glossaryTerms,
                    $glossaryPairs
                );
            } catch (\Throwable $e) {
                $this->registerOpenAiFailure($e->getMessage());
                $batchResults = [];
            }

            $pending = [];
            foreach ($targetLanguages as $lang) {
                $translated = $batchResults[$lang] ?? null;
                if (is_string($translated) && trim($translated) !== '') {
                    $results[$lang] = $translated;
                } else {
                    $pending[] = $lang;
                }
            }
        }

        // Solo los idiomas que OpenAI no resolvio (o el lote entero si OpenAI esta
        // deshabilitado) pasan por el fallback DeepL/Google, uno por uno.
        foreach ($pending as $lang) {
            $results[$lang] = $this->translateViaFallback($text, $lang, $glossaryTerms, $glossaryPairs);
        }

        return $results;
    }

    /**
     * @return array{model:string,prompt:string,usable:bool}
     */
    private function resolveOpenAiSettings(Sesion $sesion): array
    {
        $settings = is_array($sesion->translation_settings ?? null) ? $sesion->translation_settings : [];
        $model = (string) ($settings['translation_model'] ?? '');
        $prompt = trim((string) ($settings['master_translation_prompt'] ?? ''));
        $key = (string) config('services.openai.key', '');

        return [
            'model' => $model,
            'prompt' => $prompt,
            'usable' => $model !== '' && $prompt !== '' && $key !== '' && ! Cache::has('spikia:openai:disabled'),
        ];
    }

    /**
     * @return array{0:array,1:array} [glossaryTerms, glossaryPairs]
     */
    private function resolveGlossary(Sesion $sesion): array
    {
        $sesion->loadMissing('glosario');

        if (! $sesion->glosario) {
            return [[], []];
        }

        $glossaryTerms = $sesion->glosario->getTermsList();
        $glossaryPairs = $sesion->glosario->getTermPairs();

        Log::info('spikia.glossary', [
            'sesion_id' => $sesion->id,
            'glosario_id' => $sesion->glosario->id,
            'terms_count' => count($glossaryTerms),
            'pairs_count' => count($glossaryPairs),
        ]);

        return [$glossaryTerms, $glossaryPairs];
    }

    private function registerOpenAiFailure(string $message): void
    {
        if (preg_match('/rate limit|quota|429|insufficient|timed?\s?out|timeout|could not resolve host|connection (refused|reset)/i', $message)) {
            Cache::put('spikia:openai:disabled', $message, now()->addMinutes(2));
        }
        Log::warning('OpenAI translation failed, using fallback: ' . $message);
    }

    private function translateViaFallback(string $text, string $targetLanguage, array $glossaryTerms, array $glossaryPairs): string
    {
        // Para fallbacks (DeepL/Google) que no aceptan glosario nativamente:
        // 1) Aplicar pares directos si el destino coincide (ej. stroke -> accidente cerebrovascular)
        // 2) Proteger terminos restantes con placeholders para que NO los traduzcan mal
        $textForFallback = $this->applyPairsToText($text, $glossaryPairs, $targetLanguage);
        [$textForFallback, $placeholders] = $this->protectTerms($textForFallback, $glossaryTerms);

        $deeplKey = (string) config('services.deepl.key', '');

        if ($this->isUsableDeeplKey($deeplKey) && ! Cache::has('spikia:deepl:disabled')) {
            try {
                $response = Http::asForm()
                    ->timeout(4)
                    ->post($this->deeplEndpoint() . '/v2/translate', [
                        'auth_key' => $deeplKey,
                        'text' => $textForFallback,
                        'target_lang' => $this->normalizeDeepLTarget($targetLanguage),
                    ]);

                if ($response->ok()) {
                    $translated = data_get($response->json(), 'translations.0.text');

                    if (is_string($translated) && trim($translated) !== '') {
                        return $this->restoreTerms($translated, $placeholders);
                    }
                }

                if (in_array($response->status(), [401, 403, 456], true)) {
                    Cache::put('spikia:deepl:disabled', $response->status(), now()->addMinutes(10));
                }
            } catch (\Throwable $e) {
                Cache::put('spikia:deepl:disabled', $e->getMessage(), now()->addMinutes(2));
                Log::warning('DeepL no disponible, usando fallback local: ' . $e->getMessage());
            }
        }

        try {
            $translator = new GoogleTranslate($this->normalizeGoogleTarget($targetLanguage));
            $translator->setSource();
            $translated = $translator->translate($textForFallback);
            return $this->restoreTerms((string) $translated, $placeholders);
        } catch (\Throwable $e) {
            Log::warning('Fallback de traduccion fallo: ' . $e->getMessage());
        }

        return $text;
    }

    /**
     * Si un par origen=>destino coincide con el idioma destino actual, sustituye en el texto
     * antes de mandarlo al traductor. Asi forzamos la traduccion exacta del par.
     */
    private function applyPairsToText(string $text, array $pairs, string $targetLanguage): string
    {
        if (empty($pairs)) {
            return $text;
        }

        $targetBase = strtolower(explode('-', $targetLanguage)[0] ?? $targetLanguage);

        foreach ($pairs as $pair) {
            $source = trim((string) ($pair['source'] ?? ''));
            $target = trim((string) ($pair['target'] ?? ''));
            if ($source === '' || $target === '') continue;

            $targetLangGuess = $this->guessLanguageOfText($target);
            if ($targetLangGuess !== '' && $targetLangGuess !== $targetBase) {
                continue;
            }

            $pattern = '/\b' . preg_quote($source, '/') . '\b/iu';
            $text = preg_replace($pattern, $target, $text) ?? $text;
        }

        return $text;
    }

    /**
     * Heuristica para adivinar el idioma de un texto corto. Combina diacriticos y
     * palabras/sufijos comunes. Devuelve '' si no hay senal clara.
     */
    private function guessLanguageOfText(string $text): string
    {
        $lower = mb_strtolower($text);
        $scores = ['es' => 0, 'en' => 0, 'pt' => 0, 'fr' => 0, 'it' => 0];

        // Diacriticos
        if (preg_match('/[áéíóúñ¿¡]/u', $lower)) $scores['es'] += 2;
        if (preg_match('/[ãõç]/u', $lower)) $scores['pt'] += 3;
        if (preg_match('/[àâêîôûœ]/u', $lower)) $scores['fr'] += 3;
        if (preg_match('/[èìò]/u', $lower)) $scores['it'] += 2;

        // Sufijos tipicos
        if (preg_match('/\b\w+(ción|dad|mente|miento)\b/u', $lower)) $scores['es'] += 2;
        if (preg_match('/\w+(tion|ment|ity|ness)\b/u', $lower)) $scores['en'] += 1;
        if (preg_match('/\w+(ção|dade|mente)\b/u', $lower)) $scores['pt'] += 2;
        if (preg_match('/\w+(tion|ment|ité|esse)\b/u', $lower)) $scores['fr'] += 1;
        if (preg_match('/\w+(zione|mente|ità)\b/u', $lower)) $scores['it'] += 2;

        // Palabras comunes
        if (preg_match('/\b(el|la|los|las|de|con|paciente|accidente|insuficiencia|enfermedad|cardiaca|cerebro\w*)\b/u', $lower)) $scores['es'] += 2;
        if (preg_match('/\b(the|and|of|to|with|patient|stroke|heart|failure)\b/u', $lower)) $scores['en'] += 2;
        if (preg_match('/\b(o|a|os|as|de|com|paciente|doença|cardiaca|cerebro\w*)\b/u', $lower)) $scores['pt'] += 2;
        if (preg_match('/\b(le|la|les|de|du|avec|patient|maladie|cœur|cerveau)\b/u', $lower)) $scores['fr'] += 2;
        if (preg_match('/\b(il|la|gli|le|di|con|paziente|malattia|cuore|cervello)\b/u', $lower)) $scores['it'] += 2;

        arsort($scores);
        $top = key($scores);
        return $scores[$top] >= 2 ? $top : '';
    }

    /**
     * Reemplaza terminos "preservables" del glosario por marcadores unicos para que
     * el traductor externo NO los mangle. Solo protege nombres propios, marcas,
     * acronimos y tecnicismos raros — palabras comunes (lowercase) se dejan traducir
     * normalmente por Google/DeepL.
     */
    private function protectTerms(string $text, array $terms): array
    {
        if (empty($terms)) {
            return [$text, []];
        }

        usort($terms, fn ($a, $b) => mb_strlen($b) - mb_strlen($a));

        $placeholders = [];
        $protected = $text;
        $i = 0;

        foreach ($terms as $term) {
            $term = trim((string) $term);
            if (! $this->shouldPreserveAsProperNoun($term)) {
                continue;
            }

            $placeholder = "\u{2603}T{$i}\u{2603}";
            $i++;
            $pattern = '/\b' . preg_quote($term, '/') . '\b/iu';
            $protected = preg_replace_callback($pattern, function () use ($placeholder, $term, &$placeholders) {
                $placeholders[$placeholder] = $term;
                return $placeholder;
            }, $protected) ?? $protected;
        }

        return [$protected, $placeholders];
    }

    /**
     * Heuristica para decidir si un termino debe preservarse tal cual o dejarse traducir.
     * Preserva: nombres propios, marcas, acronimos, tecnicismos largos en mayuscula inicial.
     * No preserva: palabras comunes lowercase (paciente, urgencias, etc).
     */
    private function shouldPreserveAsProperNoun(string $term): bool
    {
        $term = trim($term);
        if (mb_strlen($term) < 3) return false;

        $firstChar = mb_substr($term, 0, 1);
        $startsUpper = $firstChar !== mb_strtolower($firstChar);

        if (! $startsUpper) {
            return false;
        }

        // Si tiene multiple uppercase (acronimo) o palabra-Palabra (marca) → preservar
        if (preg_match('/[A-Z].*[A-Z]/u', $term)) return true;

        // Si es muy largo (>= 12) y empieza en mayuscula, probablemente tecnicismo medico raro
        if (mb_strlen($term) >= 12) return true;

        // Si contiene palabras tipicas de marca/producto
        if (preg_match('/\b(Software|Platform|Plataforma|App|Inc|Ltd|System|Spikia)\b/iu', $term)) return true;

        return false;
    }

    private function restoreTerms(string $translated, array $placeholders): string
    {
        if (empty($placeholders)) {
            return $translated;
        }

        foreach ($placeholders as $placeholder => $original) {
            $translated = str_replace($placeholder, $original, $translated);
        }

        return $translated;
    }

    private function isUsableDeeplKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $normalized = strtolower($key);
        $placeholders = ['tu_clave', 'your_key', 'real_aqui', 'placeholder', 'changeme', 'xxxxxxxx'];

        foreach ($placeholders as $needle) {
            if (str_contains($normalized, $needle)) {
                return false;
            }
        }

        return strlen($key) >= 20;
    }

    private function deeplEndpoint(): string
    {
        $endpoint = (string) config('services.deepl.endpoint', '');

        if ($endpoint !== '') {
            return rtrim($endpoint, '/');
        }

        $key = (string) config('services.deepl.key', '');

        return str_ends_with($key, ':fx')
            ? 'https://api-free.deepl.com'
            : 'https://api.deepl.com';
    }

    private function normalizeDeepLTarget(string $targetLanguage): string
    {
        $base = strtolower(explode('-', $targetLanguage)[0]);

        return match ($base) {
            'pt' => 'PT-BR',
            'en' => 'EN-US',
            'es' => 'ES',
            'it' => 'IT',
            'fr' => 'FR',
            default => strtoupper($base),
        };
    }

    private function normalizeGoogleTarget(string $targetLanguage): string
    {
        return strtolower(explode('-', $targetLanguage)[0]);
    }
}
