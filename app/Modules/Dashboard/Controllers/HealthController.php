<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Support\SpikiaUrl;
use Illuminate\Support\Facades\Http;

class HealthController extends Controller
{
    public function index()
    {
        $checks = [];

        $checks[] = $this->checkApp();
        $checks[] = $this->checkPublicUrl();
        $checks[] = $this->checkOpenAI();
        $checks[] = $this->checkDeepgram();
        $checks[] = $this->checkElevenLabs();
        $checks[] = $this->checkCache();

        $allOk = collect($checks)->every(fn ($c) => ($c['status'] ?? '') === 'ok');

        return view('modules.dashboard.health', [
            'checks' => $checks,
            'allOk' => $allOk,
        ]);
    }

    private function checkApp(): array
    {
        return [
            'name' => 'Aplicacion Laravel',
            'status' => 'ok',
            'detail' => 'PHP ' . PHP_VERSION . ' / Laravel ' . app()->version(),
        ];
    }

    private function checkPublicUrl(): array
    {
        $configured = (string) config('spikia.public_base_url', '');
        if ($configured === '') {
            return [
                'name' => 'URL publica (tunnel)',
                'status' => 'warning',
                'detail' => 'No configurada en .env (usara auto-deteccion)',
            ];
        }

        try {
            $res = Http::timeout(5)->head($configured);
            return [
                'name' => 'URL publica (tunnel)',
                'status' => $res->successful() ? 'ok' : 'error',
                'detail' => $configured . ' [' . $res->status() . ']',
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'URL publica (tunnel)',
                'status' => 'error',
                'detail' => $configured . ' (' . $e->getMessage() . ')',
            ];
        }
    }

    private function checkOpenAI(): array
    {
        $key = (string) config('services.openai.key', '');
        if ($key === '') {
            return [
                'name' => 'OpenAI',
                'status' => 'warning',
                'detail' => 'Sin API key configurada (traduccion va por Google)',
            ];
        }
        try {
            $res = Http::timeout(5)->withToken($key)->get('https://api.openai.com/v1/models');
            if ($res->successful()) {
                return ['name' => 'OpenAI', 'status' => 'ok', 'detail' => 'Key valida'];
            }
            return ['name' => 'OpenAI', 'status' => 'warning', 'detail' => 'Key rechazada (' . $res->status() . ')'];
        } catch (\Throwable $e) {
            return ['name' => 'OpenAI', 'status' => 'error', 'detail' => $e->getMessage()];
        }
    }

    private function checkDeepgram(): array
    {
        $key = (string) config('services.deepgram.key', '');
        if ($key === '') {
            return [
                'name' => 'Deepgram (STT)',
                'status' => 'error',
                'detail' => 'Sin API key — master no podra transcribir',
            ];
        }
        try {
            $res = Http::timeout(5)->withHeaders(['Authorization' => 'Token ' . $key])
                ->get('https://api.deepgram.com/v1/projects');
            if ($res->successful()) {
                return ['name' => 'Deepgram (STT)', 'status' => 'ok', 'detail' => 'Key valida, streaming listo'];
            }
            return ['name' => 'Deepgram (STT)', 'status' => 'error', 'detail' => 'Key rechazada (' . $res->status() . ')'];
        } catch (\Throwable $e) {
            return ['name' => 'Deepgram (STT)', 'status' => 'error', 'detail' => $e->getMessage()];
        }
    }

    private function checkElevenLabs(): array
    {
        $settings = config('spikia.elevenlabs', []);
        if (! ((bool) ($settings['enabled'] ?? false))) {
            return [
                'name' => 'ElevenLabs (voz humana)',
                'status' => 'warning',
                'detail' => 'Desactivado en .env (caera a voz robotica del navegador)',
            ];
        }
        $key = (string) ($settings['api_key'] ?? '');
        if ($key === '') {
            return ['name' => 'ElevenLabs (voz humana)', 'status' => 'error', 'detail' => 'Sin API key'];
        }
        try {
            $res = Http::timeout(5)->withHeaders(['xi-api-key' => $key])
                ->get('https://api.elevenlabs.io/v1/user');
            if (! $res->successful()) {
                return ['name' => 'ElevenLabs (voz humana)', 'status' => 'error', 'detail' => 'Key rechazada (' . $res->status() . ')'];
            }
            $data = $res->json('subscription', []);
            $used = (int) ($data['character_count'] ?? 0);
            $limit = (int) ($data['character_limit'] ?? 0);
            $left = max(0, $limit - $used);
            $approxMin = (int) floor($left / 25 / 60);
            $status = 'ok';
            if ($left < 500) $status = 'error';
            elseif ($left < 2000) $status = 'warning';

            return [
                'name' => 'ElevenLabs (voz humana)',
                'status' => $status,
                'detail' => "{$used}/{$limit} chars usados — quedan {$left} (~{$approxMin} min de voz)",
            ];
        } catch (\Throwable $e) {
            return ['name' => 'ElevenLabs (voz humana)', 'status' => 'error', 'detail' => $e->getMessage()];
        }
    }

    private function checkCache(): array
    {
        try {
            cache()->put('spikia.health.test', '1', 5);
            $value = cache()->get('spikia.health.test');
            cache()->forget('spikia.health.test');
            if ($value === '1') {
                return [
                    'name' => 'Cache de Laravel',
                    'status' => 'ok',
                    'detail' => 'Driver: ' . config('cache.default') . ' (read/write OK)',
                ];
            }
            return ['name' => 'Cache de Laravel', 'status' => 'error', 'detail' => 'No retorno valor escrito'];
        } catch (\Throwable $e) {
            return ['name' => 'Cache de Laravel', 'status' => 'error', 'detail' => $e->getMessage()];
        }
    }
}
