<?php

namespace App\Modules\Sessions\Controllers;

use App\Events\TranscripcionCreada;
use App\Events\TraduccionEnviada;
use App\Http\Controllers\Controller;
use App\Jobs\PersistTranslatedAudioSegmentJob;
use App\Jobs\ProcessSignGlossesJob;
use App\Models\Glosario;
use App\Models\Sesion;
use App\Models\SessionUsageEvent;
use App\Models\Transcripcion;
use App\Models\User;
use App\Services\ElevenLabsVoiceCloningService;
use App\Services\OpenAITranslationService;
use App\Services\QrCodeService;
use App\Support\SpikiaUrl;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SesionController extends Controller
{
    private const RELAY_DELAY_SECONDS = 0;
    private const TRANSLATION_CONCURRENCY = 6;
    private const INTERIM_TTL_SECONDS = 8;
    private const CORRECTION_WINDOW_SECONDS = 8;

    public function __construct(
        private OpenAITranslationService $openai,
        private ElevenLabsVoiceCloningService $voiceCloning,
        private \App\Services\MeetingBotClient $meetingBot
    ) {}

    public function index(QrCodeService $qrCodeService)
    {
        $userId = auth()->id();
        $this->cleanupExpiredSessions($userId);

        $sesiones = Sesion::with(['glosario', 'user'])
            ->withCount('transcripciones')
            ->where('user_id', $userId)
            ->latest()
            ->paginate(8)
            ->withQueryString();

        $glosarios = Glosario::where('user_id', $userId)->get();

        $owner = User::query()
            ->whereKey($userId)
            ->withCount(['sesiones', 'glosarios', 'transcripciones', 'videos'])
            ->firstOrFail();

        $resumenContenido = [
            'sesiones' => (int) $owner->sesiones_count,
            'glosarios' => (int) $owner->glosarios_count,
            'transcripciones' => (int) $owner->transcripciones_count,
            'videos' => (int) $owner->videos_count,
        ];

        $qrSvgs = $sesiones->getCollection()->mapWithKeys(function (Sesion $sesion) use ($qrCodeService) {
            if (! $sesion->short_code) {
                $sesion->short_code = Sesion::generateUniqueShortCode();
                $sesion->saveQuietly();
            }

            $url = SpikiaUrl::public(route('sesion.short', ['code' => $sesion->short_code]));

            return [$sesion->id => $qrCodeService->transmissionSvg($sesion->slug, $url)];
        })->all();

        return view('modules.sessions.index', compact('sesiones', 'glosarios', 'resumenContenido', 'qrSvgs'));
    }

    public function store(Request $request)
    {
        $translationConfig = config('spikia.translation_simultaneous', []);
        $sttValues = collect($translationConfig['available_stt_models'] ?? [])->pluck('value')->all();
        $translationValues = collect($translationConfig['available_translation_models'] ?? [])->pluck('value')->all();
        $voiceValues = $this->availableVoiceValues($translationConfig);
        $audioDeliveryModes = collect($translationConfig['available_audio_delivery_modes'] ?? [])->pluck('value')->all();

        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'fecha_inicio' => ['nullable', 'string', 'max:255'],
            'hora_inicio' => ['nullable', 'string', 'max:255'],
            'hora_fin' => ['nullable', 'string', 'max:255'],
            'glosario_id' => [
                'nullable',
                'integer',
                Rule::exists('glosarios', 'id')->where(fn ($query) => $query->where('user_id', auth()->id())),
            ],
            'idiomas' => ['nullable', 'array'],
            'translation_mode' => ['nullable', Rule::in(['voice_to_text', 'voice_to_voice'])],
            'speech_to_text_model' => ['nullable', Rule::in($sttValues)],
            'translation_model' => ['nullable', Rule::in($translationValues)],
            'text_to_speech_model' => ['nullable', Rule::in([$translationConfig['text_to_speech_model'] ?? 'gpt-4o-mini-tts'])],
            'voice_provider' => ['nullable', Rule::in(['elevenlabs', 'openai'])],
            'voice_gender_profile' => ['nullable', Rule::in(['male', 'female'])],
            'voice' => ['nullable', Rule::in($voiceValues)],
            'audio_delivery_mode' => ['nullable', Rule::in($audioDeliveryModes)],
            'master_translation_prompt' => ['nullable', 'string'],
            'has_sign_avatar' => ['nullable', 'boolean'],
            'avatar_mode' => ['nullable', Rule::in(['3d', 'video', 'human_live'])],
            'avatar_character' => ['nullable', Rule::in(['avatar_femenino', 'avatar_masculino'])],
            'avatar_video_url' => ['nullable', 'url', 'max:500'],
            'zoom_link' => ['nullable', 'url', 'max:500'],
            'meeting_bot_source_lang' => ['nullable', 'string', 'max:10'],
        ]);

        $sesion = new Sesion();
        $sesion->user_id = auth()->id();
        $sesion->titulo = $data['titulo'];
        $sesion->fecha_inicio = $data['fecha_inicio'] ?? null;
        $sesion->hora_inicio = $data['hora_inicio'] ?? null;
        $sesion->hora_fin = $data['hora_fin'] ?? null;
        $sesion->glosario_id = $data['glosario_id'] ?? null;
        $sesion->idiomas = $data['idiomas'] ?? [];
        $sesion->translation_settings = $this->buildTranslationSettings($data);
        // El toggle solo tiene efecto real si el switch global tambien esta activo (guard en
        // processAudio); se guarda igual para que la preferencia de la sala quede lista si se
        // habilita el modulo mas adelante.
        $sesion->has_sign_avatar = config('spikia.features.sign_avatar') && $request->boolean('has_sign_avatar');
        $sesion->avatar_mode = $data['avatar_mode'] ?? '3d';
        $sesion->avatar_character = $data['avatar_character'] ?? null;
        $sesion->avatar_video_url = $data['avatar_video_url'] ?? null;
        // Enlace de la reunion de Zoom/Meet a traducir: se usa tanto para el modo manual de
        // captura de pestaña del Master como para el bot que entra solo a la reunion.
        $sesion->zoom_link = $data['zoom_link'] ?? null;
        $sesion->meeting_bot_source_lang = $data['meeting_bot_source_lang'] ?? null;
        $baseSlug = Str::slug($data['titulo']);
        do {
            $candidate = $baseSlug . '-' . Str::lower(Str::random(6));
        } while (Sesion::withTrashed()->where('slug', $candidate)->exists());
        $sesion->slug = $candidate;
        $sesion->short_code = Sesion::generateUniqueShortCode();
        $sesion->save();
        $this->recordSessionUsage($sesion, 'session_created');

        return redirect()->route('sesiones.index');
    }

    public function edit($id)
    {
        $sesion = Sesion::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $glosarios = Glosario::where('user_id', auth()->id())->get();

        return view('modules.sessions.edit', compact('sesion', 'glosarios'));
    }

    public function update(Request $request, $id)
    {
        $translationConfig = config('spikia.translation_simultaneous', []);
        $sttValues = collect($translationConfig['available_stt_models'] ?? [])->pluck('value')->all();
        $translationValues = collect($translationConfig['available_translation_models'] ?? [])->pluck('value')->all();
        $voiceValues = $this->availableVoiceValues($translationConfig);
        $audioDeliveryModes = collect($translationConfig['available_audio_delivery_modes'] ?? [])->pluck('value')->all();

        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'fecha_inicio' => ['nullable', 'string', 'max:255'],
            'hora_inicio' => ['nullable', 'string', 'max:255'],
            'hora_fin' => ['nullable', 'string', 'max:255'],
            'glosario_id' => [
                'nullable',
                'integer',
                Rule::exists('glosarios', 'id')->where(fn ($query) => $query->where('user_id', auth()->id())),
            ],
            'idiomas' => ['nullable', 'array'],
            'translation_mode' => ['nullable', Rule::in(['voice_to_text', 'voice_to_voice'])],
            'speech_to_text_model' => ['nullable', Rule::in($sttValues)],
            'translation_model' => ['nullable', Rule::in($translationValues)],
            'text_to_speech_model' => ['nullable', Rule::in([$translationConfig['text_to_speech_model'] ?? 'gpt-4o-mini-tts'])],
            'voice_provider' => ['nullable', Rule::in(['elevenlabs', 'openai'])],
            'voice_gender_profile' => ['nullable', Rule::in(['male', 'female'])],
            'voice' => ['nullable', Rule::in($voiceValues)],
            'audio_delivery_mode' => ['nullable', Rule::in($audioDeliveryModes)],
            'master_translation_prompt' => ['nullable', 'string'],
            'has_sign_avatar' => ['nullable', 'boolean'],
            'avatar_mode' => ['nullable', Rule::in(['3d', 'video', 'human_live'])],
            'avatar_character' => ['nullable', Rule::in(['avatar_femenino', 'avatar_masculino'])],
            'avatar_video_url' => ['nullable', 'url', 'max:500'],
            'zoom_link' => ['nullable', 'url', 'max:500'],
            'meeting_bot_source_lang' => ['nullable', 'string', 'max:10'],
        ]);

        $sesion = Sesion::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $sesion->titulo = $data['titulo'];
        $sesion->fecha_inicio = $data['fecha_inicio'] ?? null;
        $sesion->hora_inicio = $data['hora_inicio'] ?? null;
        $sesion->hora_fin = $data['hora_fin'] ?? null;
        $sesion->glosario_id = $data['glosario_id'] ?? null;
        $sesion->idiomas = $data['idiomas'] ?? [];
        $sesion->translation_settings = $this->buildTranslationSettings($data);
        $sesion->has_sign_avatar = config('spikia.features.sign_avatar') && $request->boolean('has_sign_avatar');
        $sesion->avatar_mode = $data['avatar_mode'] ?? '3d';
        $sesion->avatar_character = $data['avatar_character'] ?? null;
        $sesion->avatar_video_url = $data['avatar_video_url'] ?? null;
        $sesion->zoom_link = $data['zoom_link'] ?? null;
        $sesion->meeting_bot_source_lang = $data['meeting_bot_source_lang'] ?? null;
        $sesion->save();

        return redirect()->route('sesiones.index')->with('success', 'Configuracion actualizada');
    }

    public function destroy($id)
    {
        $sesion = Sesion::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $this->recordSessionUsage($sesion, 'session_deleted', [
            'reason' => 'manual',
            'titulo' => $sesion->titulo,
            'extra_time_minutes' => (int) ($sesion->extra_time_minutes ?? 0),
            'extension_count' => (int) ($sesion->extension_count ?? 0),
        ]);
        $sesion->delete();

        return redirect()->route('sesiones.index');
    }

    public function extendTime(Request $request, int $id)
    {
        $data = $request->validate([
            'extra_hours' => ['required', 'integer', Rule::in([1, 2, 3])],
        ]);

        $sesion = Sesion::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        abort_if($sesion->demo_expires_at, 422, 'Las demos no se pueden extender.');
        abort_unless($sesion->can_extend_now, 422, 'La ventana para extender esta sesion ya no esta disponible.');

        $hours = (int) $data['extra_hours'];
        $end = $sesion->scheduledEndAt();
        $base = ($end && $end->isFuture()) ? $end : now();
        $newEnd = $base->copy()->addHours($hours);

        $sesion->hora_fin = $newEnd->format('H:i');
        $sesion->extra_time_minutes = (int) ($sesion->extra_time_minutes ?? 0) + ($hours * 60);
        $sesion->extension_count = (int) ($sesion->extension_count ?? 0) + 1;
        $sesion->extension_deadline_at = null;
        $sesion->last_extended_at = now();
        $sesion->save();

        $this->recordSessionUsage($sesion, 'time_extended', [
            'extra_hours' => $hours,
            'extra_minutes' => $hours * 60,
            'new_end' => $newEnd->toDateTimeString(),
            'extension_count' => $sesion->extension_count,
        ]);

        return redirect()->route('sesiones.index')->with('success', "Sesion extendida {$hours} hora(s).");
    }

    public function reunion($slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();
        $this->recordSessionUsage($sesion, 'reunion_opened');

        return view('modules.sessions.reunion', compact('sesion'));
    }

    public function master($slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();
        $this->recordSessionUsage($sesion, 'master_opened');

        if (! $sesion->short_code) {
            $sesion->short_code = Sesion::generateUniqueShortCode();
            $sesion->saveQuietly();
        }

        return view('modules.sessions.master', compact('sesion'));
    }

    public function shortCode(string $code)
    {
        $normalized = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $code));

        $sesion = Sesion::where('short_code', $normalized)->first();

        if (! $sesion) {
            return $this->sessionGoneResponse();
        }

        return redirect()->route('sesion.transmision', ['slug' => $sesion->slug]);
    }

    public function transmision($slug)
    {
        $sesion = Sesion::where('slug', $slug)->first();

        if (! $sesion) {
            return $this->sessionGoneResponse();
        }

        $this->recordSessionUsage($sesion, 'transmission_opened');

        return response()
            ->view('modules.sessions.transmision', compact('sesion'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function movil($slug)
    {
        $sesion = Sesion::where('slug', $slug)->first();

        if (! $sesion) {
            return $this->sessionGoneResponse();
        }

        $this->recordSessionUsage($sesion, 'mobile_opened');

        return response()
            ->view('modules.sessions.movil', compact('sesion'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Antes shortCode()/transmision()/movil() usaban firstOrFail(): un QR escaneado
     * despues de que la sesion se autoelimino (ver cleanupExpiredSessions) o un codigo
     * mal tipeado tiraban el 404 generico de Laravel, sin explicar nada al oyente.
     */
    private function sessionGoneResponse()
    {
        return response()
            ->view('modules.sessions.session-gone')
            ->setStatusCode(404);
    }

    public function avatar($slug)
    {
        $sesion = Sesion::where('slug', $slug)->firstOrFail();

        abort_unless(config('spikia.features.sign_avatar') && $sesion->has_sign_avatar, 404);

        $this->recordSessionUsage($sesion, 'avatar_opened');

        return response()
            ->view('modules.sessions.avatar', compact('sesion'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function subtitulos($slug)
    {
        $sesion = Sesion::where('slug', $slug)->firstOrFail();
        $this->recordSessionUsage($sesion, 'subtitles_opened');

        return response()
            ->view('modules.sessions.subtitulos', compact('sesion'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function interprete($slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        abort_unless(config('spikia.features.sign_avatar'), 404);

        return view('modules.sessions.interprete', compact('sesion'));
    }

    /**
     * Token de LiveKit para el interprete (dueño de la sesion): puede publicar su video a la
     * sala. La sala es el slug de la sesion, asi coincide 1:1 con lo que usa el viewer.
     */
    public function liveKitPublisherToken(string $slug, \App\Services\LiveKitTokenService $liveKit)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        abort_unless(config('spikia.features.sign_avatar') && $sesion->avatar_mode === 'human_live', 404);
        abort_unless($liveKit->enabled(), 503, 'LiveKit no esta configurado (LIVEKIT_URL/API_KEY/API_SECRET).');

        return response()->json([
            'url' => config('spikia.livekit.url'),
            'token' => $liveKit->createToken('interprete-' . $sesion->slug, $sesion->slug, canPublish: true),
        ]);
    }

    /**
     * Token de LiveKit para un OYENTE (publico, sin autenticacion): solo puede suscribirse,
     * nunca publicar. Identidad aleatoria por pestaña, no hace falta que sea estable.
     */
    public function liveKitViewerToken(string $slug, \App\Services\LiveKitTokenService $liveKit)
    {
        $sesion = Sesion::where('slug', $slug)->firstOrFail();

        abort_unless(config('spikia.features.sign_avatar') && $sesion->avatar_mode === 'human_live', 404);
        abort_unless($liveKit->enabled(), 503, 'LiveKit no esta configurado (LIVEKIT_URL/API_KEY/API_SECRET).');

        return response()->json([
            'url' => config('spikia.livekit.url'),
            'token' => $liveKit->createToken('oyente-' . \Illuminate\Support\Str::random(10), $sesion->slug, canPublish: false),
        ]);
    }

    public function processAudio(Request $request, string $slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if ($sesion->demo_expired) {
            return $this->demoExpiredResponse();
        }

        $data = $request->validate([
            'audio' => ['required', 'file', 'max:25600'],
            'lang' => ['required', 'string', 'max:10'],
            'lang_base' => ['nullable', 'string', 'max:10'],
            'gender' => ['nullable', 'string', 'max:10'],
            'save_mode' => ['nullable', 'string', 'max:20'],
            'auto_detect_lang' => ['nullable', 'boolean'],
            'hablante' => ['nullable', 'string', 'max:80'],
        ]);

        $audioFile = $request->file('audio');

        if ($errorResponse = $this->validateAudioUpload($audioFile)) {
            return $errorResponse;
        }

        if ($errorResponse = $this->ensureOpenAiConfigured()) {
            return $errorResponse;
        }

        return $this->handleIncomingAudioSegment($sesion, $slug, $audioFile, $data);
    }

    /**
     * Guarda un fragmento de audio SOLO para archivar - no hace STT ni traduccion, no le
     * cuesta nada a OpenAI/ElevenLabs. Existe porque el reconocimiento de voz por defecto
     * corre en el NAVEGADOR (gratis, mas rapido) y el audio real nunca llega al servidor por
     * esa via: master.js graba en paralelo, sin importar que motor este transcribiendo, y
     * sube cada fragmento aca para que "Descargar audio" tenga con que armar el audio
     * completo. Se guarda como una fila de Transcripcion con texto vacio (modo
     * "audio_archivo") para reusar la misma concatenacion por slug+idioma que ya usa
     * TranscripcionController::descargar - las pantallas de Actividad/Transcripciones
     * filtran estas filas sin texto para no ensuciar los listados.
     */
    public function archiveAudioSegment(Request $request, string $slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if (! $sesion->live_started_at) {
            return response()->json(['success' => false, 'message' => 'La sesion no esta en vivo.'], 422);
        }

        $data = $request->validate([
            'audio' => ['required', 'file', 'max:25600'],
            'lang' => ['required', 'string', 'max:10'],
        ]);

        $audioFile = $request->file('audio');

        if ($errorResponse = $this->validateAudioUpload($audioFile)) {
            return $errorResponse;
        }

        $clientExtension = strtolower((string) $audioFile->getClientOriginalExtension());
        $mimeType = strtolower((string) $audioFile->getMimeType());
        $extension = in_array($clientExtension, ['webm', 'ogg', 'mp3', 'wav', 'm4a', 'mp4'], true)
            ? $clientExtension
            : $this->audioExtensionFromMime($mimeType);

        $storagePath = $audioFile->storeAs(
            'tmp/session-audio',
            'archive_' . Str::uuid() . '.' . $extension,
            'local'
        );

        $sourceLang = $this->normalizeSpeechLanguage((string) $data['lang']);
        $audioUrl = $this->persistSessionAudioSegment($storagePath, $slug, 'original', $extension);

        if ($audioUrl) {
            Transcripcion::create([
                'user_id' => $sesion->user_id,
                'sesion_id' => $sesion->id,
                'slug' => $sesion->slug,
                'texto' => '',
                'idioma' => $sourceLang,
                'audio_url' => $audioUrl,
                'modo' => 'audio_archivo',
            ]);
        }

        return response()->json(['success' => (bool) $audioUrl]);
    }

    /**
     * Deteccion de idioma "liviana" (no transcribe para guardar, no traduce, no publica
     * nada) - unicamente le pregunta a Whisper que idioma esta escuchando en este fragmento
     * corto de audio. Pensada para el modo microfono/Web Speech (motor por defecto): ese
     * motor transcribe rapido en el navegador pero NO puede detectar idioma por si solo (no
     * expone audio, solo texto) - este endpoint corre en paralelo, sin bloquear ni afectar
     * la transcripcion en vivo, solo para poder auto-cambiar el idioma seleccionado cuando
     * "Detectar idioma automaticamente" esta prendido. Reusa el mismo audio que ya se manda
     * a archivar (ver sesiones.audio.archive), no pide un permiso ni una grabacion nueva.
     */
    public function detectLanguage(Request $request, string $slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if ($errorResponse = $this->ensureOpenAiConfigured()) {
            return $errorResponse;
        }

        $data = $request->validate([
            'audio' => ['required', 'file', 'max:25600'],
        ]);

        $audioFile = $request->file('audio');

        if ($errorResponse = $this->validateAudioUpload($audioFile)) {
            return $errorResponse;
        }

        $clientExtension = strtolower((string) $audioFile->getClientOriginalExtension());
        $mimeType = strtolower((string) $audioFile->getMimeType());
        $extension = in_array($clientExtension, ['webm', 'ogg', 'mp3', 'wav', 'm4a', 'mp4'], true)
            ? $clientExtension
            : $this->audioExtensionFromMime($mimeType);

        $storagePath = $audioFile->storeAs(
            'tmp/session-audio',
            'langdetect_' . Str::uuid() . '.' . $extension,
            'local'
        );
        $audioPath = Storage::disk('local')->path($storagePath);

        try {
            $settings = $this->resolveTranslationSettings($sesion);
            $sttModel = (string) ($settings['speech_to_text_model'] ?? 'gpt-4o-mini-transcribe');
            $detectionResult = $this->openai->transcribeWithLanguageDetection($audioPath, $sttModel);
            $detectedMasterLang = $this->mapDetectedLanguageToMasterLang($detectionResult['language'] ?? null);
        } catch (\Throwable $e) {
            Log::warning('detectLanguage: fallo detectando idioma con OpenAI.', [
                'slug' => $slug,
                'message' => $e->getMessage(),
            ]);
            $detectedMasterLang = null;
        } finally {
            Storage::disk('local')->delete($storagePath);
        }

        return response()->json([
            'success' => true,
            'detected_lang' => $detectedMasterLang,
        ]);
    }

    /**
     * Valida que el archivo de audio subido tenga una extension/mime soportada. Compartido
     * entre processAudio() (mic del dueño) e ingestBotAudio() (worker del bot de reunion).
     */
    private function validateAudioUpload(\Illuminate\Http\UploadedFile $audioFile): ?\Illuminate\Http\JsonResponse
    {
        $clientExtension = strtolower((string) $audioFile->getClientOriginalExtension());
        $mimeType = strtolower((string) $audioFile->getMimeType());
        $allowedExtensions = ['webm', 'ogg', 'mp3', 'wav', 'm4a', 'mp4'];
        $allowedMimePrefixes = ['audio/', 'video/webm', 'video/mp4', 'application/ogg', 'application/octet-stream'];

        $allowedMime = collect($allowedMimePrefixes)->contains(
            fn (string $prefix) => str_starts_with($mimeType, $prefix)
        );

        if (! in_array($clientExtension, $allowedExtensions, true) && ! $allowedMime) {
            return response()->json([
                'success' => false,
                'message' => 'El audio capturado no tiene un formato compatible. Intenta de nuevo o usa Chrome/Edge actualizado.',
                'mime' => $mimeType,
                'extension' => $clientExtension,
            ], 422);
        }

        return null;
    }

    private function ensureOpenAiConfigured(): ?\Illuminate\Http\JsonResponse
    {
        if ((string) config('services.openai.key', '') === '') {
            return response()->json([
                'success' => false,
                'message' => 'OpenAI no esta configurado para procesar audio en esta sesion.',
            ], 503);
        }

        return null;
    }

    /**
     * Transcribe, traduce y publica un segmento de audio ya validado. Compartido por el
     * flujo de microfono del dueño (processAudio) y el del bot de reunion (ingestBotAudio):
     * a partir de aca no importa de donde vino el audio, solo que ya hay un $sesion resuelto
     * y un $data con lang/lang_base/gender/save_mode.
     */
    private function handleIncomingAudioSegment(Sesion $sesion, string $slug, \Illuminate\Http\UploadedFile $audioFile, array $data): \Illuminate\Http\JsonResponse
    {
        $settings = $this->resolveTranslationSettings($sesion);
        $translationPrompt = $this->buildSessionTranslationPrompt($sesion, $settings);
        $inputLanguage = $this->normalizeSpeechLanguage($data['lang'] ?? 'es');
        $clientExtension = strtolower((string) $audioFile->getClientOriginalExtension());
        $mimeType = strtolower((string) $audioFile->getMimeType());
        $extension = in_array($clientExtension, ['webm', 'ogg', 'mp3', 'wav', 'm4a', 'mp4'], true)
            ? $clientExtension
            : $this->audioExtensionFromMime($mimeType);
        $storagePath = $audioFile->storeAs(
            'tmp/session-audio',
            'master_' . Str::uuid() . '.' . $extension,
            'local'
        );
        $audioPath = Storage::disk('local')->path($storagePath);

        // "Detectar idioma automaticamente" (interruptor opcional, apagado por defecto en
        // Configuracion avanzada): sin esto, siempre se le pasa a Whisper el idioma elegido
        // a mano en el selector, lo que ayuda a transcribir mejor. Prendido, se lo sacamos
        // para que Whisper detecte libremente que idioma se esta hablando (a costa de algo
        // de precision en frases cortas/con acento fuerte) y usamos ese idioma detectado
        // para el resto del pipeline en vez del que estaba seleccionado en pantalla.
        $autoDetectLang = (bool) ($data['auto_detect_lang'] ?? false);
        $detectedMasterLang = null;

        try {
            if ($autoDetectLang) {
                $sttModel = (string) ($settings['speech_to_text_model'] ?? 'gpt-4o-mini-transcribe');
                $detectionResult = $this->openai->transcribeWithLanguageDetection($audioPath, $sttModel);
                $originalTranscript = trim((string) ($detectionResult['text'] ?? ''));
                $detectedMasterLang = $this->mapDetectedLanguageToMasterLang($detectionResult['language'] ?? null);
                if ($detectedMasterLang) {
                    $inputLanguage = $this->normalizeSpeechLanguage($detectedMasterLang);
                }
            } else {
                $originalTranscript = trim($this->openai->transcribe(
                    $audioPath,
                    (string) ($settings['speech_to_text_model'] ?? 'gpt-4o-mini-transcribe'),
                    $inputLanguage
                ));
            }
        } catch (\Throwable $e) {
            Log::error('Error procesando audio de sesion con OpenAI.', [
                'slug' => $slug,
                'message' => $e->getMessage(),
                'model' => $settings['speech_to_text_model'] ?? null,
                'lang' => $inputLanguage,
            ]);

            Storage::disk('local')->delete($storagePath);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo transcribir el audio de la sesion con OpenAI.',
            ], 502);
        }

        $originalTranscript = preg_replace('/\s+/', ' ', $originalTranscript ?? '');
        $originalTranscript = trim((string) $originalTranscript);
        $this->clearInterimState($slug);

        if ($originalTranscript === '' || mb_strlen($originalTranscript) < 2) {
            // Segmento sin voz real (silencio/ruido): no vale la pena guardarlo, se
            // descarta igual que antes.
            Storage::disk('local')->delete($storagePath);

            return response()->json([
                'success' => true,
                'skipped' => true,
                'original_transcript' => '',
                'messages' => [],
            ]);
        }

        $publishedAt = now()->timestamp;
        $availableAt = now()->addSeconds(self::RELAY_DELAY_SECONDS)->timestamp;
        $saveMode = (string) ($data['save_mode'] ?? 'resumen');
        $gender = $this->resolveVoiceGender(
            (string) ($settings['voice_gender_profile'] ?? ($data['gender'] ?? 'female'))
        );
        $sourceLang = $detectedMasterLang ?: (string) ($data['lang'] ?? 'es-ES');
        $sourceBase = $detectedMasterLang !== null
            ? explode('-', $detectedMasterLang)[0]
            : (string) ($data['lang_base'] ?? explode('-', $sourceLang)[0] ?? 'es');
        $sourceVariant = str_contains($sourceLang, '-') ? $sourceLang : '';
        // Antes este fragmento se borraba siempre (ver el finally que ya no existe arriba):
        // "Descargar audio" nunca tenia nada que servir. Ahora se guarda en el disco publico
        // para poder armar el audio completo de la sesion despues.
        $sourceAudioUrl = $this->persistSessionAudioSegment($storagePath, $slug, 'original', $extension);
        $messages = [];

        $originalMessage = $this->storeRelayMessage($slug, [
            'texto' => $originalTranscript,
            'idioma' => $sourceBase,
            'variante' => $sourceVariant,
            'genero' => $gender,
            'tipo' => 'original',
            'published_at' => $publishedAt,
            'available_at' => $availableAt,
            'audio_url' => $sourceAudioUrl,
        ]);

        $this->storeTranscriptionRecord($sesion, $originalTranscript, $sourceLang, $sourceAudioUrl, $saveMode, $data['hablante'] ?? null);
        // Canal principal: se emite de inmediato, sin esperar nada mas. Todo lo de abajo
        // (traducciones, señas) ocurre despues y no puede retrasar esta linea.
        $this->emitLiveMessageEvent($sesion, $originalMessage);
        $messages[] = $originalMessage;

        // Avatar 3D en Lengua de Señas: modulo 100% opcional y desacoplado. La condicion de
        // guardia evalua config() Y el toggle de la sala; si cualquiera de los dos es false,
        // este bloque no hace absolutamente nada (ni una consulta extra, ni un dispatch), por
        // lo que el flujo de traduccion de arriba no pierde ni un milisegundo de latencia.
        if (config('spikia.features.sign_avatar') && $sesion->has_sign_avatar) {
            ProcessSignGlossesJob::dispatch($sesion->id, $originalMessage)->onQueue('low-priority');
        }

        $rawTargets = $this->resolveTargetLanguages($sesion);
        $translateItems = [];
        $targetMeta = [];

        foreach ($rawTargets as $targetLanguage) {
            $targetLanguage = (string) $targetLanguage;
            if ($targetLanguage === '' || strtolower($targetLanguage) === strtolower($sourceLang)) {
                continue;
            }

            $targetBase = strtolower(explode('-', $targetLanguage)[0] ?? $targetLanguage);
            $targetMeta[$targetLanguage] = [
                'language' => $targetLanguage,
                'base' => $targetBase,
                'variant' => str_contains($targetLanguage, '-') ? $targetLanguage : '',
            ];
            $targetLanguageForApi = match ($targetBase) {
                'en' => 'en-US',
                'es' => str_contains($targetLanguage, '-') ? $targetLanguage : 'es-ES',
                'pt' => 'pt-BR',
                'it' => 'it-IT',
                'fr' => 'fr-FR',
                default => $targetLanguage,
            };
            $translateItems[$targetLanguage] = [
                'text' => $originalTranscript,
                'from' => $inputLanguage,
                'to' => $targetLanguageForApi,
            ];
        }

        $translations = $translateItems !== []
            ? $this->openai->translateBatch(
                $translateItems,
                (string) ($settings['translation_model'] ?? 'gpt-4o-mini'),
                $translationPrompt
            )
            : [];

        $audioUrls = [];
        if (($settings['translation_mode'] ?? 'voice_to_voice') === 'voice_to_voice'
            && ($settings['audio_delivery_mode'] ?? 'ultra_fast') !== 'ultra_fast') {
            $synthItems = [];
            foreach ($translations as $key => $translatedText) {
                if (! is_string($translatedText) || $translatedText === '') {
                    continue;
                }
                $synthItems[$key] = [
                    'text' => $translatedText,
                    'gender' => $gender,
                ];
            }

            if ($synthItems !== []) {
                $audioUrls = $this->synthesizeLiveAudioBatch($synthItems, $settings, $gender);
            }
        }

        foreach ($targetMeta as $targetLanguage => $meta) {
            $translatedText = $translations[$targetLanguage] ?? null;
            if (! is_string($translatedText) || trim($translatedText) === '') {
                continue;
            }

            // Nunca publiques el original como si fuera una traduccion. Esto ocurria
            // cuando OpenAI y el fallback externo respondian 429 y el fallback devolvia
            // el texto de entrada para no romper el flujo.
            if ($meta['base'] !== $sourceBase
                && mb_strtolower(trim($translatedText)) === mb_strtolower(trim($originalTranscript))) {
                Log::warning('Se omitio una traduccion identica al original.', [
                    'slug' => $slug,
                    'target' => $targetLanguage,
                    'text_length' => mb_strlen($originalTranscript),
                ]);
                continue;
            }

            $audioUrl = $audioUrls[$targetLanguage] ?? null;

            $translationMessage = $this->storeRelayMessage($slug, [
                'texto' => $translatedText,
                'idioma' => $meta['base'],
                'variante' => $meta['variant'],
                'genero' => $gender,
                'tipo' => 'traduccion',
                'published_at' => $publishedAt,
                'available_at' => $availableAt,
                'audio_url' => $audioUrl,
            ]);

            $transcripcionRow = $this->storeTranscriptionRecord($sesion, $translatedText, $targetLanguage, $audioUrl, $saveMode, $data['hablante'] ?? null);

            // En modo ultra_fast el audio nunca se sintetiza aca arriba (ver el gate de
            // audio_delivery_mode mas arriba en este metodo) para no agregarle latencia al
            // pipeline en vivo: el oyente lo recibe streameado aparte, en tiempo real, via
            // elevenlabsStream(). Este job de segundo plano genera una copia SOLO para poder
            // armar el audio completo de la sesion despues (Descargar audio) - corre en su
            // propia cola, no bloquea ni compite con la traduccion en vivo de nadie.
            if ($audioUrl === null && ($settings['translation_mode'] ?? 'voice_to_voice') === 'voice_to_voice') {
                PersistTranslatedAudioSegmentJob::dispatch(
                    $transcripcionRow->id,
                    $translatedText,
                    $settings,
                    $gender
                )->onQueue('low-priority');
            }

            $this->emitLiveMessageEvent($sesion, $translationMessage);
            $messages[] = $translationMessage;
        }

        return response()->json([
            'success' => true,
            'original_transcript' => $originalTranscript,
            'messages' => $messages,
            'translation_settings' => $settings,
            // Solo viene con valor si "Detectar idioma automaticamente" esta prendido Y
            // Whisper detecto un idioma que mapea a uno de los soportados (master_languages)
            // - el frontend usa esto para mover el selector de idioma solo, sin que el
            // presentador tenga que tocarlo.
            'detected_lang' => $detectedMasterLang,
        ]);
    }

    /**
     * Mapea el idioma detectado por Whisper (nombre en ingles, ej. "spanish", "english") al
     * id de master_languages correspondiente. Whisper no distingue variantes regionales
     * (es-ES vs es-419), asi que para español siempre cae en es-ES por defecto.
     */
    private function mapDetectedLanguageToMasterLang(?string $whisperLanguageName): ?string
    {
        if (! $whisperLanguageName) {
            return null;
        }

        $map = [
            'english' => 'en-US',
            'spanish' => 'es-ES',
            'portuguese' => 'pt-BR',
            'italian' => 'it-IT',
            'french' => 'fr-FR',
        ];

        return $map[strtolower(trim($whisperLanguageName))] ?? null;
    }

    /**
     * Recibe los segmentos de audio que el worker de /meet-bot va subiendo mientras esta
     * dentro de una reunion de Meet/Zoom. No hay usuario autenticado aca (es un proceso Node
     * sin sesion de navegador): la autorizacion es el token por-sesion en bot_ingest_token,
     * comparado con hash_equals ANTES de tocar storage/OpenAI.
     */
    public function ingestBotAudio(Request $request, string $slug)
    {
        abort_unless(config('spikia.features.meeting_bot'), 404);

        $sesion = Sesion::where('slug', $slug)->firstOrFail();

        $token = (string) $request->header('X-Spikia-Bot-Token', '');
        abort_unless($sesion->bot_ingest_token && hash_equals($sesion->bot_ingest_token, $token), 401);

        $request->validate([
            'audio' => ['required', 'file', 'max:25600'],
        ]);

        $audioFile = $request->file('audio');

        if ($errorResponse = $this->validateAudioUpload($audioFile)) {
            return $errorResponse;
        }

        if ($errorResponse = $this->ensureOpenAiConfigured()) {
            return $errorResponse;
        }

        // El primer chunk que llega es la unica confirmacion real de que el worker ya esta
        // dentro de la reunion capturando audio (Laravel no tiene otra forma de saberlo).
        $sesion->meeting_bot_status = 'active';
        $sesion->meeting_bot_last_heartbeat_at = now();
        $sesion->save();

        $sourceLang = $sesion->meeting_bot_source_lang ?: 'es-ES';

        return $this->handleIncomingAudioSegment($sesion, $slug, $audioFile, [
            'lang' => $sourceLang,
            'lang_base' => explode('-', $sourceLang)[0] ?? 'es',
            'save_mode' => 'resumen',
        ]);
    }

    /**
     * Le pide al worker de /meet-bot que entre a la reunion guardada en zoom_link. Idempotente:
     * si ya esta uniendose/activo no rota el token (evita invalidar lo que el worker ya tiene
     * cacheado si el dueño hace doble clic).
     */
    public function startMeetingBot(Request $request, string $slug)
    {
        abort_unless(config('spikia.features.meeting_bot'), 404);

        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        abort_if(empty($sesion->zoom_link), 422, 'Esta sesion no tiene un enlace de reunion configurado.');

        $data = $request->validate([
            'source_lang' => ['nullable', 'string', 'max:10'],
        ]);

        if (! in_array($sesion->meeting_bot_status, ['joining', 'active'], true)) {
            $sesion->bot_ingest_token = Str::random(40);
        }
        $sesion->meeting_bot_source_lang = $data['source_lang'] ?? $sesion->meeting_bot_source_lang ?? 'es-ES';
        $sesion->meeting_bot_status = 'joining';
        $sesion->meeting_bot_last_heartbeat_at = null;
        $sesion->save();

        try {
            $ingestUrl = route('sesiones.bot-audio.ingest', ['slug' => $sesion->slug]);
            $this->meetingBot->join($sesion, $ingestUrl);
        } catch (\Throwable $e) {
            $sesion->meeting_bot_status = 'error';
            $sesion->save();

            return response()->json([
                'success' => false,
                'message' => 'No se pudo contactar al worker del bot de reunion.',
            ], 502);
        }

        return response()->json(['success' => true, 'status' => $sesion->meeting_bot_status]);
    }

    public function stopMeetingBot(string $slug)
    {
        abort_unless(config('spikia.features.meeting_bot'), 404);

        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        try {
            $this->meetingBot->leave($sesion);
        } catch (\Throwable $e) {
            // El worker no respondio, pero igual dejamos la sesion como detenida del lado de
            // Spikia: el usuario pidio parar y no debe quedar atascado en "activo" para siempre.
        }

        $sesion->meeting_bot_status = 'stopped';
        $sesion->bot_ingest_token = null;
        $sesion->save();

        return response()->json(['success' => true, 'status' => $sesion->meeting_bot_status]);
    }

    public function meetingBotStatus(string $slug)
    {
        abort_unless(config('spikia.features.meeting_bot'), 404);

        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        // El worker es la unica fuente real de "fallo al unirse" (Meet no dejo entrar, no
        // encontro el boton, etc.) - la base de datos no se entera de eso por su cuenta. Se
        // consulta en vivo y, si difiere, se refleja en la sesion para que quede consistente
        // la proxima vez que se lea desde otro lado (p. ej. el listado de sesiones).
        if (in_array($sesion->meeting_bot_status, ['joining', 'active'], true)) {
            try {
                $live = $this->meetingBot->status($sesion);
                $liveStatus = $live['status'] ?? null;

                if ($liveStatus && $liveStatus !== $sesion->meeting_bot_status) {
                    $sesion->meeting_bot_status = $liveStatus;
                    $sesion->save();
                }

                return response()->json([
                    'status' => $sesion->meeting_bot_stale ? 'error' : $liveStatus,
                    'error' => $live['error'] ?? null,
                    'last_heartbeat_at' => $sesion->meeting_bot_last_heartbeat_at?->toIso8601String(),
                ]);
            } catch (\Throwable $e) {
                // Worker inalcanzable: no tumbar el endpoint, mostrar lo ultimo que sabe la BD.
            }
        }

        return response()->json([
            'status' => $sesion->meeting_bot_stale ? 'error' : ($sesion->meeting_bot_status ?? 'idle'),
            'last_heartbeat_at' => $sesion->meeting_bot_last_heartbeat_at?->toIso8601String(),
        ]);
    }

    public function deepgramToken(Request $request)
    {
        $apiKey = (string) config('services.deepgram.key', '');
        $projectId = (string) config('services.deepgram.project_id', '');

        if ($apiKey === '' || $projectId === '') {
            return response()->json([
                'success' => false,
                'message' => 'Deepgram no esta configurado en el servidor.',
            ], 503);
        }

        $slug = (string) $request->input('slug', 'master');
        $ttl = (int) $request->input('ttl', 1800);
        $ttl = max(300, min(3600, $ttl));

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(5)->post("https://api.deepgram.com/v1/projects/{$projectId}/keys", [
                'comment' => 'spikia-master-' . substr($slug, 0, 40) . '-' . now()->format('YmdHis'),
                'scopes' => ['usage:write'],
                'time_to_live_in_seconds' => $ttl,
            ]);

            if ($response->successful()) {
                $body = $response->json();
                $key = (string) ($body['key'] ?? '');
                $keyId = (string) ($body['api_key_id'] ?? '');

                if ($key !== '') {
                    return response()->json([
                        'success' => true,
                        'key' => $key,
                        'key_id' => $keyId,
                        'expires_in' => $ttl,
                        'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
                        'mode' => 'scoped',
                    ]);
                }
            }

            Log::info('Deepgram scoped key creation rejected, falling back to master key for demo.', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 300),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Deepgram scoped key request failed, falling back to master key.', [
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'key' => $apiKey,
            'key_id' => null,
            'expires_in' => null,
            'expires_at' => null,
            'mode' => 'master',
        ]);
    }

    public function elevenlabs(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:5000'],
            'slug' => ['nullable', 'string', 'max:255'],
            'lang' => ['nullable', 'string', 'max:10'],
            'variante' => ['nullable', 'string', 'max:10'],
            'gender' => ['nullable', 'string', 'max:10'],
            'voice' => ['nullable', 'string', 'max:50'],
        ]);

        $text = trim((string) ($data['text'] ?? ''));

        if ($text === '') {
            return response()->json([
                'message' => 'El texto para sintetizar no puede estar vacio.',
            ], 422);
        }

        $translationSettings = $this->buildTranslationSettings([]);
        if (! empty($data['slug'])) {
            $sesion = Sesion::where('slug', (string) $data['slug'])->first();

            if ($sesion) {
                $translationSettings = $this->resolveTranslationSettings($sesion);
            }
        }

        if (! empty($data['voice'])) {
            $translationSettings['voice'] = (string) $data['voice'];
        }

        $gender = $this->resolveVoiceGender(
            (string) ($data['gender'] ?? ($translationSettings['voice_gender_profile'] ?? 'female'))
        );

        // Provider elegido en la sesion (ver updateTranslationSettings): 'openai' reusa la
        // MISMA OPENAI_API_KEY que ya usa el resto de Spikia, sin necesitar una cuenta de
        // ElevenLabs separada.
        if (Str::lower((string) ($translationSettings['voice_provider'] ?? 'openai')) === 'openai') {
            $audioBytes = $this->requestOpenAiTtsAudioBinary($text, $translationSettings);

            if ($audioBytes === null || $audioBytes === '') {
                return response()->json([
                    'message' => 'OpenAI TTS no esta configurado o fallo generando el audio.',
                ], 503);
            }

            return response($audioBytes, 200, [
                'Content-Type' => 'audio/mpeg',
                'Content-Disposition' => 'inline; filename="spikia-voice.mp3"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]);
        }

        $audioBytes = $this->requestElevenLabsAudioBinary(
            $text,
            $translationSettings,
            $gender,
            (string) ($data['lang'] ?? ''),
            (string) ($data['variante'] ?? '')
        );

        if ($audioBytes === null || $audioBytes === '') {
            return response()->json([
                'message' => 'ElevenLabs no esta configurado.',
            ], 503);
        }

        return response($audioBytes, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="spikia-voice.mp3"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function elevenlabsStream(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:5000'],
            'slug' => ['nullable', 'string', 'max:255'],
            'lang' => ['nullable', 'string', 'max:10'],
            'variante' => ['nullable', 'string', 'max:10'],
            'gender' => ['nullable', 'string', 'max:10'],
            'voice' => ['nullable', 'string', 'max:50'],
        ]);

        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '') {
            return response('Empty text', 422);
        }

        $translationSettings = $this->buildTranslationSettings([]);
        if (! empty($data['slug'])) {
            $sesion = Sesion::where('slug', (string) $data['slug'])->first();
            if ($sesion) {
                $translationSettings = $this->resolveTranslationSettings($sesion);
            }
        }
        if (! empty($data['voice'])) {
            $translationSettings['voice'] = (string) $data['voice'];
        }

        $gender = $this->resolveVoiceGender(
            (string) ($data['gender'] ?? ($translationSettings['voice_gender_profile'] ?? 'female'))
        );

        if (Str::lower((string) ($translationSettings['voice_provider'] ?? 'openai')) === 'openai') {
            return response()->stream(function () use ($text, $translationSettings) {
                if (! $this->streamOpenAiTtsChunks($text, $translationSettings)) {
                    Log::warning('OpenAI TTS streaming fallo.', ['voice' => $translationSettings['voice'] ?? null]);
                }
            }, 200, [
                'Content-Type' => 'audio/mpeg',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $settings = config('spikia.elevenlabs', []);
        $apiKey = (string) ($settings['api_key'] ?? '');
        $voiceId = $this->resolveElevenLabsVoiceId($translationSettings, $gender);
        $fallbackVoiceId = $this->resolvePresetElevenLabsVoiceId($translationSettings, $gender);

        if (! ((bool) ($settings['enabled'] ?? false)) || $apiKey === '' || $voiceId === '') {
            return response('ElevenLabs no esta configurado.', 503);
        }

        $body = json_encode([
            'text' => $text,
            'model_id' => (string) ($settings['model_id'] ?? 'eleven_turbo_v2'),
            'voice_settings' => $this->buildElevenLabsVoiceSettings($translationSettings, $gender),
        ]);
        $headers = [
            'xi-api-key: ' . $apiKey,
            'Accept: audio/mpeg',
            'Content-Type: application/json',
        ];

        return response()->stream(function () use ($voiceId, $fallbackVoiceId, $body, $headers, $text) {
            $usedFallback = false;
            $ok = $this->streamElevenLabsVoiceChunks($voiceId, $body, $headers, $text);

            // La voz clonada fallo (borrada en ElevenLabs, cuenta sin permiso, etc): en vez de
            // dejar al oyente sin audio, reintentamos UNA vez con la voz preestablecida. El
            // primer intento no manda nada al cliente hasta confirmar que son bytes de audio
            // validos, asi que este reintento no corrompe el stream.
            if (! $ok && $fallbackVoiceId !== '' && $fallbackVoiceId !== $voiceId) {
                $usedFallback = true;
                \Illuminate\Support\Facades\Log::warning('ElevenLabs streaming: voz clonada fallo, reintentando con voz preestablecida.', [
                    'cloned_voice_id' => $voiceId,
                    'fallback_voice_id' => $fallbackVoiceId,
                ]);
                $this->streamElevenLabsVoiceChunks($fallbackVoiceId, $body, $headers, $text);
            }

            if (! $ok && ! $usedFallback) {
                \Illuminate\Support\Facades\Log::warning('ElevenLabs streaming fallo sin fallback disponible.', ['voice_id' => $voiceId]);
            }
        }, 200, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Streamea un TTS de ElevenLabs para un voice_id especifico, chunk por chunk. El primer
     * chunk se inspecciona antes de mandarlo al cliente: si no parece un frame MP3 valido
     * (ElevenLabs devuelve JSON de error con status 4xx en vez de audio), se traga esos bytes
     * en silencio y se reporta fallo, permitiendo reintentar con otra voz sin haber corrompido
     * ya la respuesta que esta viendo el oyente. Devuelve true si transmitio audio real.
     */
    private function streamElevenLabsVoiceChunks(string $voiceId, string $body, array $headers, string $text): bool
    {
        $url = "https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}/stream?optimize_streaming_latency=4&output_format=mp3_22050_32";
        $tStart = microtime(true);
        $tracker = (object) ['firstByteAt' => 0.0, 'totalBytes' => 0, 'sniffed' => false, 'looksValid' => null];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($tStart, $tracker) {
                if (! $tracker->sniffed) {
                    $tracker->sniffed = true;
                    // MP3 real: arranca con el tag "ID3" o con el sync-byte 0xFF de un frame MPEG.
                    // JSON de error de ElevenLabs arranca con "{".
                    $tracker->looksValid = str_starts_with($chunk, 'ID3')
                        || (strlen($chunk) > 0 && ord($chunk[0]) === 0xFF);
                }

                if ($tracker->looksValid !== true) {
                    return strlen($chunk); // tragamos el error sin mandarlo al cliente
                }

                if ($tracker->firstByteAt === 0.0) {
                    $tracker->firstByteAt = (microtime(true) - $tStart) * 1000;
                }
                $tracker->totalBytes += strlen($chunk);
                echo $chunk;
                if (ob_get_level() > 0) { @ob_flush(); }
                @flush();
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);

        \Illuminate\Support\Facades\Log::info('spikia.elevenlabs', [
            'chars' => mb_strlen($text),
            'voice_id' => $voiceId,
            'ttfb_ms' => round($tracker->firstByteAt, 1),
            'total_ms' => round((microtime(true) - $tStart) * 1000, 1),
            'bytes' => $tracker->totalBytes,
            'valid' => $tracker->looksValid,
        ]);

        return $tracker->looksValid === true;
    }

    public function updateTranslationSettings(Request $request, string $slug)
    {
        $config = config('spikia.translation_simultaneous', []);
        $sttValues = collect($config['available_stt_models'] ?? [])->pluck('value')->all();
        $translationValues = collect($config['available_translation_models'] ?? [])->pluck('value')->all();
        $voiceValues = $this->availableVoiceValues($config);
        $audioDeliveryModes = collect($config['available_audio_delivery_modes'] ?? [])->pluck('value')->all();

        $data = $request->validate([
            'translation_mode' => ['nullable', Rule::in(['voice_to_text', 'voice_to_voice'])],
            'speech_to_text_model' => ['nullable', Rule::in($sttValues)],
            'translation_model' => ['nullable', Rule::in($translationValues)],
            'text_to_speech_model' => ['nullable', Rule::in([$config['text_to_speech_model'] ?? 'gpt-4o-mini-tts'])],
            'voice_provider' => ['nullable', Rule::in(['elevenlabs', 'openai'])],
            'voice_gender_profile' => ['nullable', Rule::in(['male', 'female'])],
            'voice' => ['nullable', Rule::in($voiceValues)],
            'audio_delivery_mode' => ['nullable', Rule::in($audioDeliveryModes)],
            'master_translation_prompt' => ['nullable', 'string'],
        ]);

        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $current = is_array($sesion->translation_settings ?? null) ? $sesion->translation_settings : [];
        $incoming = array_filter($data, fn ($value) => $value !== null && $value !== '');
        $sesion->translation_settings = $this->buildTranslationSettings(array_merge($current, $incoming));
        $sesion->save();

        return response()->json([
            'success' => true,
            'translation_settings' => $sesion->translation_settings,
        ]);
    }

    /**
     * Marca el inicio de un segmento "en vivo" del cronometro de la sesion. Idempotente: si
     * ya estaba en vivo (recarga de pagina mientras seguia corriendo), NO pisa live_started_at
     * -- eso perderia el tiempo ya transcurrido de ese segmento. Devuelve el estado completo
     * para que el cliente calcule el cronometro sin depender de su propio reloj.
     */
    public function startLiveTimer(Request $request, string $slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if (! $sesion->live_started_at) {
            $sesion->live_started_at = now();
            // live_first_started_at NUNCA se pisa una vez escrito: a diferencia de
            // live_started_at (se limpia en cada pausa), este queda fijo desde la primera
            // vez que se activo el microfono en esta sesion - lo que "Registro de Actividad"
            // necesita mostrar como "abierto".
            if (! $sesion->live_first_started_at) {
                $sesion->live_first_started_at = $sesion->live_started_at;
            }
            $sesion->save();
        }

        return response()->json([
            'success' => true,
            'live_started_at' => $sesion->live_started_at->toIso8601String(),
            'live_accumulated_seconds' => (int) $sesion->live_accumulated_seconds,
            'live_elapsed_seconds' => $sesion->live_elapsed_seconds,
        ]);
    }

    /**
     * Congela el cronometro: suma lo transcurrido del segmento actual al acumulado y limpia
     * live_started_at. A diferencia del comportamiento viejo (puramente en el navegador), el
     * valor acumulado sobrevive recargas, pausas o que se cierre la pestaña sin avisar.
     */
    public function stopLiveTimer(Request $request, string $slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if ($sesion->live_started_at) {
            $sesion->live_accumulated_seconds = $sesion->live_elapsed_seconds;
            $sesion->live_started_at = null;
            // Se sobreescribe en CADA pausa/detencion (a diferencia de live_first_started_at):
            // asi "Registro de Actividad" siempre puede mostrar el ultimo momento real en que
            // se dejo de hablar, aunque despues se reactive el microfono otra vez.
            $sesion->live_last_stopped_at = now();
            $sesion->save();
        }

        return response()->json([
            'success' => true,
            'live_started_at' => null,
            'live_accumulated_seconds' => (int) $sesion->live_accumulated_seconds,
            'live_elapsed_seconds' => $sesion->live_elapsed_seconds,
        ]);
    }

    /**
     * Clona la voz del orador a partir de una muestra de audio grabada en el panel master.
     * Requiere consentimiento explicito (checkbox) ANTES de aceptar el archivo: sin
     * consent=true, ni siquiera se toca ElevenLabs. Es una llamada UNICA al configurar la
     * sesion, fuera del camino critico de latencia de la traduccion en vivo.
     */
    public function cloneVoice(Request $request, string $slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $data = $request->validate([
            'consent' => ['required', 'accepted'],
            'sample' => ['required', 'file', 'mimes:webm,ogg,mp3,wav,m4a', 'max:25600'],
        ], [
            'consent.accepted' => 'Necesitas confirmar que el orador dio su consentimiento antes de clonar la voz.',
        ]);

        try {
            $voiceId = $this->voiceCloning->clone(
                $data['sample'],
                'Spikia - ' . $sesion->titulo . ' - ' . $sesion->slug
            );
        } catch (\RuntimeException $e) {
            // Mensajes conocidos del servicio (plan sin permisos, rechazo de ElevenLabs):
            // se muestran tal cual, son accionables para quien opera la sesion.
            Log::error('Error clonando voz con ElevenLabs.', ['slug' => $slug, 'message' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        } catch (\Throwable $e) {
            Log::error('Error inesperado clonando voz.', ['slug' => $slug, 'message' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo clonar la voz. Verifica que la muestra tenga audio claro y vuelve a intentar.',
            ], 502);
        }

        $previousVoiceId = $sesion->cloned_voice_id;

        $sesion->cloned_voice_id = $voiceId;
        $sesion->voice_consent_at = now();
        $sesion->save();

        $this->recordSessionUsage($sesion, 'voice_cloned', [
            'voice_id' => $voiceId,
            'consent_at' => $sesion->voice_consent_at->toIso8601String(),
            'ip' => $request->ip(),
        ]);

        // Si habia una clonacion anterior (el orador volvio a grabar), liberamos la voz vieja
        // en ElevenLabs para no acumular voces huerfanas en la cuenta.
        if ($previousVoiceId && $previousVoiceId !== $voiceId) {
            $this->voiceCloning->delete($previousVoiceId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Voz clonada correctamente. La transmision ya usa la voz del orador.',
            'cloned_voice_id' => $voiceId,
            'voice_consent_at' => $sesion->voice_consent_at->toIso8601String(),
        ]);
    }

    /**
     * Vuelve a las voces predefinidas (marin/coral/etc) y borra la voz clonada de ElevenLabs.
     */
    public function removeVoiceClone(Request $request, string $slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $voiceId = $sesion->cloned_voice_id;

        $sesion->cloned_voice_id = null;
        $sesion->voice_consent_at = null;
        $sesion->save();

        if ($voiceId) {
            $this->voiceCloning->delete($voiceId);
            $this->recordSessionUsage($sesion, 'voice_clone_removed', ['voice_id' => $voiceId]);
        }

        return response()->json(['success' => true]);
    }

    public function updateInterim(Request $request, string $slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if ($sesion->demo_expired) {
            $this->clearInterimState($sesion->slug);

            return $this->demoExpiredResponse();
        }

        $data = $request->validate([
            'texto' => ['nullable', 'string', 'max:800'],
            'idioma' => ['nullable', 'string', 'max:10'],
            'variante' => ['nullable', 'string', 'max:10'],
        ]);

        $texto = trim((string) ($data['texto'] ?? ''));
        if ($texto === '') {
            $this->clearInterimState($sesion->slug);

            return response()->json([
                'success' => true,
                'interim' => null,
            ]);
        }

        $payload = [
            'texto' => preg_replace('/\s+/', ' ', $texto),
            'idioma' => (string) ($data['idioma'] ?? 'es'),
            'variante' => (string) ($data['variante'] ?? ''),
            'updated_at' => now()->timestamp,
        ];

        Cache::put(
            $this->interimCacheKey($sesion->slug),
            $payload,
            now()->addSeconds(self::INTERIM_TTL_SECONDS)
        );

        return response()->json([
            'success' => true,
            'interim' => $payload,
        ]);
    }

    public function activarIdioma(Request $request, $id)
    {
        $sesion = Sesion::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $sesion->idioma_activo = $request->idioma;
        $sesion->save();

        return response()->json(['status' => 'success']);
    }

    public function enviarTraduccion(Request $request)
    {
        $sesionId = $request->input('sesion_id');
        $texto = $request->input('texto');
        $idioma = $request->input('idioma');
        $sesion = Sesion::find($sesionId);

        if ($sesion) {
            broadcast(new TraduccionEnviada($sesion->slug, $texto, $idioma))->toOthers();
        }

        return response()->json(['status' => 'Transmitido']);
    }

    public function publicarMensaje(Request $request, string $slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if ($sesion->demo_expired) {
            return $this->demoExpiredResponse();
        }

        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:80'],
            'texto' => ['required', 'string'],
            'idioma' => ['required', 'string', 'max:10'],
            'variante' => ['nullable', 'string', 'max:10'],
            'genero' => ['nullable', 'string', 'max:10'],
            'tipo' => ['nullable', 'string', 'max:20'],
            'audio_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $message = $this->storeRelayMessage($slug, $data);
        $this->emitLiveMessageEvent($sesion, $message);

        return response()->json([
            'success' => true,
            'queued' => true,
            'available_in_seconds' => self::RELAY_DELAY_SECONDS,
            'message' => $message,
        ]);
    }

    public function feed(string $slug)
    {
        $sesion = Sesion::where('slug', $slug)->first();
        if ($sesion?->demo_expired) {
            // Endpoint publico consumido por el oyente (listener.js/subtitulos.js), no por
            // el host: "activa otra sesion" no aplica aca, el oyente no tiene panel propio.
            return response()->json([
                'success' => true,
                'demo_expired' => true,
                'message' => 'Se acabo el tiempo de esta demo. Pedile al organizador un codigo nuevo.',
                'messages' => [],
                'interim' => null,
                'pending_count' => 0,
                'next_available_in_seconds' => null,
            ]);
        }

        $messages = Cache::get($this->relayCacheKey($slug), []);
        $now = now()->timestamp;
        $interim = Cache::get($this->interimCacheKey($slug));

        $available = array_values(array_filter($messages, function (array $message) use ($now) {
            return (int) ($message['available_at'] ?? 0) <= $now;
        }));

        $pending = array_values(array_filter($messages, function (array $message) use ($now) {
            return (int) ($message['available_at'] ?? 0) > $now;
        }));

        $nextAvailableIn = null;

        if (! empty($pending)) {
            $nextAvailableIn = max(0, (int) ($pending[0]['available_at'] ?? $now) - $now);
        }

        return response()->json([
            'success' => true,
            'messages' => $available,
            'interim' => is_array($interim) && ((int) ($interim['updated_at'] ?? 0) >= ($now - self::INTERIM_TTL_SECONDS))
                ? $interim
                : null,
            'pending_count' => count($pending),
            'next_available_in_seconds' => $nextAvailableIn,
        ]);
    }

    private function relayCacheKey(string $slug): string
    {
        return 'spikia:relay:' . Str::slug($slug);
    }

    private function audioExtensionFromMime(string $mimeType): string
    {
        return match (true) {
            str_contains($mimeType, 'ogg') => 'ogg',
            str_contains($mimeType, 'mp4'), str_contains($mimeType, 'm4a') => 'm4a',
            str_contains($mimeType, 'mpeg'), str_contains($mimeType, 'mp3') => 'mp3',
            str_contains($mimeType, 'wav') => 'wav',
            default => 'webm',
        };
    }

    private function interimCacheKey(string $slug): string
    {
        return 'spikia:interim:' . Str::slug($slug);
    }

    private function clearInterimState(string $slug): void
    {
        Cache::forget($this->interimCacheKey($slug));
    }

    private function cleanupExpiredSessions(?int $userId): void
    {
        if (! $userId) {
            return;
        }

        Sesion::where('user_id', $userId)
            ->where(function ($query) {
                $query->whereNotNull('demo_expires_at')
                    ->orWhereNotNull('hora_fin')
                    ->orWhereNotNull('extension_deadline_at');
            })
            ->select([
                'id',
                'user_id',
                'titulo',
                'fecha_inicio',
                'hora_inicio',
                'hora_fin',
                'slug',
                'demo_expires_at',
                'extra_time_minutes',
                'extension_count',
                'extension_deadline_at',
                'last_extended_at',
            ])
            ->chunkById(50, function ($sesiones) {
                $sesiones->each(function (Sesion $sesion) {
            $end = $sesion->scheduledEndAt();
            if (! $sesion->demo_expires_at && $end && now()->greaterThanOrEqualTo($end) && ! $sesion->extension_deadline_at) {
                // 10 minutos de margen real para que el dueño note el aviso y decida extender
                // antes del borrado automatico. Antes eran 20 SEGUNDOS: cleanupExpiredSessions()
                // corre en cada carga de /sesiones, asi que con esa ventana casi nadie llegaba
                // a tiempo y la sesion desaparecia sin que el usuario entendiera por que.
                $sesion->extension_deadline_at = now()->addMinutes(10);
                $sesion->save();
                return;
            }

            if (! $sesion->session_expired_for_deletion) {
                return;
            }

            $this->recordSessionUsage($sesion, $sesion->demo_expires_at ? 'demo_auto_deleted' : 'session_auto_deleted', [
                'reason' => $sesion->demo_expires_at ? 'demo_expired' : 'scheduled_time_expired',
                'titulo' => $sesion->titulo,
                'hora_fin' => $sesion->hora_fin,
                'demo_expires_at' => $sesion->demo_expires_at?->toIso8601String(),
                'extra_time_minutes' => (int) ($sesion->extra_time_minutes ?? 0),
                'extension_count' => (int) ($sesion->extension_count ?? 0),
            ]);

            $sesion->delete();
                });
            });
    }

    private function recordSessionUsage(Sesion $sesion, string $action, array $metadata = []): void
    {
        try {
            SessionUsageEvent::create([
                'user_id' => $sesion->user_id,
                'sesion_id' => $sesion->id,
                'slug' => $sesion->slug,
                'action' => $action,
                'metadata' => $metadata !== [] ? $metadata : null,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable) {
            //
        }
    }

    private function demoExpiredResponse()
    {
        return response()->json([
            'success' => false,
            'demo_expired' => true,
            'message' => 'Se acabo el tiempo del demo. Activa otra sesion para continuar.',
        ], 403);
    }

    private function resolveTranslationSettings(Sesion $sesion): array
    {
        $settings = array_merge(
            $this->buildTranslationSettings([]),
            is_array($sesion->translation_settings ?? null) ? $sesion->translation_settings : []
        );

        // cloned_voice_id vive en su propia columna (no en el JSON de settings) porque es un
        // hecho auditable con su propio timestamp de consentimiento, no una preferencia mas.
        // Se mezcla aqui para que TODO el pipeline de TTS (stream, batch, elevenlabs()) lo
        // reciba sin tener que tocar cada punto de llamada por separado.
        $settings['cloned_voice_id'] = (string) ($sesion->cloned_voice_id ?? '');

        return $settings;
    }

    private function resolveTargetLanguages(Sesion $sesion)
    {
        $sessionLanguages = collect(is_array($sesion->idiomas ?? null) ? $sesion->idiomas : [])
            ->filter(fn ($lang) => filled($lang))
            ->map(fn ($lang) => (string) $lang)
            ->unique()
            ->values();

        if ($sessionLanguages->isNotEmpty()) {
            return $sessionLanguages;
        }

        $listenerLanguages = collect(config('spikia.listener_languages', []))
            ->pluck('id')
            ->filter(fn ($lang) => filled($lang))
            ->map(fn ($lang) => (string) $lang)
            ->unique()
            ->values();

        if ($listenerLanguages->isNotEmpty()) {
            return $listenerLanguages;
        }

        return collect(config('spikia.default_targets', ['en', 'pt', 'it', 'fr']))
            ->filter(fn ($lang) => filled($lang))
            ->map(fn ($lang) => (string) $lang)
            ->unique()
            ->values();
    }

    private function normalizeSpeechLanguage(string $language): string
    {
        $base = strtolower(explode('-', $language)[0] ?? $language);

        return match ($base) {
            'es', 'en', 'pt', 'it', 'fr', 'de' => $base,
            default => 'es',
        };
    }

    private function buildSessionTranslationPrompt(Sesion $sesion, array $settings): string
    {
        $sesion->loadMissing('glosario');

        $basePrompt = trim((string) ($settings['master_translation_prompt'] ?? ''));
        $glossaryPrompt = $this->buildGlossaryPrompt($sesion->glosario);

        return trim($basePrompt . "\n\n" . $glossaryPrompt);
    }

    private function buildGlossaryPrompt(?Glosario $glosario): string
    {
        if (! $glosario) {
            return '';
        }

        $entries = $this->parseGlossaryEntries((string) ($glosario->terminos ?? ''));
        if ($entries === []) {
            return sprintf(
                'Glosario activo: %s. Si aparece terminologia especializada, manten consistencia terminologica y no simplifiques terminos tecnicos.',
                trim((string) $glosario->titulo)
            );
        }

        $rules = array_map(function (array $entry) {
            if (($entry['target'] ?? '') !== '') {
                return sprintf('- "%s" => "%s"', $entry['source'], $entry['target']);
            }

            return sprintf('- preservar termino: "%s"', $entry['source']);
        }, $entries);

        return trim(sprintf(
            "Glosario activo: %s.\nAplica estas reglas terminologicas con prioridad alta. No reemplaces estos terminos por sinonimos ni simplificaciones.\n%s",
            trim((string) $glosario->titulo),
            implode("\n", $rules)
        ));
    }

    private function parseGlossaryEntries(string $raw): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($raw));
        if ($normalized === '') {
            return [];
        }

        $entries = [];
        $lines = preg_split('/\n+/', $normalized) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s*(=>|=|:)\s*/', $line, 2) ?: [];
            if (count($parts) === 2) {
                $source = trim((string) ($parts[0] ?? ''));
                $target = trim((string) ($parts[1] ?? ''));
                if ($source !== '') {
                    $entries[] = ['source' => $source, 'target' => $target];
                }
                continue;
            }

            $tokens = array_filter(array_map('trim', preg_split('/,/', $line) ?: []));
            foreach ($tokens as $token) {
                if ($token !== '') {
                    $entries[] = ['source' => $token, 'target' => ''];
                }
            }
        }

        return array_slice($entries, 0, 120);
    }

    private function storeRelayMessage(string $slug, array $payload): array
    {
        $cacheKey = $this->relayCacheKey($slug);
        $messages = Cache::get($cacheKey, []);
        $message = [
            'id' => ! empty($payload['id']) ? (string) $payload['id'] : (string) Str::uuid(),
            'texto' => (string) ($payload['texto'] ?? ''),
            'idioma' => (string) ($payload['idioma'] ?? 'es'),
            'variante' => $payload['variante'] ?? null,
            'genero' => $payload['genero'] ?? null,
            'tipo' => $payload['tipo'] ?? 'texto',
            'audio_url' => $payload['audio_url'] ?? null,
            'published_at' => (int) ($payload['published_at'] ?? now()->timestamp),
            'available_at' => (int) ($payload['available_at'] ?? now()->addSeconds(self::RELAY_DELAY_SECONDS)->timestamp),
            'revision' => 1,
        ];

        $replaceIndex = $this->findReplaceableRelayMessageIndex($messages, $message);
        if ($replaceIndex !== null) {
            $existing = $messages[$replaceIndex];
            $message['id'] = (string) ($existing['id'] ?? $message['id']);
            $message['published_at'] = (int) ($existing['published_at'] ?? $message['published_at']);
            $message['available_at'] = min(
                (int) ($existing['available_at'] ?? $message['available_at']),
                (int) $message['available_at']
            );
            $message['revision'] = (int) ($existing['revision'] ?? 1) + 1;
            $messages[$replaceIndex] = $message;
        } else {
            $messages[] = $message;
        }

        $messages = array_slice($messages, -200);

        Cache::put($cacheKey, $messages, now()->addHours(12));

        return $message;
    }

    /**
     * TranscripcionCreada es ShouldBroadcastNow: con Pusher activo eso es una llamada HTTPS
     * bloqueante por mensaje. Se difiere con app()->terminating() para que la respuesta al
     * master (o al feed) salga sin esperar al broadcast — los oyentes lo reciben unos
     * milisegundos despues, sin necesitar un worker de colas aparte.
     */
    private function emitLiveMessageEvent(Sesion $sesion, array $message): void
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
                Log::error('No se pudo emitir la transcripcion en vivo: ' . $e->getMessage());
            }
        });
    }

    /**
     * Mueve un fragmento de audio del disco local (temporal) al disco publico, para que
     * quede disponible despues al armar el audio completo de la sesion (ver
     * TranscripcionController::descargar). Se usa tanto para la voz original del
     * presentador como para el audio ya traducido.
     */
    private function persistSessionAudioSegment(string $localStoragePath, string $slug, string $folder, string $extension): ?string
    {
        try {
            if (! Storage::disk('local')->exists($localStoragePath)) {
                return null;
            }

            $contents = Storage::disk('local')->get($localStoragePath);
            $publicPath = 'media/sesiones/' . $slug . '/' . $folder . '/' . Str::uuid() . '.' . $extension;
            Storage::disk('public')->put($publicPath, $contents);
            Storage::disk('local')->delete($localStoragePath);

            return Storage::disk('public')->url($publicPath);
        } catch (\Throwable $e) {
            Log::warning('No se pudo guardar el fragmento de audio de la sesion.', [
                'slug' => $slug,
                'folder' => $folder,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function storeTranscriptionRecord(
        Sesion $sesion,
        string $texto,
        string $idioma,
        ?string $audioUrl,
        string $modo,
        ?string $hablante = null
    ): Transcripcion {
        $candidate = Transcripcion::query()
            ->where('sesion_id', $sesion->id)
            ->where('idioma', $idioma)
            ->where('modo', $modo)
            ->where('updated_at', '>=', now()->subSeconds(self::CORRECTION_WINDOW_SECONDS))
            ->latest('updated_at')
            ->first();

        if ($candidate && $this->shouldReplaceRecentText((string) $candidate->texto, $texto)) {
            $candidate->update([
                'texto' => $texto,
                'audio_url' => $audioUrl,
            ]);

            $this->recordSessionUsage($sesion, 'transcription_updated', [
                'transcripcion_id' => $candidate->id,
                'idioma' => $idioma,
                'modo' => $modo,
                'texto' => Str::limit($texto, 500),
                'audio_url' => $audioUrl,
            ]);

            return $candidate;
        }

        $transcripcion = Transcripcion::create([
            'user_id' => $sesion->user_id,
            'sesion_id' => $sesion->id,
            'slug' => $sesion->slug,
            'texto' => $texto,
            'idioma' => $idioma,
            'hablante' => $hablante,
            'audio_url' => $audioUrl,
            'modo' => $modo,
        ]);

        $this->recordSessionUsage($sesion, 'transcription_saved', [
            'transcripcion_id' => $transcripcion->id,
            'idioma' => $idioma,
            'modo' => $modo,
            'texto' => Str::limit($texto, 500),
            'audio_url' => $audioUrl,
        ]);

        return $transcripcion;
    }

    private function findReplaceableRelayMessageIndex(array $messages, array $current): ?int
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $candidate = $messages[$index] ?? null;
            if (! is_array($candidate)) {
                continue;
            }

            if (($candidate['tipo'] ?? 'texto') !== ($current['tipo'] ?? 'texto')) {
                continue;
            }

            if (($candidate['idioma'] ?? 'es') !== ($current['idioma'] ?? 'es')) {
                continue;
            }

            if (($candidate['variante'] ?? null) !== ($current['variante'] ?? null)) {
                continue;
            }

            $candidatePublishedAt = (int) ($candidate['published_at'] ?? 0);
            $currentPublishedAt = (int) ($current['published_at'] ?? 0);
            if ($candidatePublishedAt > 0 && $currentPublishedAt > 0 && ($currentPublishedAt - $candidatePublishedAt) > self::CORRECTION_WINDOW_SECONDS) {
                break;
            }

            if ($this->shouldReplaceRecentText((string) ($candidate['texto'] ?? ''), (string) ($current['texto'] ?? ''))) {
                return $index;
            }
        }

        return null;
    }

    private function shouldReplaceRecentText(string $previous, string $current): bool
    {
        $previous = $this->normalizeComparableText($previous);
        $current = $this->normalizeComparableText($current);

        if ($previous === '' || $current === '' || $previous === $current) {
            return false;
        }

        if (str_starts_with($current, $previous) || str_starts_with($previous, $current)) {
            return true;
        }

        similar_text($previous, $current, $percent);
        if ($percent >= 72.0) {
            return true;
        }

        $previousWords = array_values(array_filter(explode(' ', $previous)));
        $currentWords = array_values(array_filter(explode(' ', $current)));
        if ($previousWords === [] || $currentWords === []) {
            return false;
        }

        $intersection = array_intersect($previousWords, $currentWords);
        $overlap = count($intersection) / max(1, min(count($previousWords), count($currentWords)));

        return $overlap >= 0.8;
    }

    private function normalizeComparableText(string $text): string
    {
        $normalized = Str::of($text)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s]/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->toString();

        return $normalized;
    }

    private function publicStorageUrl(string $path): string
    {
        // Ruta RELATIVA al host: el oyente la resuelve contra el origen por el que entro
        // (localhost, LAN o el tunnel ngrok). Antes se fijaba al host de ngrok, asi que el
        // audio no cargaba si el oyente abria por 127.0.0.1/LAN o si el tunel estaba caido.
        return '/storage/' . ltrim($path, '/');
    }

    private function availableVoiceValues(array $config): array
    {
        $voiceProfiles = collect($config['voice_profiles'] ?? [])
            ->pluck('value')
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values()
            ->all();

        return $voiceProfiles !== []
            ? $voiceProfiles
            : array_values(array_filter($config['available_voices'] ?? []));
    }

    private function resolveVoiceGender(string $gender): string
    {
        return Str::lower($gender) === 'male' ? 'male' : 'female';
    }

    private function resolveVoiceProvider(array $settings): string
    {
        // Antes esto devolvia 'elevenlabs' SIEMPRE (las dos ramas del ternario eran
        // identicas - codigo muerto de un refactor anterior) - por eso synthesizeLiveAudio()
        // nunca podia sintetizar via OpenAI aunque la sesion lo tuviera configurado.
        return Str::lower((string) ($settings['voice_provider'] ?? 'openai')) === 'openai'
            ? 'openai'
            : 'elevenlabs';
    }

    private function resolveVoiceProfiles(): array
    {
        return array_values(array_filter(
            config('spikia.translation_simultaneous.voice_profiles', []),
            fn ($profile) => filled($profile['value'] ?? null)
        ));
    }

    private function normalizeVoiceSelection(?string $voice, string $gender): string
    {
        $gender = $this->resolveVoiceGender($gender);
        $profiles = collect($this->resolveVoiceProfiles());
        $normalizedVoice = trim((string) $voice);

        if ($normalizedVoice !== '') {
            $selected = $profiles->first(fn ($profile) => ($profile['value'] ?? '') === $normalizedVoice && ($profile['gender'] ?? '') === $gender);
            if ($selected) {
                return $normalizedVoice;
            }
        }

        $fallback = $profiles->first(fn ($profile) => ($profile['gender'] ?? '') === $gender);
        if ($fallback) {
            return (string) ($fallback['value'] ?? $normalizedVoice);
        }

        return $normalizedVoice !== '' ? $normalizedVoice : (string) config('spikia.translation_simultaneous.voice', 'marin');
    }

    private function resolveVoiceProfile(string $voice, string $gender): ?array
    {
        $gender = $this->resolveVoiceGender($gender);
        $profiles = collect($this->resolveVoiceProfiles());

        $selected = $profiles->first(fn ($profile) => ($profile['value'] ?? '') === $voice && ($profile['gender'] ?? '') === $gender);
        if ($selected) {
            return $selected;
        }

        return $profiles->first(fn ($profile) => ($profile['gender'] ?? '') === $gender);
    }

    private function resolveElevenLabsVoiceId(array $translationSettings, string $gender): string
    {
        // Si la sesion tiene una voz clonada del orador, manda sobre cualquier perfil
        // predefinido (marin/coral/etc) sin importar el toggle de genero: la voz clonada
        // YA es la del orador real.
        $clonedVoiceId = trim((string) ($translationSettings['cloned_voice_id'] ?? ''));
        if ($clonedVoiceId !== '') {
            return $clonedVoiceId;
        }

        return $this->resolvePresetElevenLabsVoiceId($translationSettings, $gender);
    }

    /**
     * Resolucion de voz IGNORANDO cloned_voice_id: es el destino de fallback cuando la voz
     * clonada falla (borrada en ElevenLabs, cuenta bajada de plan, etc). Sin esto, una sesion
     * con clonacion activa se queda muda por completo si ElevenLabs rechaza esa voz.
     */
    private function resolvePresetElevenLabsVoiceId(array $translationSettings, string $gender): string
    {
        $voice = $this->normalizeVoiceSelection((string) ($translationSettings['voice'] ?? ''), $gender);
        $profile = $this->resolveVoiceProfile($voice, $gender);
        $elevenLabs = config('spikia.elevenlabs', []);

        if (! empty($profile['voice_id'])) {
            return (string) $profile['voice_id'];
        }

        if ($this->resolveVoiceGender($gender) === 'male' && ! empty($elevenLabs['male_voice_id'])) {
            return (string) $elevenLabs['male_voice_id'];
        }

        if ($this->resolveVoiceGender($gender) === 'female' && ! empty($elevenLabs['female_voice_id'])) {
            return (string) $elevenLabs['female_voice_id'];
        }

        return (string) ($elevenLabs['voice_id'] ?? '');
    }

    private function buildElevenLabsVoiceSettings(array $translationSettings, string $gender): array
    {
        $config = config('spikia.elevenlabs', []);
        $voice = $this->normalizeVoiceSelection((string) ($translationSettings['voice'] ?? ''), $gender);

        $presets = [
            'marin' => ['stability' => 0.42, 'similarity_boost' => 0.78, 'style' => 0.12],
            'coral' => ['stability' => 0.38, 'similarity_boost' => 0.82, 'style' => 0.22],
            'shimmer' => ['stability' => 0.35, 'similarity_boost' => 0.85, 'style' => 0.30],
            'cedar' => ['stability' => 0.48, 'similarity_boost' => 0.74, 'style' => 0.08],
            'alloy' => ['stability' => 0.44, 'similarity_boost' => 0.79, 'style' => 0.18],
            'sage' => ['stability' => 0.50, 'similarity_boost' => 0.72, 'style' => 0.04],
        ];
        $preset = $presets[$voice] ?? [];

        return [
            'stability' => (float) ($preset['stability'] ?? $config['stability'] ?? 0.45),
            'similarity_boost' => (float) ($preset['similarity_boost'] ?? $config['similarity_boost'] ?? 0.75),
            'style' => (float) ($preset['style'] ?? $config['style'] ?? 0),
            'use_speaker_boost' => (bool) ($config['use_speaker_boost'] ?? true),
        ];
    }

    /**
     * TTS via OpenAI (/v1/audio/speech) - alternativa a ElevenLabs usando la MISMA
     * OPENAI_API_KEY que ya usa el resto de Spikia para transcribir/traducir, sin
     * necesitar una cuenta/clave separada. El campo "voice" de translation_settings ya
     * usa nombres de voz de OpenAI (marin, cedar, alloy, coral, shimmer, sage - ver
     * config('spikia.translation_simultaneous.available_voices')), asi que no hace
     * falta ningun mapeo de nombre de voz.
     */
    private function requestOpenAiTtsAudioBinary(string $text, array $translationSettings): ?string
    {
        $apiKey = (string) config('services.openai.key', '');
        if ($text === '' || $apiKey === '') {
            return null;
        }

        $voice = (string) ($translationSettings['voice'] ?? 'marin');
        $model = (string) config('spikia.translation_simultaneous.text_to_speech_model', 'gpt-4o-mini-tts');

        $ch = curl_init('https://api.openai.com/v1/audio/speech');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'voice' => $voice,
                'input' => $text,
                'response_format' => 'mp3',
            ]),
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || $response === false || $response === '') {
            Log::warning('OpenAI TTS: fallo generando audio.', ['status' => $status, 'voice' => $voice]);

            return null;
        }

        return $response;
    }

    /**
     * Version streaming (mismo patron que streamElevenLabsVoiceChunks): manda los bytes
     * al oyente a medida que OpenAI los va generando, sin esperar la respuesta completa.
     */
    private function streamOpenAiTtsChunks(string $text, array $translationSettings): bool
    {
        $apiKey = (string) config('services.openai.key', '');
        if ($text === '' || $apiKey === '') {
            return false;
        }

        $voice = (string) ($translationSettings['voice'] ?? 'marin');
        $model = (string) config('spikia.translation_simultaneous.text_to_speech_model', 'gpt-4o-mini-tts');
        $tStart = microtime(true);
        $tracker = (object) ['firstByteAt' => 0.0, 'totalBytes' => 0];

        $ch = curl_init('https://api.openai.com/v1/audio/speech');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'voice' => $voice,
                'input' => $text,
                'response_format' => 'mp3',
            ]),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($tStart, $tracker) {
                // Header ya llego antes que el cuerpo (orden normal HTTP) - a diferencia
                // de ElevenLabs, OpenAI SI devuelve un status HTTP real de error en vez
                // de disfrazarlo de 200, asi que alcanza con mirar el status.
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($status !== 200) {
                    return strlen($chunk);
                }
                if ($tracker->firstByteAt === 0.0) {
                    $tracker->firstByteAt = (microtime(true) - $tStart) * 1000;
                }
                $tracker->totalBytes += strlen($chunk);
                echo $chunk;
                if (ob_get_level() > 0) { @ob_flush(); }
                @flush();

                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $finalStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        Log::info('spikia.openai_tts', [
            'chars' => mb_strlen($text),
            'voice' => $voice,
            'ttfb_ms' => round($tracker->firstByteAt, 1),
            'total_ms' => round((microtime(true) - $tStart) * 1000, 1),
            'bytes' => $tracker->totalBytes,
            'status' => $finalStatus,
        ]);

        return $finalStatus === 200 && $tracker->totalBytes > 0;
    }

    private function requestElevenLabsAudioBinary(
        string $text,
        array $translationSettings,
        string $gender,
        string $lang = '',
        string $variant = ''
    ): ?string {
        $settings = config('spikia.elevenlabs', []);
        $apiKey = (string) ($settings['api_key'] ?? '');
        $voiceId = $this->resolveElevenLabsVoiceId($translationSettings, $gender);

        if ($text === '' || ! ((bool) ($settings['enabled'] ?? false)) || $apiKey === '' || $voiceId === '') {
            return null;
        }

        $audio = $this->requestElevenLabsAudioForVoice($text, $voiceId, $translationSettings, $gender, $settings, $apiKey, $lang, $variant);
        if ($audio !== null) {
            return $audio;
        }

        // Si la voz que fallo era una clonada, reintentar UNA vez con la voz preestablecida
        // para que la sesion no se quede muda. Si ya era una voz preestablecida (no clonada),
        // no hay a que caer -- ya fue el intento final.
        $fallbackVoiceId = $this->resolvePresetElevenLabsVoiceId($translationSettings, $gender);
        if ($fallbackVoiceId === '' || $fallbackVoiceId === $voiceId) {
            return null;
        }

        Log::warning('ElevenLabs: voz clonada fallo, reintentando con voz preestablecida.', [
            'cloned_voice_id' => $voiceId,
            'fallback_voice_id' => $fallbackVoiceId,
            'lang' => $lang !== '' ? $lang : null,
        ]);

        return $this->requestElevenLabsAudioForVoice($text, $fallbackVoiceId, $translationSettings, $gender, $settings, $apiKey, $lang, $variant);
    }

    private function requestElevenLabsAudioForVoice(
        string $text,
        string $voiceId,
        array $translationSettings,
        string $gender,
        array $settings,
        string $apiKey,
        string $lang = '',
        string $variant = ''
    ): ?string {
        $response = Http::withHeaders([
            'xi-api-key' => $apiKey,
            'Accept' => 'audio/mpeg',
            'Content-Type' => 'application/json',
        ])->timeout(12)->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}?optimize_streaming_latency=4&output_format=mp3_22050_32", [
            'text' => $text,
            'model_id' => (string) ($settings['model_id'] ?? 'eleven_turbo_v2'),
            'voice_settings' => $this->buildElevenLabsVoiceSettings($translationSettings, $gender),
        ]);

        if (! $response->successful()) {
            Log::warning('ElevenLabs request failed.', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 800),
                'voice_id' => $voiceId,
                'gender' => $gender,
                'lang' => $lang !== '' ? $lang : null,
                'variante' => $variant !== '' ? $variant : null,
                'voice' => $translationSettings['voice'] ?? null,
            ]);

            return null;
        }

        return (string) $response->body();
    }

    private function synthesizeLiveAudio(
        string $text,
        array $translationSettings,
        string $gender,
        string $lang = '',
        string $variant = ''
    ): ?string {
        $audioBytes = $this->resolveVoiceProvider($translationSettings) === 'openai'
            ? $this->requestOpenAiTtsAudioBinary($text, $translationSettings)
            : $this->requestElevenLabsAudioBinary($text, $translationSettings, $gender, $lang, $variant);

        if ($audioBytes === null || $audioBytes === '') {
            return null;
        }

        $filename = 'traducciones/audio_' . uniqid('', true) . '.mp3';
        Storage::disk('public')->put($filename, $audioBytes);

        return $this->publicStorageUrl($filename);
    }

    /**
     * Publico a proposito: PersistTranslatedAudioSegmentJob (cola de segundo plano) tambien
     * lo llama para archivar audio en modo ultra_fast sin bloquear el pipeline en vivo.
     */
    public function synthesizeLiveAudioBatch(array $items, array $translationSettings, string $gender): array
    {
        if ($items === []) {
            return [];
        }

        if ($this->resolveVoiceProvider($translationSettings) === 'openai') {
            return $this->synthesizeLiveAudioBatchOpenAi($items, $translationSettings);
        }

        $config = config('spikia.elevenlabs', []);
        $apiKey = (string) ($config['api_key'] ?? '');
        $voiceId = $this->resolveElevenLabsVoiceId($translationSettings, $gender);

        if (! ((bool) ($config['enabled'] ?? false)) || $apiKey === '' || $voiceId === '') {
            return [];
        }

        $headers = [
            'xi-api-key' => $apiKey,
            'Accept' => 'audio/mpeg',
            'Content-Type' => 'application/json',
        ];
        $voiceSettings = $this->buildElevenLabsVoiceSettings($translationSettings, $gender);

        $audioUrls = $this->synthesizeLiveAudioBatchForVoice($items, $voiceId, $headers, $voiceSettings, $config);

        // Si TODO el lote fallo y la voz usada era una clonada, reintentar el lote completo
        // con la voz preestablecida en vez de dejar la sesion muda para todos los idiomas.
        $fallbackVoiceId = $this->resolvePresetElevenLabsVoiceId($translationSettings, $gender);
        $allFailed = $audioUrls !== [] && ! in_array(false, array_map(fn ($url) => $url === null, $audioUrls), true);

        if ($allFailed && $fallbackVoiceId !== '' && $fallbackVoiceId !== $voiceId) {
            Log::warning('ElevenLabs: lote con voz clonada fallo completo, reintentando con voz preestablecida.', [
                'cloned_voice_id' => $voiceId,
                'fallback_voice_id' => $fallbackVoiceId,
                'items' => count($items),
            ]);

            $audioUrls = $this->synthesizeLiveAudioBatchForVoice($items, $fallbackVoiceId, $headers, $voiceSettings, $config);
        }

        return $audioUrls;
    }

    private function synthesizeLiveAudioBatchForVoice(array $items, string $voiceId, array $headers, array $voiceSettings, array $config): array
    {
        $endpoint = "https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}?optimize_streaming_latency=4&output_format=mp3_22050_32";

        $responses = Http::pool(function (Pool $pool) use ($items, $headers, $voiceSettings, $config, $endpoint) {
            $requests = [];
            foreach ($items as $key => $item) {
                $requests[$key] = $pool
                    ->as($key)
                    ->withHeaders($headers)
                    ->timeout(12)
                    ->post($endpoint, [
                        'text' => (string) ($item['text'] ?? ''),
                        'model_id' => (string) ($config['model_id'] ?? 'eleven_turbo_v2'),
                        'voice_settings' => $voiceSettings,
                    ]);
            }

            return $requests;
        });

        $audioUrls = [];
        foreach ($responses as $key => $response) {
            if (! $response || ! $response->successful()) {
                Log::warning('ElevenLabs batch request failed.', [
                    'key' => $key,
                    'status' => $response?->status(),
                    'voice_id' => $voiceId,
                ]);
                $audioUrls[$key] = null;
                continue;
            }

            $audioBytes = (string) $response->body();
            if ($audioBytes === '') {
                $audioUrls[$key] = null;
                continue;
            }

            $filename = 'traducciones/audio_' . uniqid('', true) . '.mp3';
            Storage::disk('public')->put($filename, $audioBytes);
            $audioUrls[$key] = $this->publicStorageUrl($filename);
        }

        return $audioUrls;
    }

    /**
     * Equivalente OpenAI de synthesizeLiveAudioBatchForVoice: mismo patron de Http::pool
     * (todas las llamadas HTTP concurrentes, no una tras otra) para no sumar latencia
     * cuando hay varios idiomas destino en el lote.
     */
    private function synthesizeLiveAudioBatchOpenAi(array $items, array $translationSettings): array
    {
        $apiKey = (string) config('services.openai.key', '');
        if ($apiKey === '') {
            return [];
        }

        $voice = (string) ($translationSettings['voice'] ?? 'marin');
        $model = (string) config('spikia.translation_simultaneous.text_to_speech_model', 'gpt-4o-mini-tts');

        $responses = Http::pool(function (Pool $pool) use ($items, $apiKey, $voice, $model) {
            $requests = [];
            foreach ($items as $key => $item) {
                $requests[$key] = $pool
                    ->as($key)
                    ->withHeaders(['Authorization' => 'Bearer ' . $apiKey])
                    ->timeout(15)
                    ->post('https://api.openai.com/v1/audio/speech', [
                        'model' => $model,
                        'voice' => $voice,
                        'input' => (string) ($item['text'] ?? ''),
                        'response_format' => 'mp3',
                    ]);
            }

            return $requests;
        });

        $audioUrls = [];
        foreach ($responses as $key => $response) {
            if (! $response || ! $response->successful()) {
                Log::warning('OpenAI TTS batch request failed.', [
                    'key' => $key,
                    'status' => $response?->status(),
                    'voice' => $voice,
                ]);
                $audioUrls[$key] = null;
                continue;
            }

            $audioBytes = (string) $response->body();
            if ($audioBytes === '') {
                $audioUrls[$key] = null;
                continue;
            }

            $filename = 'traducciones/audio_' . uniqid('', true) . '.mp3';
            Storage::disk('public')->put($filename, $audioBytes);
            $audioUrls[$key] = $this->publicStorageUrl($filename);
        }

        return $audioUrls;
    }

    private function buildTranslationSettings(array $data): array
    {
        $config = config('spikia.translation_simultaneous', []);
        $voiceGenderProfile = $this->resolveVoiceGender(
            (string) ($data['voice_gender_profile'] ?? ($config['voice_gender_profile'] ?? 'female'))
        );
        $voice = $this->normalizeVoiceSelection(
            (string) ($data['voice'] ?? ($config['voice'] ?? 'marin')),
            $voiceGenderProfile
        );

        return [
            'translation_mode' => $data['translation_mode'] ?? 'voice_to_voice',
            'speech_to_text_model' => $data['speech_to_text_model'] ?? ($config['speech_to_text_model'] ?? 'gpt-4o-mini-transcribe'),
            'translation_model' => $data['translation_model'] ?? ($config['translation_model'] ?? 'gpt-4o-mini'),
            'text_to_speech_model' => $data['text_to_speech_model'] ?? ($config['text_to_speech_model'] ?? 'gpt-4o-mini-tts'),
            'voice_provider' => $data['voice_provider'] ?? ($config['voice_provider'] ?? 'openai'),
            'voice_gender_profile' => $voiceGenderProfile,
            'voice' => $voice,
            'audio_delivery_mode' => $data['audio_delivery_mode'] ?? ($config['audio_delivery_mode'] ?? 'ultra_fast'),
            'master_translation_prompt' => trim((string) ($data['master_translation_prompt'] ?? ($config['master_translation_prompt'] ?? ''))),
        ];
    }
}
