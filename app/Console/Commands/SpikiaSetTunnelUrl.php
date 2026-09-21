<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SpikiaSetTunnelUrl extends Command
{
    protected $signature = 'spikia:set-tunnel-url {url : URL publica (https://...) del tunnel}';
    protected $description = 'Actualiza SPIKIA_PUBLIC_BASE_URL en .env con la URL del tunnel (Cloudflare/ngrok/etc) y limpia caches';

    public function handle(): int
    {
        $url = rtrim(trim((string) $this->argument('url')), '/');

        if (! preg_match('#^https?://[^/]+$#', $url)) {
            $this->error("URL invalida. Ejemplo: https://abc.trycloudflare.com");
            return self::FAILURE;
        }

        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            $this->error('.env no existe.');
            return self::FAILURE;
        }

        $contents = file_get_contents($envPath);
        $line = "SPIKIA_PUBLIC_BASE_URL={$url}";

        if (preg_match('/^SPIKIA_PUBLIC_BASE_URL=.*$/m', $contents)) {
            $contents = preg_replace('/^SPIKIA_PUBLIC_BASE_URL=.*$/m', $line, $contents);
        } else {
            $contents = rtrim($contents) . "\n{$line}\n";
        }

        file_put_contents($envPath, $contents);

        $this->info("SPIKIA_PUBLIC_BASE_URL actualizado: {$url}");

        $this->call('cache:clear');
        $this->call('config:clear');
        $this->call('view:clear');
        $this->call('route:clear');

        $this->newLine();
        $this->info('Listo. Recarga /sesiones con Ctrl+Shift+R y los QR ya apuntan a la URL nueva.');

        return self::SUCCESS;
    }
}
