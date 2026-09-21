<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ElevenLabsVoiceCloningService
{
    /**
     * Crea una voz clonada (Instant Voice Cloning) a partir de una muestra de audio del
     * orador y devuelve el voice_id de ElevenLabs. Se llama UNA vez al configurar la
     * sesion, no por frase: no forma parte del camino critico de latencia en vivo.
     */
    public function clone(UploadedFile $sample, string $name): string
    {
        $settings = config('spikia.elevenlabs', []);
        $apiKey = (string) ($settings['api_key'] ?? '');

        if ($apiKey === '') {
            throw new RuntimeException('ElevenLabs no esta configurado (falta ELEVENLABS_API_KEY).');
        }

        $response = Http::withHeaders(['xi-api-key' => $apiKey])
            ->timeout(30)
            ->attach('files', file_get_contents($sample->getRealPath()), $sample->getClientOriginalName())
            ->post('https://api.elevenlabs.io/v1/voices/add', [
                'name' => $name,
                'description' => 'Voz clonada del orador para traduccion simultanea Spikia.',
            ]);

        if (! $response->successful()) {
            $code = (string) $response->json('detail.code', '');

            if ($response->status() === 401 && $code === 'unauthorized') {
                throw new RuntimeException(
                    'Tu plan de ElevenLabs no incluye clonación de voz (Instant Voice Cloning). '
                    . 'Necesitas actualizar a un plan pago (Starter o superior) en elevenlabs.io para usar esta función.'
                );
            }

            throw new RuntimeException(
                'ElevenLabs rechazo la clonacion de voz: ' . $response->status() . ' ' . $response->body()
            );
        }

        $voiceId = (string) ($response->json('voice_id') ?? '');

        if ($voiceId === '') {
            throw new RuntimeException('ElevenLabs no devolvio un voice_id valido.');
        }

        return $voiceId;
    }

    /**
     * Borra una voz clonada de ElevenLabs (al desactivar la clonacion o eliminar la sesion).
     */
    public function delete(string $voiceId): bool
    {
        $settings = config('spikia.elevenlabs', []);
        $apiKey = (string) ($settings['api_key'] ?? '');

        if ($apiKey === '' || $voiceId === '') {
            return false;
        }

        $response = Http::withHeaders(['xi-api-key' => $apiKey])
            ->timeout(10)
            ->delete("https://api.elevenlabs.io/v1/voices/{$voiceId}");

        return $response->successful();
    }
}
