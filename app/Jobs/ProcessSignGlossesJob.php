<?php

namespace App\Jobs;

use App\Events\SignLanguageBroadcastEvent;
use App\Models\Sesion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

        try {
            $glosses = $this->textToGlosses($texto);
        } catch (\Throwable $e) {
            Log::error('ProcessSignGlossesJob: fallo generando glosas de lengua de senas.', [
                'sesion_id' => $this->sessionId,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if ($glosses === []) {
            return;
        }

        broadcast(new SignLanguageBroadcastEvent($sesion->slug, $glosses, $this->textData));
    }

    /**
     * Placeholder de texto -> glosas (mayusculas, sin stopwords basicas de español). Es el
     * punto de extension pensado para conectar el motor real de traduccion a LSE/glosas
     * (modelo de IA, servicio externo, diccionario de glosas, etc.) sin tocar el resto del
     * pipeline: quien reemplace este metodo no necesita cambiar el job, el evento ni el guard.
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
