<?php

namespace App\Jobs;

use App\Events\SignLanguageBroadcastEvent;
use App\Models\Sesion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Convierte el texto ya transcrito/traducido en una secuencia de glosas de lengua de senas y
 * las difunde por su propio canal (SignLanguageBroadcastEvent). Vive en una cola separada
 * ('low-priority') a proposito: procesar/renderizar glosas es mas lento y menos critico que
 * la traduccion por voz, y NUNCA debe competir por el mismo hilo/worker que esa traduccion.
 *
 * Este job solo se despacha si el guard en SesionController::processAudio() confirma que el
 * modulo esta habilitado globalmente Y activado para esa sesion especifica -- ver ese metodo
 * para el punto exacto de enganche.
 */
class ProcessSignGlossesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 15;

    public function __construct(
        public readonly int $sessionId,
        public readonly array $textData,
    ) {
    }

    public function handle(): void
    {
        $sesion = Sesion::find($this->sessionId);

        // Revalidar aqui (no solo en el dispatch): para cuando el worker recoge el job, la
        // sesion pudo borrarse o el toggle pudo apagarse. Sin este chequeo, un job encolado
        // antes de apagar el flag seguiria emitiendo señas de todos modos.
        if (! $sesion || ! config('spikia.features.sign_avatar') || ! $sesion->has_sign_avatar) {
            return;
        }

        $texto = trim((string) ($this->textData['texto'] ?? ''));
        if ($texto === '') {
            return;
        }

        // Motor real (sign-nlp-service, Python/FastAPI + GPT-4o): devuelve la secuencia
        // completa (glosa + animacion + duracion) ya lista para Unreal Engine. Si el servicio
        // esta caido/mal configurado, cae al placeholder local de siempre - el modulo nunca
        // debe fallar duro solo porque el pipeline de avatar 3D con MetaHuman no esta
        // disponible en este momento.
        $sequence = $this->translateViaNlpService($texto);

        if ($sequence !== null && $sequence !== []) {
            $glosses = array_column($sequence, 'gloss');
            $this->pushToOrchestrator($sesion, $sequence);
        } else {
            try {
                $glosses = $this->textToGlosses($texto);
            } catch (\Throwable $e) {
                Log::error('ProcessSignGlossesJob: fallo generando glosas de lengua de senas (fallback local).', [
                    'sesion_id' => $this->sessionId,
                    'message' => $e->getMessage(),
                ]);

                return;
            }
        }

        if ($glosses === []) {
            return;
        }

        // Se mantiene SIEMPRE (venga la secuencia del motor real o del fallback local): es lo
        // que consume avatar-engine.js, el renderer 3D existente - el pipeline de Unreal
        // Engine es un canal ADICIONAL (via pushToOrchestrator), no un reemplazo.
        broadcast(new SignLanguageBroadcastEvent($sesion->slug, $glosses, $this->textData));
    }

    /**
     * Llama a sign-nlp-service (POST /translate-to-sign) para obtener la secuencia real de
     * glosas (con animacion y duracion, ver GlossFrame en ese servicio). Devuelve null si el
     * servicio no esta configurado, no responde, o devuelve algo que no se puede interpretar
     * - en cualquiera de esos casos el llamador cae al placeholder local.
     *
     * @return array<int, array{gloss: string, animation: string, duration: float, is_fingerspelling: bool}>|null
     */
    private function translateViaNlpService(string $texto): ?array
    {
        $baseUrl = trim((string) config('spikia.sign_avatar_pipeline.nlp_service_url', ''));
        if ($baseUrl === '') {
            return null;
        }

        try {
            $token = (string) config('spikia.sign_avatar_pipeline.nlp_service_token', '');
            $timeout = (float) config('spikia.sign_avatar_pipeline.nlp_service_timeout', 6.0);

            $response = Http::timeout($timeout)
                ->when($token !== '', fn ($request) => $request->withHeaders(['X-Internal-Token' => $token]))
                ->post(rtrim($baseUrl, '/') . '/translate-to-sign', [
                    'text' => $texto,
                    'session_id' => $this->textData['slug'] ?? null,
                ]);

            if (! $response->successful()) {
                Log::warning('ProcessSignGlossesJob: sign-nlp-service respondio con error, usando fallback local.', [
                    'sesion_id' => $this->sessionId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $sequence = $response->json('sequence');

            return is_array($sequence) ? $sequence : null;
        } catch (\Throwable $e) {
            // No se loguea como 'error' a proposito: que sign-nlp-service este caido es una
            // condicion ESPERADA (servicio opcional, separado del resto de Spikia) y no debe
            // ensuciar los logs como si fuera una falla real del sistema principal.
            Log::info('ProcessSignGlossesJob: sign-nlp-service no disponible, usando fallback local.', [
                'sesion_id' => $this->sessionId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Publica la secuencia enriquecida en sign-avatar-orchestrator (Node.js), que la encola y
     * se la reenvia, seña por seña, a la instancia de Unreal Engine conectada para esta
     * sesion. Fire-and-forget: un fallo aca nunca debe tumbar el job ni afectar el resto del
     * pipeline de traduccion (por eso el try/catch envuelve TODA la llamada).
     *
     * @param array<int, array{gloss: string, animation: string, duration: float, is_fingerspelling: bool}> $sequence
     */
    private function pushToOrchestrator(Sesion $sesion, array $sequence): void
    {
        $baseUrl = trim((string) config('spikia.sign_avatar_pipeline.orchestrator_url', ''));
        if ($baseUrl === '') {
            return;
        }

        try {
            $token = (string) config('spikia.sign_avatar_pipeline.orchestrator_token', '');
            $timeout = (float) config('spikia.sign_avatar_pipeline.orchestrator_timeout', 3.0);

            Http::timeout($timeout)
                ->when($token !== '', fn ($request) => $request->withHeaders(['X-Internal-Token' => $token]))
                ->post(rtrim($baseUrl, '/') . "/api/sessions/{$sesion->slug}/sign-sequence", [
                    'session_id' => $sesion->slug,
                    'sequence' => $sequence,
                ]);
        } catch (\Throwable $e) {
            Log::info('ProcessSignGlossesJob: no se pudo publicar la secuencia en sign-avatar-orchestrator.', [
                'sesion_id' => $this->sessionId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Placeholder de texto -> glosas (mayusculas, sin stopwords basicas de español). Fallback
     * de emergencia cuando sign-nlp-service no esta disponible - NO produce animacion ni
     * duracion real, solo los nombres de glosa para que el renderer 3D existente
     * (avatar-engine.js) tenga algo que mostrar.
     */
    private function textToGlosses(string $texto): array
    {
        static $stopwords = [
            'el', 'la', 'los', 'las', 'de', 'del', 'y', 'a', 'en', 'que', 'un', 'una',
            'se', 'por', 'con', 'no', 'su', 'al', 'lo', 'como', 'mas', 'pero', 'sus',
        ];

        $words = preg_split('/\s+/u', mb_strtolower(trim($texto))) ?: [];

        return collect($words)
            ->map(fn ($word) => preg_replace('/[^\p{L}\p{N}]/u', '', (string) $word))
            ->filter(fn ($word) => $word !== '' && ! in_array($word, $stopwords, true))
            ->map(fn ($word) => mb_strtoupper($word))
            ->values()
            ->all();
    }
}
