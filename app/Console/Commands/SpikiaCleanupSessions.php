<?php

namespace App\Console\Commands;

use App\Models\Sesion;
use App\Models\SessionUsageEvent;
use Illuminate\Console\Command;

class SpikiaCleanupSessions extends Command
{
    protected $signature = 'spikia:sessions:cleanup {--user-id= : Limita la limpieza a un usuario}';
    protected $description = 'Archiva (soft-delete) las demos y sesiones vencidas fuera de la ventana de extension';

    public function handle(): int
    {
        $query = Sesion::query();

        if ($this->option('user-id')) {
            $query->where('user_id', (int) $this->option('user-id'));
        }

        $archived = 0;

        // Sesion usa SoftDeletes: delete() ya no ejecuta un DELETE real ni dispara el cascade
        // sobre transcripciones/traducciones, solo pone deleted_at. La sesion desaparece de la
        // lista de trabajo (Gestion de Sesiones, master, transmision, etc.) pero Registro de
        // Actividad e Historial de Transcripciones la siguen mostrando via withTrashed().
        $query->get()->each(function (Sesion $sesion) use (&$archived) {
            if (! $sesion->session_expired_for_deletion) {
                return;
            }

            SessionUsageEvent::create([
                'user_id' => $sesion->user_id,
                'sesion_id' => $sesion->id,
                'slug' => $sesion->slug,
                'action' => $sesion->demo_expires_at ? 'demo_archived' : 'session_archived',
                'metadata' => [
                    'titulo' => $sesion->titulo,
                    'hora_fin' => $sesion->hora_fin,
                    'extra_time_minutes' => (int) ($sesion->extra_time_minutes ?? 0),
                    'extension_count' => (int) ($sesion->extension_count ?? 0),
                ],
                'occurred_at' => now(),
            ]);

            $sesion->delete();
            $archived++;
        });

        $this->info("Sesiones archivadas: {$archived}");

        return self::SUCCESS;
    }
}
