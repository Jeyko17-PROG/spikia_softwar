<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Aquí registras los canales que Laravel puede autorizar.
| En tu caso estamos usando canales públicos para transmisión.
|
*/

/*
|--------------------------------------------------------------------------
| Canal público para transmisión en tiempo real
|--------------------------------------------------------------------------
|
| Este canal coincide con:
| transmision.{slug}
|
| Ejemplo:
| transmision.congreso-909
|
*/

Broadcast::channel('transmision.{slug}', function ($user, $slug) {
    return true; // Permite acceso público
});
