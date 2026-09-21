<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Archiva (soft-delete) las demos y sesiones vencidas: salen de las listas de trabajo
// activas pero Registro de Actividad e Historial de Transcripciones las siguen mostrando
// (Sesion::withTrashed()). Antes se borraban de verdad (cascade sobre transcripciones/
// traducciones) y desaparecian de ahi sin dejar rastro.
Schedule::command('spikia:sessions:cleanup')->dailyAt('04:00');

// Los MP3 de traduccion se acumulan en storage/app/public/traducciones y nunca
// se borraban: el disco crecia sin limite. Limpiamos los de mas de 6h cada hora.
Schedule::command('spikia:audio:cleanup --hours=6')->hourly();

// El worker del bot de reunion (Node, fuera de Laravel) puede morirse sin avisar (VPS
// caido, Chrome crasheado, etc.). Si dejo de mandar heartbeat por mas de 90s lo marcamos
// como error para que la UI del Master no se quede mostrando "activo" indefinidamente.
Schedule::command('spikia:meeting-bot:sweep')->everyMinute();
