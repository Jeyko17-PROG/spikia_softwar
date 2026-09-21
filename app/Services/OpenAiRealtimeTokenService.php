<?php

namespace App\Services;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

/**
 * PoC / benchmark (ver informe "reduccion de delay a 1-2s"): emite un "client secret"
 * efimero de la Realtime API de OpenAI para que el NAVEGADOR abra su propia conexion
 * WebSocket directa — mismo patron que Spikia ya usa con Deepgram en
 * SesionController::deepgramToken(): Laravel nunca expone la API key real al cliente,
 * solo un token de corta duracion.
 *
 * Verificado contra la documentacion oficial de OpenAI (guias "voice-webrtc",
 * "realtime-websocket", "realtime-conversations", consultadas al construir este PoC):
 * - Emision del token: POST https://api.openai.com/v1/realtime/client_secrets
 * - El navegador NO puede mandar headers custom en un WebSocket nativo, asi que del
 *   lado del browser la autenticacion va por el subprotocolo
 *   "openai-insecure-api-key.<token>" (NO por header Authorization).
 * - Conexion real: wss://api.openai.com/v1/realtime?model=<model>
 * - Para texto sin audio: session.output_modalities = ["text"].
 */
class OpenAiRealtimeTokenService
{
    private GuzzleClient $http;

    public function __construct()
    {
        $this->http = new GuzzleClient([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . (string) config('services.openai.key'),
                'Content-Type' => 'application/json',
            ],
            'timeout' => 8,
            'connect_timeout' => 3,
        ]);
    }

    /**
     * Brazo 1 (texto): traduce texto ya transcrito por el navegador (Web Speech API).
     * Sin audio: la sesion de traduccion dedicada de OpenAI (gpt-realtime-translate) es
     * audio-only y no acepta texto de entrada, por eso este brazo usa el modelo Realtime
     * general en modo output_modalities=["text"].
     *
     * @return array{success:bool, value?:string, model?:string, expires_at?:string, message?:string}
     */
    public function mintClientSecret(string $instructions): array
    {
        return $this->requestClientSecret([
            'output_modalities' => ['text'],
            'instructions' => $instructions,
        ]);
    }

    /**
     * Brazo 2 (audio, ver informe "menor latencia posible"): el navegador manda audio
     * PCM16 24kHz directo (sin pasar por Web Speech API). Turn detection configurable via
     * config('spikia.realtime_translation.vad') - la sesion de traduccion dedicada de
     * OpenAI NO expone esto (segmentacion interna, sin control del desarrollador), por eso
     * este brazo usa el modelo Realtime general, que si lo permite.
     *
     * @return array{success:bool, value?:string, model?:string, expires_at?:string, message?:string}
     */
    public function mintClientSecretForAudio(string $instructions): array
    {
        $vad = (array) config('spikia.realtime_translation.vad', []);
        $sampleRate = (int) config('spikia.realtime_translation.audio_sample_rate', 24000);

        $turnDetection = ($vad['type'] ?? 'semantic_vad') === 'server_vad'
            ? [
                'type' => 'server_vad',
                'silence_duration_ms' => (int) ($vad['silence_duration_ms'] ?? 400),
                'create_response' => true,
            ]
            : [
                'type' => 'semantic_vad',
                'eagerness' => (string) ($vad['eagerness'] ?? 'high'),
                'create_response' => true,
            ];

        return $this->requestClientSecret([
            'output_modalities' => ['text'],
            'instructions' => $instructions,
            'audio' => [
                'input' => [
                    'format' => ['type' => 'audio/pcm', 'rate' => $sampleRate],
                    // Transcribe el audio de ENTRADA (el idioma original que habla el
                    // presentador) para poder mostrar/guardar el texto original igual que
                    // hoy con Web Speech API - ver evento
                    // conversation.item.input_audio_transcription.completed.
                    'transcription' => ['model' => 'gpt-realtime-whisper'],
                    'turn_detection' => $turnDetection,
                ],
            ],
        ]);
    }

    /**
     * Brazo B (ver informe "A vs B"): sesion de traduccion DEDICADA de OpenAI
     * (gpt-realtime-translate). Transmite continuo, sin response.create ni turnos - Spikia
     * arma su propia heuristica de segmentacion SOLO para poder medir el benchmark (ver
     * resources/js/realtime-translate-dedicated-poc.js). Limitacion verificada en la doc
     * de OpenAI: este modelo NO soporta instructions/prompt personalizado ni VAD
     * configurable - solo se puede fijar el idioma de salida.
     *
     * @return array{success:bool, value?:string, model?:string, expires_at?:string, message?:string}
     */
    public function mintClientSecretForDedicatedTranslation(string $targetLanguage): array
    {
        $sampleRate = (int) config('spikia.realtime_translation.audio_sample_rate', 24000);
        $model = (string) config('spikia.realtime_translation.translation_dedicated_model', 'gpt-realtime-translate');

        return $this->requestClientSecret([
            'audio' => [
                'input' => [
                    'format' => ['type' => 'audio/pcm', 'rate' => $sampleRate],
                    'transcription' => ['model' => 'gpt-realtime-whisper'],
                    'noise_reduction' => ['type' => 'near_field'],
                ],
                'output' => [
                    'language' => $targetLanguage,
                ],
            ],
        ], $model);
    }

    /**
     * @param array<string,mixed> $sessionOverrides
     * @param string|null $modelOverride Si se omite, usa spikia.realtime_translation.model
     * @return array{success:bool, value?:string, model?:string, expires_at?:string, message?:string}
     */
    private function requestClientSecret(array $sessionOverrides, ?string $modelOverride = null): array
    {
        $apiKey = (string) config('services.openai.key', '');
        $model = $modelOverride ?? (string) config('spikia.realtime_translation.model', 'gpt-realtime-2.1');

        if ($apiKey === '') {
            return ['success' => false, 'message' => 'OpenAI no esta configurado en el servidor.'];
        }

        try {
            $response = $this->http->post('realtime/client_secrets', [
                'json' => [
                    'session' => array_merge([
                        'type' => 'realtime',
                        'model' => $model,
                    ], $sessionOverrides),
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $value = (string) ($body['value'] ?? '');

            if ($value === '') {
                Log::warning('spikia.realtime_token_empty', ['body' => $body]);

                return ['success' => false, 'message' => 'OpenAI no devolvio un token valido.'];
            }

            return [
                'success' => true,
                'value' => $value,
                'model' => $model,
                'expires_at' => (string) ($body['expires_at'] ?? ''),
            ];
        } catch (\Throwable $e) {
            Log::warning('spikia.realtime_token_failed', ['message' => $e->getMessage()]);

            return ['success' => false, 'message' => 'No se pudo conectar con OpenAI Realtime.'];
        }
    }
}
