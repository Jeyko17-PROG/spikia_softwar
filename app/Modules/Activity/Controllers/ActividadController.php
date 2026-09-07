<?php

namespace App\Modules\Activity\Controllers;

use App\Exports\ActividadExport;
use App\Http\Controllers\Controller;
use App\Models\Sesion;
use App\Models\Transcripcion;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ActividadController extends Controller
{
    public function promptPin()
    {
        if ($this->hasAccess()) {
            return redirect()->route('actividad.index');
        }

        return view('modules.activity.pin');
    }

    public function verifyPin(Request $request)
    {
        $data = $request->validate([
            'pin' => ['required', 'digits:4'],
        ]);

        if ($data['pin'] !== config('spikia.activity_pin', '1234')) {
            return back()->withErrors(['pin' => 'Pin invalido.']);
        }

        session(['actividad_pin_verified' => true]);

        return redirect()->route('actividad.index');
    }

    public function index(Request $request)
    {
        abort_unless($this->hasAccess(), 403);

        $q = trim((string) $request->query('q', ''));
        $sesionesConEstadisticas = $this->buildSesionesConEstadisticas($q, 15);

        $activityTotals = $this->activityTotals($q);

        return view('modules.activity.index', compact('sesionesConEstadisticas', 'activityTotals', 'q'));
    }

    public function exportarExcel(Request $request)
    {
        abort_unless($this->hasAccess(), 403);

        $q = trim((string) $request->query('q', ''));
        $data = $this->buildSesionesConEstadisticas($q, null);

        return Excel::download(new ActividadExport($data), 'archivo-excel-actividad.xlsx');
    }

    private function hasAccess(): bool
    {
        return auth()->check()
            && (
                auth()->user()->email === 'luisgarciab193@gmail.com'
                || (bool) session('actividad_pin_verified', false)
            );
    }

    private function buildSesionesConEstadisticas(string $q = '', ?int $perPage = 15)
    {
        // withTrashed(): una sesion archivada (borrada manualmente o vencida) sigue contando
        // como actividad ocurrida. Antes de SoftDeletes, borrar la sesion la hacia desaparecer
        // de aca sin dejar rastro - justo lo que Registro de Actividad deberia evitar.
        $query = Sesion::withTrashed()
            ->withCount('transcripciones')
            ->with(['transcripciones' => fn ($query) => $query->latest()->limit(1)])
            ->where('user_id', auth()->id())
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';

                $query->where(function ($nested) use ($like) {
                    $nested->where('titulo', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhere('fecha_inicio', 'like', $like)
                        ->orWhere('hora_inicio', 'like', $like)
                        ->orWhere('hora_fin', 'like', $like)
                        ->orWhereHas('transcripciones', fn ($transcripciones) => $transcripciones->where('texto', 'like', $like));
                });
            })
            ->latest();

        $mapSession = function (Sesion $sesion) {
                $inicio = $this->parseDateTime($sesion->fecha_inicio, $sesion->hora_inicio);
                $fin = $this->parseDateTime($sesion->fecha_inicio, $sesion->hora_fin);
                $duracionHoras = null;

                if ($inicio && $fin && $fin->greaterThan($inicio)) {
                    $duracionHoras = round($inicio->diffInMinutes($fin) / 60, 2);
                }

                return [
                    'sesion' => $sesion,
                    'titulo' => $sesion->titulo,
                    'slug' => $sesion->slug,
                    'fecha' => $sesion->fecha_inicio,
                    'hora_inicio' => $sesion->hora_inicio,
                    'hora_fin' => $sesion->hora_fin,
                    'idiomas' => collect(is_array($sesion->idiomas) ? $sesion->idiomas : [])
                        ->filter()
                        ->values()
                        ->implode(', '),
                    'presentador' => auth()->user()->name,
                    'transcripciones_count' => (int) $sesion->transcripciones_count,
                    'duracion_horas' => $duracionHoras,
                    'extra_time_hours' => round(((int) ($sesion->extra_time_minutes ?? 0)) / 60, 2),
                    'extension_count' => (int) ($sesion->extension_count ?? 0),
                    'last_extended_at' => $sesion->last_extended_at,
                    'ultimo_texto' => $sesion->transcripciones->first()?->texto,
                ];
        };

        if ($perPage === null) {
            return $query->get()->map($mapSession);
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $paginator->setCollection($paginator->getCollection()->map($mapSession));

        return $paginator;
    }

    private function activityTotals(string $q = ''): array
    {
        $query = Sesion::withTrashed()->where('user_id', auth()->id())
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';

                $query->where(function ($nested) use ($like) {
                    $nested->where('titulo', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhere('fecha_inicio', 'like', $like)
                        ->orWhereHas('transcripciones', fn ($transcripciones) => $transcripciones->where('texto', 'like', $like));
                });
            });

        $sessionIds = (clone $query)->pluck('id');

        return [
            'sesiones' => $sessionIds->count(),
            'transcripciones' => $sessionIds->isEmpty()
                ? 0
                : Transcripcion::whereIn('sesion_id', $sessionIds)->count(),
        ];
    }

    private function parseDateTime($date, $time): ?Carbon
    {
        if (! $date || ! $time) {
            return null;
        }

        try {
            return Carbon::parse(trim((string) $date . ' ' . (string) $time));
        } catch (\Throwable) {
            return null;
        }
    }
}
