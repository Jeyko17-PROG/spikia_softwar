<?php

namespace App\Jobs;

use App\Models\Transcripcion;
use App\Modules\Sessions\Controllers\SesionController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sintetiza y guarda en disco el audio de UNA traduccion ya generada, para que quede
 * disponible despues al armar el audio completo de la sesion (TranscripcionController::descargar).
 *
 * Corre en su propia cola ('low-priority') a proposito: en modo audio_delivery_mode=ultra_fast
 * el pipeline en vivo (SesionController::processAudio) nunca espera a ElevenLabs -- esa es la
 * ventaja de ese modo, el oyente recibe el audio streameado aparte via elevenlabsStream(). Si
 * este guardado corriera sincronico ahi, se perderia esa ventaja para TODAS las sesiones, no
 * solo las que se lleguen a descargar. Por eso se despacha como job en vez de llamarse directo.
 */
class PersistTranslatedAudioSegmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 20;

    public function __construct(
        public readonly int $transcripcionId,
        public readonly string $texto,
        public readonly array $translationSettings,
        public readonly string $gender,
    ) {
    }

    public function handle(SesionController $controller): void
    {
        $transcripcion = Transcripcion::find($this->transcripcionId);

        // La fila pudo borrarse (sesion eliminada) o ya tener audio (reintento tardio de un
        // job anterior) para cuando el worker la recoge -- en cualquier caso no hay nada que
        // hacer, y sobre todo no hay que pisar un audio_url que ya se guardo bien.
        if (! $transcripcion || $transcripcion->audio_url) {
            return;
        }

        try {
            $audioUrls = $controller->synthesizeLiveAudioBatch(
                ['segmento' => ['text' => $this->texto, 'gender' => $this->gender]],
                $this->translationSettings,
                $this->gender
            );
        } catch (\Throwable $e) {
            Log::warning('PersistTranslatedAudioSegmentJob: fallo sintetizando audio para archivar.', [
                'transcripcion_id' => $this->transcripcionId,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $audioUrl = $audioUrls['segmento'] ?? null;
        if ($audioUrl) {
            $transcripcion->update(['audio_url' => $audioUrl]);
        }
    }
}
