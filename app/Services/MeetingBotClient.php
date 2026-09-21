<?php

namespace App\Services;

use App\Models\Sesion;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP hacia el worker Node.js de /meet-bot: un proceso separado y persistente
 * (Puppeteer + Chrome) que entra a una reunion de Meet/Zoom como invitado y captura su audio.
 * Laravel no lanza navegadores ni mantiene procesos largos; solo le pide al worker que
 * arranque/pare, igual que OpenAITranslationService le pide a la API de OpenAI.
 */
class MeetingBotClient
{
    private GuzzleClient $http;

    public function __construct()
    {
        $this->http = new GuzzleClient([
            'base_uri' => rtrim((string) config('spikia.meeting_bot.url'), '/') . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . (string) config('spikia.meeting_bot.secret'),
            ],
            'timeout' => 5,
            'connect_timeout' => 3,
        ]);
    }

    /**
     * Pide al worker que se una a la reunion de $sesion->zoom_link. Idempotente del lado del
     * worker: si ya esta uniendose/activo para este slug, devuelve el estado actual en vez de
     * lanzar un segundo navegador.
     */
    public function join(Sesion $sesion, string $ingestUrl): array
    {
        try {
            $response = $this->http->post('join', [
                'json' => [
                    'slug' => $sesion->slug,
                    'meetUrl' => $sesion->zoom_link,
                    'ingestUrl' => $ingestUrl,
                    'ingestToken' => $sesion->bot_ingest_token,
                    'sourceLang' => $sesion->meeting_bot_source_lang,
                ],
            ]);

            return json_decode((string) $response->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            Log::error('MeetingBotClient::join fallo al contactar al worker.', [
                'slug' => $sesion->slug,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Estado en vivo segun el worker (no la base de datos): es la unica fuente que sabe si
     * una union realmente fallo (Meet no dejo entrar, no se encontro el boton, etc.), algo que
     * Laravel nunca se entera por su cuenta porque no hay ningun callback del worker hacia
     * Laravel salvo la subida de audio.
     */
    public function status(Sesion $sesion): array
    {
        $response = $this->http->get('status/' . $sesion->slug);

        return json_decode((string) $response->getBody(), true) ?? ['status' => 'idle'];
    }

    public function leave(Sesion $sesion): array
    {
        try {
            $response = $this->http->post('leave', [
                'json' => ['slug' => $sesion->slug],
            ]);

            return json_decode((string) $response->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            Log::error('MeetingBotClient::leave fallo al contactar al worker.', [
                'slug' => $sesion->slug,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
