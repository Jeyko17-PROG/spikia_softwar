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
        ]);

        $transcripcion = Transcripcion::create([
            'user_id' => auth()->id(),
            'sesion_id' => $request->sesion_id,
            'slug' => $request->slug,
            'texto' => $request->texto,
            'idioma' => $request->idioma,
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
        $transcripciones = Transcripcion::where('slug', $slug)
            ->whereHas('sesion', fn ($query) => $query->where('user_id', auth()->id()))
            ->when($idioma, fn ($query) => $query->where('idioma', $idioma))
            ->latest()
            ->get();

        if ($tipo === 'texto') {
            $contenido = $transcripciones->pluck('texto')->filter()->implode("\n\n");

            return response($contenido, 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $slug . '.txt"',
            ]);
        }

        $audioUrl = $transcripciones->first()?->audio_url;

        if (! $audioUrl) {
            $sesion = Sesion::where('slug', $slug)
                ->where('user_id', auth()->id())
                ->first();

            $audioUrl = $sesion?->grabacion_url;
        }

        if (! $audioUrl) {
            abort(404, 'No hay audio disponible.');
        }

        $diskPath = $this->resolvePublicDiskPath($audioUrl);

        if ($diskPath && Storage::disk('public')->exists($diskPath)) {
            return Storage::disk('public')->download($diskPath, basename($diskPath));
        }

        if (Str::startsWith($audioUrl, ['http://', 'https://'])) {
            return redirect()->away($audioUrl);
        }

        abort(404, 'El archivo de audio no existe en storage.');
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
