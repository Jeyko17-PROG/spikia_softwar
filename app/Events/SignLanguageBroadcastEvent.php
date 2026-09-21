<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento independiente del pipeline de traduccion en vivo (TranscripcionCreada). Se emite
 * SOLO desde ProcessSignGlossesJob, nunca desde el flujo principal de processAudio(), para que
 * un fallo o una lentitud aqui jamas retrase ni tumbe la traduccion por voz que ya funciona.
 */
class SignLanguageBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $slug,
        public readonly array $glosses,
        public readonly array $sourceTextData = [],
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('transmision.' . $this->slug);
    }

    public function broadcastAs(): string
    {
        return 'SignLanguageBroadcast';
    }

    public function broadcastWith(): array
    {
        return [
            'glosses' => $this->glosses,
            'idioma' => $this->sourceTextData['idioma'] ?? 'es',
            'texto' => $this->sourceTextData['texto'] ?? '',
            'ts' => now()->timestamp,
        ];
    }
}
