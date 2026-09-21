<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SpikiaDetectIp extends Command
{
    protected $signature = 'spikia:detect-ip {--write : Actualiza SPIKIA_PUBLIC_BASE_URL en .env automaticamente}';
    protected $description = 'Detecta la IP LAN actual y la compara con SPIKIA_PUBLIC_BASE_URL';

    public function handle(): int
    {
        Cache::forget('spikia.detected_lan_ip');

        $detected = \App\Support\SpikiaUrl::class;
        $reflection = new \ReflectionClass($detected);
        $method = $reflection->getMethod('cachedLanIp');
        $method->setAccessible(true);
        $detectedIp = $method->invoke(null);

        $configured = (string) config('spikia.public_base_url', '');

        $port = parse_url(config('app.url'), PHP_URL_PORT) ?: 8000;
        $suggested = $detectedIp ? "http://{$detectedIp}:{$port}" : null;

        $this->line('IP detectada:        ' . ($detectedIp ?: '-- no detectada --'));
        $this->line('Config actual:       ' . ($configured ?: '(vacio)'));
        $this->line('URL sugerida:        ' . ($suggested ?: '--'));

        if (! $suggested) {
            $this->error('No se pudo detectar IP LAN.');
            return self::FAILURE;
        }

        if ($configured === $suggested) {
            $this->info('Configuracion sincronizada.');
            return self::SUCCESS;
        }

        $this->warn('Configuracion desincronizada.');

        if ($this->option('write')) {
            $envPath = base_path('.env');
            $contents = file_get_contents($envPath);
            $new = preg_replace(
                '/^SPIKIA_PUBLIC_BASE_URL=.*$/m',
                'SPIKIA_PUBLIC_BASE_URL=' . $suggested,
                $contents
            );
            if ($new === $contents) {
                $new .= "\nSPIKIA_PUBLIC_BASE_URL={$suggested}\n";
            }
            file_put_contents($envPath, $new);
            $this->call('config:clear');
            $this->info("Env actualizado: SPIKIA_PUBLIC_BASE_URL={$suggested}");
        } else {
            $this->line('Ejecuta: php artisan spikia:detect-ip --write');
        }

        return self::SUCCESS;
    }
}
