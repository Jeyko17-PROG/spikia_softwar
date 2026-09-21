<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TraduccionEnviada implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $slug;
    public $texto;
    public $idioma;

    public function __construct($slug, $texto, $idioma)
    {
        $this->slug = $slug;
        $this->texto = $texto;
        $this->idioma = $idioma;
    }

    public function broadcastOn()
    {
        return new Channel('transmision.' . $this->slug);
    }

    public function broadcastAs()
    {
        return 'TraduccionEnviada';
    }

    public function broadcastWith()
    {
        return [
            'texto' => $this->texto,
            'idioma' => $this->idioma,
        ];
    }
}