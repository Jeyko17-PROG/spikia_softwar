<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reglas de uso:
 * - SpikiaUrl::public()  -> SOLO para URLs que veran dispositivos externos (QR, "Copiar enlace", links compartibles, redes sociales).
 * - SpikiaUrl::master()  -> SOLO para enlaces que se envian por email/SMS al organizador.
 * - Para botones del panel que el dueno cliquea desde su navegador, usar route() directo.
 */
final class SpikiaUrl
{
    public static function master(string $url): string
    {
        return self::rewrite(
            $url,
            self::normalizeBaseUrl((string) config('spikia.master_base_url', config('app.url')))
        );
    }

    public static function public(string $url): string
    {
        // 1) Si el request actual llega por un host publico/tunnel, esa es la fuente mas fresca.
        $requestBaseUrl = self::detectFromCurrentRequest();
        if ($requestBaseUrl) {
            return self::rewrite($url, $requestBaseUrl);
        }

        // 2) Config explicito en .env, util cuando el panel se abre desde localhost.
        $baseUrl = self::normalizeBaseUrl((string) config('spikia.public_base_url', ''));
        if ($baseUrl && self::baseUrlMatchesCurrentHost($baseUrl)) {
            return self::rewrite($url, $baseUrl);
        }

        // 3) Deteccion dinamica por socket UDP
        $detected = self::detectDynamicPublicBaseUrl($url);
        if ($detected) {
            if ($baseUrl) {
                Log::warning('SpikiaUrl: SPIKIA_PUBLIC_BASE_URL apunta a una IP no activa. Usando deteccion automatica.', [
                    'configured' => $baseUrl,
                    'detected' => $detected,
                ]);
            }
            return self::rewrite($url, $detected);
        }

        // 4) Ultimo recurso: usar el config aunque este desactualizado
        if ($baseUrl) {
            return self::rewrite($url, $baseUrl);
        }

        return $url;
    }

    private static function baseUrlMatchesCurrentHost(string $baseUrl): bool
    {
        $parts = parse_url($baseUrl);
        if (! is_array($parts)) {
            return false;
        }
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }

        // Hostnames (no IP) - asumimos validos, DNS resuelve
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        // Loopback siempre valido
        if (in_array($host, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        $localIps = self::collectLocalIps();
        return in_array($host, $localIps, true);
    }

    private static function collectLocalIps(): array
    {
        return Cache::remember('spikia.local_ips', 30, function () {
            $ips = [];
            $hostname = @gethostname();
            if ($hostname) {
                $resolved = @gethostbynamel($hostname);
                if (is_array($resolved)) {
                    $ips = array_merge($ips, $resolved);
                }
            }
            $socketIp = self::detectLanIpViaSocket();
            if ($socketIp) {
                $ips[] = $socketIp;
            }
            return array_values(array_unique(array_filter($ips, fn ($ip) => is_string($ip) && $ip !== '')));
        });
    }

    private static function detectFromCurrentRequest(): ?string
    {
        if (! function_exists('request')) {
            return null;
        }
        try {
            $request = request();
        } catch (\Throwable $e) {
            return null;
        }
        if (! $request) {
            return null;
        }
        $host = strtolower((string) $request->getHost());
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }
        // Hostnames de desarrollo local (ej. "spikia.test" via Laragon) resuelven en ESTA PC
        // por /etc/hosts pero no existen en internet. Si no se excluyen aca, un dueno que abre
        // su propio panel en http://spikia.test terminaria con QRs/enlaces publicos apuntando
        // a una URL que nadie mas puede alcanzar - justo el bug que se detecto antes de una demo.
        if (self::isLocalDevHost($host)) {
            return null;
        }
        return rtrim($request->getSchemeAndHttpHost(), '/');
    }

    private static function isLocalDevHost(string $host): bool
    {
        $appHost = strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));
        if ($appHost !== '' && $host === $appHost) {
            return true;
        }

        foreach (['.test', '.local', '.localhost'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function detectDynamicPublicBaseUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== '' && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }

        $detectedHost = self::cachedLanIp();
        if (! $detectedHost) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

        return sprintf('%s://%s%s', $scheme, $detectedHost, $port);
    }

    private static function cachedLanIp(): ?string
    {
        return Cache::remember('spikia.detected_lan_ip', 60, function () {
            return self::detectLanIpViaSocket() ?? self::firstLanIp();
        });
    }

    /**
     * Abrir un socket UDP "ficticio" a un IP publico (8.8.8.8) y leer
     * la direccion local que el SO escogio para ese paquete.
     * Esto NO envia datos y no depende del cache DNS de Windows.
     */
    private static function detectLanIpViaSocket(): ?string
    {
        $sock = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
        if (! $sock) {
            return null;
        }
        $name = @stream_socket_get_name($sock, false);
        @fclose($sock);
        if (! $name) {
            return null;
        }
        $ip = preg_replace('/:\d+$/', '', $name);
        if (! $ip || $ip === '0.0.0.0' || $ip === '127.0.0.1') {
            return null;
        }
        return $ip;
    }

    private static function firstLanIp(): ?string
    {
        $hostname = @gethostname();
        if (! $hostname) {
            return null;
        }
        $records = @gethostbynamel($hostname);
        if (! is_array($records)) {
            return null;
        }
        foreach ($records as $ip) {
            if (! is_string($ip) || $ip === '127.0.0.1') {
                continue;
            }
            if (preg_match('/^(10|192\.168|172\.(1[6-9]|2\d|3[0-1]))\./', $ip)) {
                return $ip;
            }
        }
        foreach ($records as $ip) {
            if (is_string($ip) && $ip !== '127.0.0.1') {
                return $ip;
            }
        }
        return null;
    }

    private static function rewrite(string $url, ?string $baseUrl): string
    {
        if (! $baseUrl) {
            return $url;
        }
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }
        $path     = $parts['path'] ?? '';
        $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return rtrim($baseUrl, '/') . $path . $query . $fragment;
    }

    private static function normalizeBaseUrl(string $baseUrl): ?string
    {
        $baseUrl = trim($baseUrl);
        return $baseUrl !== '' ? rtrim($baseUrl, '/') : null;
    }
}
