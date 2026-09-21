<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class SpikiaCleanupAudio extends Command
{
    protected $signature = 'spikia:audio:cleanup {--hours=6 : Borra audios traducidos con mas de N horas}';

    protected $description = 'Elimina los MP3 de traduccion antiguos para que el disco no se llene';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = Carbon::now()->subHours($hours)->timestamp;

        $disk = Storage::disk('public');
        $deleted = 0;
        $freedBytes = 0;

        foreach ($disk->files('traducciones') as $file) {
            if (! str_ends_with(strtolower($file), '.mp3')) {
                continue;
            }

            if ($disk->lastModified($file) >= $cutoff) {
                continue;
            }

            $freedBytes += $disk->size($file);
            $disk->delete($file);
            $deleted++;
        }

        $this->info(sprintf(
            'Audios eliminados: %d (liberados %.2f MB, mas viejos de %dh).',
            $deleted,
            $freedBytes / 1048576,
            $hours
        ));

        return self::SUCCESS;
    }
}
