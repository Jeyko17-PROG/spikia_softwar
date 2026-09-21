<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SkipNgrokBrowserWarning
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('ngrok-skip-browser-warning', 'true');

        $host = strtolower((string) $request->getHost());
        $isTunnel = str_contains($host, 'ngrok-free.app')
            || str_contains($host, 'ngrok-free.dev')
            || str_contains($host, 'ngrok.io')
            || str_contains($host, 'ngrok.app')
            || str_contains($host, 'ngrok.dev');

        if ($isTunnel) {
            $response->headers->set('User-Agent', 'SpikiaApp/1.0');
        }

        return $response;
    }
}
