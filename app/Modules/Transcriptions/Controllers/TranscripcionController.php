<?php

namespace App\Modules\Transcriptions\Controllers;

use App\Events\TranscripcionCreada;
use App\Http\Controllers\Controller;
use App\Models\Sesion;
use App\Models\SessionUsageEvent;
use App\Models\Transcripcion;
use App\Services\TranscriptionHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TranscripcionController extends Controller
{
    public function index($slug)
    {
        $sesion = Sesion::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        return view('modules.transcriptions.index', compact('sesion'));
    }

    public function listado(Request $request, TranscriptionHistoryService $transcriptionHistoryService)
    {
        $modo = $request->query('modo');
        $q = trim((string) $request->query('q', ''));
        $resumenes = $transcriptionHistoryService->paginateForUser($request->user(), $modo, $q, 20);

        return view('modules.transcriptions.listado', compact('resumenes', 'modo', 'q'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'texto' => ['required', 'string'],
            'idioma' => ['required', 'string'],
            'slug' => ['required', 'string'],
            'sesion_id' => ['required', 'integer'],
            'hablante' => ['nullable', 'string', 'max:80'],
        ]);

        $transcripcion = Transcripcion::create([
            'user_id' => auth()->id(),
            'sesion_id' => $request->sesion_id,
            'slug' => $request->slug,
            'texto' => $request->texto,
            'idioma' => $request->idioma,
            'hablante' => $request->input('hablante') ?: null,
            'audio_url' => $request->input('audio_url'),
            'modo' => $request->input('modo', 'resumen'),
        ]);

        $this->recordActivity($transcripcion, 'transcription_saved');

        broadcast(new TranscripcionCreada($transcripcion, $request->slug))->toOthers();

        return response()->json([
            'success' => true,
            'data' => $transcripcion,
        ]);
    }

    public function grabarSesion(Request $request)
    {
        $request->validate([
            'sesion_id' => ['required', 'integer'],
            'slug' => ['required', 'string'],
            'grabacion' => ['required', 'file'],
        ]);

        $sesion = Sesion::where('id', $request->sesion_id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $path = $request->file('grabacion')->storeAs(
            'media/transmisiones/' . Str::slug($request->slug),
            $request->file('grabacion')->getClientOriginalName(),
            'public'
        );

        $publicUrl = Storage::disk('public')->url($path);
        $url = preg_replace('#^/storage#', '', parse_url($publicUrl, PHP_URL_PATH) ?: $publicUrl);

        $sesion->grabacion_url = $url;
        $sesion->save();

        SessionUsageEvent::create([
            'user_id' => $sesion->user_id,
            'sesion_id' => $sesion->id,
            'slug' => $sesion->slug,
            'action' => 'recording_saved',
            'metadata' => [
                'audio_url' => $url,
                'storage_path' => $path,
                'occurred_at' => now()->toIso8601String(),
            ],
            'occurred_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'path' => $path,
            'url' => $url,
        ]);
    }

    public function descargar($slug, $tipo, $idioma = null)
    {
        $baseQuery = Transcripcion::where('slug', $slug)
            ->whereHas('sesion', fn ($query) => $query->where('user_id', auth()->id()))
            ->when($idioma, fn ($query) => $query->where('idioma', $idioma));

        if ($tipo === 'texto') {
            $contenido = (clone $baseQuery)->latest()->get()->pluck('texto')->filter()->implode("\n\n");

            return response($contenido, 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $slug . '.txt"',
            ]);
        }

        // Antes esto solo miraba el ULTIMO fragmento transcripto (->latest()->first()), asi
        // que "Descargar audio" nunca entregaba mas que unos segundos de audio, cuando
        // llegaba a tener algo. Ahora se traen TODOS los fragmentos de esa sesion/idioma en
        // orden cronologico y se arma el audio completo.
        $segments = (clone $baseQuery)->oldest()->get();

        $audioFiles = [];
        foreach ($segments as $segment) {
            if (! $segment->audio_url) {
                continue;
            }

            $diskPath = $this->resolvePublicDiskPath($segment->audio_url);
            if ($diskPath && Storage::disk('public')->exists($diskPath)) {
                $audioFiles[] = Storage::disk('public')->path($diskPath);
            }
        }

        if ($audioFiles === []) {
            // Sesion sin ningun fragmento de audio guardado -- ya sea una sesion vieja de
            // antes de este cambio, o simplemente no se llego a guardar nada. Se mantiene el
            // fallback anterior (grabacion completa subida aparte a la sesion, si existe).
            $sesion = Sesion::where('slug', $slug)
                ->where('user_id', auth()->id())
                ->first();

            $audioUrl = $sesion?->grabacion_url;

            if ($audioUrl) {
                $diskPath = $this->resolvePublicDiskPath($audioUrl);

                if ($diskPath && Storage::disk('public')->exists($diskPath)) {
                    return Storage::disk('public')->download($diskPath, basename($diskPath));
                }

                if (Str::startsWith($audioUrl, ['http://', 'https://'])) {
                    return redirect()->away($audioUrl);
                }
            }

            abort(404, 'No hay audio disponible.');
        }

        $extension = pathinfo($audioFiles[0], PATHINFO_EXTENSION) ?: 'mp3';
        $filename = $slug . '-' . ($idioma ?? 'audio') . '.' . $extension;

        if (count($audioFiles) === 1) {
            // Un solo fragmento: no hace falta ffmpeg, se descarga directo.
            return response()->download($audioFiles[0], $filename);
        }

        return $this->concatenateAudioFiles($audioFiles, $extension, $filename, $slug);
    }

    /**
     * Une varios fragmentos de audio del mismo codec en un solo archivo, via el demuxer
     * "concat" de ffmpeg con -c copy (sin recodificar: rapido y sin perdida de calidad,
     * siempre que todos los fragmentos compartan formato -- asi se generan hoy, todos WebM/
     * Opus para la voz original o todos MP3 para la traduccion).
     */
    private function concatenateAudioFiles(array $audioFiles, string $extension, string $filename, string $slug)
    {
        $listPath = tempnam(sys_get_temp_dir(), 'spikia_concat_') . '.txt';
        $outputPath = tempnam(sys_get_temp_dir(), 'spikia_audio_') . '.' . $extension;
        @unlink($outputPath); // tempnam ya crea el archivo vacio; ffmpeg -y lo pisa igual, pero mejor no dejarlo a medio crear

        $listContent = collect($audioFiles)
            ->map(fn ($path) => "file '" . str_replace("'", "'\\''", $path) . "'")
            ->implode("\n");
        file_put_contents($listPath, $listContent);

        $ffmpegBinary = (string) config('spikia.ffmpeg_binary', 'ffmpeg');
        $process = new \Symfony\Component\Process\Process([
            $ffmpegBinary, '-y', '-f', 'concat', '-safe', '0', '-i', $listPath, '-c', 'copy', $outputPath,
        ]);
        $process->setTimeout(60);
        $process->run();

        @unlink($listPath);

        if (! $process->isSuccessful() || ! file_exists($outputPath) || filesize($outputPath) === 0) {
            \Illuminate\Support\Facades\Log::error('No se pudo unir el audio de la sesion con ffmpeg.', [
                'slug' => $slug,
                'fragmentos' => count($audioFiles),
                'error' => $process->getErrorOutput(),
            ]);
            @unlink($outputPath);
            abort(500, 'No se pudo generar el audio completo de la sesion.');
        }

        return response()->download($outputPath, $filename)->deleteFileAfterSend(true);
    }

    private function resolvePublicDiskPath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = str_replace('\\', '/', trim((string) $path));
        $path = preg_replace('#^https?://[^/]+#', '', $path);
        $path = ltrim($path, '/');

        foreach (['storage/', 'public/', '/storage/', '/public/'] as $prefix) {
            $path = Str::startsWith($path, ltrim($prefix, '/'))
                ? Str::after($path, ltrim($prefix, '/'))
                : $path;
        }

        if (Str::startsWith($path, 'media/')) {
            return $path;
        }

        if (Str::startsWith($path, 'traducciones/')) {
            return $path;
        }

        if (Str::startsWith($path, 'transmisiones/')) {
            return 'media/' . $path;
        }

        return $path !== '' ? $path : null;
    }

    private function recordActivity(Transcripcion $transcripcion, string $action): void
    {
        $sesion = $transcripcion->sesion;

        if (! $sesion) {
            return;
        }

        SessionUsageEvent::create([
            'user_id' => $transcripcion->user_id ?: $sesion->user_id,
            'sesion_id' => $sesion->id,
            'slug' => $sesion->slug,
            'action' => $action,
            'metadata' => [
                'transcripcion_id' => $transcripcion->id,
                'idioma' => $transcripcion->idioma,
                'modo' => $transcripcion->modo,
                'texto' => Str::limit((string) $transcripcion->texto, 500),
                'audio_url' => $transcripcion->audio_url,
                'occurred_at' => now()->toIso8601String(),
            ],
            'occurred_at' => now(),
        ]);
    }
}
