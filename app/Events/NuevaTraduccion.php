<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NuevaTraduccion implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $slug;
    public $id;
    public $traduccion;
    public $idioma;

    public function __construct($slug, $id, $traduccion, $idioma)
    {
        $this->slug = $slug;
        $this->id = $id;
        $this->traduccion = $traduccion;
        $this->idioma = $idioma;
    }

    public function broadcastOn()
    {
        return new Channel('transmision.' . $this->slug);
    }

    public function broadcastAs()
    {
        return 'NuevaTraduccion';
    }

    public function broadcastWith()
    {
        return [
            'id' => $this->id,
            'texto' => $this->traduccion,
            'idioma' => $this->idioma,
        ];
    }
}