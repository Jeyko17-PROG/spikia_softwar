<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QrCodeService
{
    public function transmissionSvg(string $slug, string $url): string
    {
        $cacheKey = 'spikia:qr:transmission:' . $slug . ':' . sha1($url);

        return Cache::remember(
            $cacheKey,
            now()->addHours(2),
            fn () => QrCode::format('svg')->errorCorrection('H')->size(176)->margin(2)->generate($url)
        );
    }
}
