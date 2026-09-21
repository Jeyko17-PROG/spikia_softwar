<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Sesion;
use App\Models\SessionUsageEvent;
use App\Models\Video;
use App\Services\LicenseActivationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function index()
    {
        $videos = Video::where('user_id', auth()->id())->latest()->limit(12)->get();
        $user = auth()->user();
        $unlimitedCredits = (bool) ($user?->hasUnlimitedCredits() ?? false);
        $licensePlans = config('spikia.license_plans', []);
        $currentPlan = (string) ($user->license_plan ?? config('spikia.default_license_plan', 'free'));

        $creditStats = [
            'unlimited' => $unlimitedCredits,
            'limit' => $unlimitedCredits ? null : (int) ($user->credit_limit ?? 100),
            'used' => (int) ($user->credit_used ?? 0),
            'remaining' => $unlimitedCredits ? null : (int) ($user->creditos_restantes ?? 100),
            'percent' => $unlimitedCredits ? 0 : (int) ($user->creditos_porcentaje ?? 0),
            'half_alert' => $unlimitedCredits ? false : (bool) ($user->credito_mitad_activa ?? false),
        ];

        $sessionsChart = $this->sessionsChartData($user?->id);

        return view('modules.dashboard.index', compact('videos', 'creditStats', 'licensePlans', 'currentPlan', 'sessionsChart'));
    }

    public function activateLicense(Request $request, LicenseActivationService $licenseActivationService): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(array_keys(config('spikia.license_plans', [])))],
            'email' => ['nullable', 'email:rfc'],
            'u_code' => ['nullable', 'string', 'max:6'],
        ]);

        try {
            $result = $licenseActivationService->handle($user, $data);
        } catch (\Throwable $e) {
            return back()->with('error', 'Error al enviar correo: ' . $e->getMessage());
        }

        return back()->with($result['type'], $result['message']);
    }

    public function activateDemo(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $baseSlug = 'demo-es-en-' . $user->id;
        // withTrashed(): el slug tiene UNIQUE a nivel de BD y una demo archivada (soft-deleted)
        // sigue ocupando la fila fisicamente - sin esto, reactivar la demo de este usuario
        // chocaria contra esa fila archivada y el insert fallaria.
        $slug = Sesion::withTrashed()->where('user_id', $user->id)->where('slug', $baseSlug)->exists()
            ? $baseSlug . '-' . Str::lower(Str::random(6))
            : $baseSlug;
        $expiresAt = now()->addMinutes((int) config('spikia.demo_duration_minutes', 20));

        // La Demo rapida siempre debe salir con el glosario "Spikia Glosario" ya cargado,
        // para que las traducciones de la demo usen su terminologia sin que el usuario
        // tenga que asignarlo a mano cada vez. Antes esto solo buscaba el glosario y
        // dejaba glosario_id en null si no existia (nunca habia logica en toda la app que
        // lo creara para un usuario nuevo) - con firstOrCreate() se genera solo la primera
        // vez que cada usuario activa la demo.
        $demoGlosarioId = \App\Models\Glosario::firstOrCreate(
            ['user_id' => $user->id, 'titulo' => 'Spikia Glosario'],
            ['idioma' => 'es', 'terminos' => "Spikia\nWhisper\nOpenAI"]
        )->id;

        $sesion = new Sesion();

        // Antes la demo traducia solo a es-ES/en (para acotar costo de la prueba gratis),
        // pero eso dejaba sin contenido real a Subtitulos si el oyente elegia otro idioma
        // del listado (que si muestra todos). Se amplio a 4 idiomas (no los 7 completos de
        // "Nueva sesion") para dar variedad en Subtitulos sin disparar el costo/latencia de
        // traducir cada frase a 7 idiomas simultaneos durante la prueba gratis.
        $demoLanguages = ['en', 'es-ES', 'es-419', 'pt'];

        $sesion->fill([
            'user_id' => $user->id,
            'titulo' => 'Demo Spikia',
            'presentador' => $user->name,
            'fecha_inicio' => now()->toDateString(),
            'hora_inicio' => now()->format('H:i'),
            'hora_fin' => null,
            'glosario_id' => $demoGlosarioId,
            'idioma_activo' => 'es-ES',
            'idiomas' => $demoLanguages,
            'slug' => $slug,
            'demo_expires_at' => $expiresAt,
        ]);
        $sesion->save();
        $this->recordSessionUsage($sesion, 'demo_activated');

        session()->put("spikia.demo_expires_at.$slug", $expiresAt->toIso8601String());

        return redirect()->route('sesion.master', $sesion->slug)
            ->with('status', 'Sesion demo creada con todos los idiomas habilitados.');
    }

    public function metrics(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'sessions' => $this->sessionsChartData($user?->id),
        ]);
    }

    private function sessionsChartData(?int $userId): array
    {
        if (! $userId) {
            return [];
        }

        $months = collect(range(0, 5))->map(
            fn (int $offset) => Carbon::now()->startOfMonth()->subMonths(5 - $offset)
        );

        $start = $months->first()->copy()->startOfMonth();
        $end = $months->last()->copy()->endOfMonth();
        $driver = DB::connection()->getDriverName();
        $monthExpression = match ($driver) {
            'sqlite' => "strftime('%Y-%m', created_at)",
            'pgsql' => "to_char(created_at, 'YYYY-MM')",
            default => "DATE_FORMAT(created_at, '%Y-%m')",
        };

        $counts = SessionUsageEvent::query()
            ->where('user_id', $userId)
            ->whereBetween('occurred_at', [$start, $end])
            ->selectRaw(str_replace('created_at', 'occurred_at', $monthExpression) . ' as month_key, COUNT(*) as aggregate')
            ->groupBy('month_key')
            ->pluck('aggregate', 'month_key');

        if ($counts->sum() === 0) {
            $counts = Sesion::query()
                ->where('user_id', $userId)
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw("{$monthExpression} as month_key, COUNT(*) as aggregate")
                ->groupBy('month_key')
                ->pluck('aggregate', 'month_key');
        }

        return $months->map(function (Carbon $month) use ($counts) {
            $key = $month->format('Y-m');

            return [
                'key' => $key,
                'label' => $month->locale('es')->translatedFormat('M'),
                'count' => (int) ($counts[$key] ?? 0),
            ];
        })->all();
    }

    private function recordSessionUsage(Sesion $sesion, string $action): void
    {
        try {
            SessionUsageEvent::create([
                'user_id' => $sesion->user_id,
                'sesion_id' => $sesion->id,
                'slug' => $sesion->slug,
                'action' => $action,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable) {
            //
        }
    }
}
