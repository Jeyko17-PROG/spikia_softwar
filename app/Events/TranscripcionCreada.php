<?php

namespace App\Events;

use App\Models\Transcripcion;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TranscripcionCreada implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public $transcripcion;
    public $slug;

    public function __construct(Transcripcion $transcripcion, $slug)
    {
        $this->transcripcion = $transcripcion;
        $this->slug = $slug;
    }

    public function broadcastOn()
    {
        return new Channel('transmision.' . $this->slug);
    }

    public function broadcastAs()
    {
        return 'TranscripcionCreada';
    }

    public function broadcastWith()
    {
        return [
            'id' => $this->transcripcion->id,
            'texto' => $this->transcripcion->texto,
            'idioma' => $this->transcripcion->idioma,
            'audio_url' => $this->transcripcion->audio_url,
            'genero' => $this->transcripcion->genero,
            'variante' => $this->transcripcion->variante,
            'tipo' => $this->transcripcion->tipo,
            'published_at' => $this->transcripcion->published_at,
            'available_at' => $this->transcripcion->available_at,
            'revision' => $this->transcripcion->revision ?? 1,
        ];
    }
}
