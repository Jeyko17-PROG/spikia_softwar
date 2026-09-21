<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class TraduccionGenerada implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $slug;
    public $texto;
    public $traduccion;

    public function __construct($slug, $texto, $traduccion)
    {
        $this->slug = $slug;
        $this->texto = $texto;
        $this->traduccion = $traduccion; 
    }

    public function broadcastOn()
    {
        return new Channel('transmision.' . $this->slug);
    }

    public function broadcastAs()
    {
        return 'nueva-traduccion';
    }
}