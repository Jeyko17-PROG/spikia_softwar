<?php

namespace App\Providers;

use App\Services\LiveKitTokenService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LiveKitTokenService::class, function () {
            return new LiveKitTokenService(
                (string) config('spikia.livekit.api_key', ''),
                (string) config('spikia.livekit.api_secret', ''),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $request = request();
        if (! $request) {
            return;
        }

        $host = strtolower((string) $request->getHost());
        $scheme = strtolower((string) ($request->header('X-Forwarded-Proto') ?: $request->getScheme()));

        $isTunnel = str_contains($host, 'trycloudflare.com')
            || str_contains($host, 'loca.lt')
            || str_contains($host, 'ngrok')
            || str_contains($host, 'ngrok-free.app')
            || str_contains($host, 'ngrok-free.dev')
            || str_contains($host, 'localhost.run')
            || str_contains($host, 'lhr.life');

        if (! $isTunnel && $scheme !== 'https') {
            return;
        }

        URL::forceScheme('https');

        // cloudflared reescribe el Host header a spikia.test (--http-host-header) para que
        // Apache enrute al vhost correcto por name-based virtual hosting. Eso significa que
        // $request->getHost() YA NO es el hostname publico real cuando se entra por el tunel:
        // si generamos la root URL con el host de la request, quedan assets/enlaces apuntando
        // a "spikia.test", que no resuelve fuera de esta maquina (confirmado con DNS publico).
        // SPIKIA_PUBLIC_BASE_URL es la fuente de verdad ya sincronizada por
        // `php artisan spikia:set-tunnel-url`: si esta seteada, manda sobre el host de la
        // request para cualquier trafico https (tunel).
        $publicBaseUrl = trim((string) config('spikia.public_base_url', ''));
        if ($publicBaseUrl !== '' && $scheme === 'https') {
            URL::forceRootUrl($publicBaseUrl);
            return;
        }

        URL::forceRootUrl($request->getSchemeAndHttpHost());
    }
}