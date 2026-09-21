<?php

namespace App\Console\Commands;

use App\Models\Sesion;
use Illuminate\Console\Command;

class SpikiaMeetingBotSweep extends Command
{
    protected $signature = 'spikia:meeting-bot:sweep';
    protected $description = 'Marca como error las sesiones cuyo bot de reunion dejo de mandar heartbeat (worker caido)';

    public function handle(): int
    {
        $marked = Sesion::where('meeting_bot_status', 'active')->get()
            ->filter(fn (Sesion $sesion) => $sesion->meeting_bot_stale)
            ->each(function (Sesion $sesion) {
                $sesion->meeting_bot_status = 'error';
                $sesion->save();
            })
            ->count();

        $this->info("Sesiones marcadas con bot en error: {$marked}");

        return self::SUCCESS;
    }
}
