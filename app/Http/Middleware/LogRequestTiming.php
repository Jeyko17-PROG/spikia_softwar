<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogRequestTiming
{
    private const HOT_PATHS = [
        'traducciones',
        'voz/elevenlabs',
        'voz/elevenlabs/stream',
        'sesiones/*/mensajes',
        'sesiones/*/procesar-audio',
        'sesiones/*/interim',
        'deepgram/token',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $startMem = memory_get_usage();

        $response = $next($request);

        $durationMs = (microtime(true) - $start) * 1000;
        $memDeltaKb = (memory_get_usage() - $startMem) / 1024;

        $path = trim($request->path(), '/');
        $isHot = $this->matchesHotPath($path);
        $isError = $response->getStatusCode() >= 400;

        // Antes se logueaba CADA request a una hot-path (el feed se consulta cada ~2s
        // por oyente), lo que infló laravel.log a decenas de MB. Ahora solo registramos
        // lo accionable: peticiones lentas o con error en las rutas criticas.
        if ($isError || ($isHot && $durationMs > 1500)) {
            $level = $isError || $durationMs > 3000 ? 'warning' : 'info';
            Log::{$level}('spikia.timing', [
                'method' => $request->method(),
                'path' => $path,
                'status' => $response->getStatusCode(),
                'duration_ms' => round($durationMs, 1),
                'mem_kb' => round($memDeltaKb, 1),
                'ip' => $request->ip(),
            ]);
        }

        $response->headers->set('X-Spikia-Duration-Ms', (string) round($durationMs, 1));

        return $response;
    }

    private function matchesHotPath(string $path): bool
    {
        foreach (self::HOT_PATHS as $pattern) {
            $regex = '#^' . str_replace('\*', '[^/]+', preg_quote($pattern, '#')) . '$#';
            if (preg_match($regex, $path)) {
                return true;
            }
        }
        return false;
    }
}
