<?php

namespace Tests\Feature;

use App\Models\Sesion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DashboardAndSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_license_is_applied_immediately(): void
    {
        $user = User::factory()->create([
            'credit_limit' => 250,
            'credit_used' => 0,
        ]);

        $response = $this->actingAs($user)->post(route('dashboard.license.activate'), [
            'plan' => 'free',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'credit_limit' => 100,
            'credit_used' => 0,
            'license_plan' => 'free',
        ]);
    }

    public function test_paid_license_requires_code_then_applies_selected_plan(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'credit_limit' => 100,
            'credit_used' => 0,
        ]);

        $requestCodeResponse = $this->actingAs($user)->post(route('dashboard.license.activate'), [
            'plan' => 'premium',
            'email' => $user->email,
        ]);

        $requestCodeResponse->assertRedirect();
        $this->assertSame('premium', $this->app['session.store']->get('pending_plan'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $this->app['session.store']->get('activation_code'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'credit_limit' => 100,
            'license_plan' => null,
        ]);

        $activationCode = (string) $this->app['session.store']->get('activation_code');

        $activationResponse = $this->actingAs($user)->post(route('dashboard.license.activate'), [
            'plan' => 'premium',
            'u_code' => $activationCode,
        ]);

        $activationResponse->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'credit_limit' => 500,
            'credit_used' => 0,
            'license_plan' => 'premium',
        ]);

        $this->assertNull($this->app['session.store']->get('activation_code'));
        $this->assertNull($this->app['session.store']->get('pending_plan'));
    }

    public function test_dashboard_metrics_returns_aggregated_sessions_contract(): void
    {
        Carbon::setTestNow('2026-04-20 10:00:00');

        try {
            $user = User::factory()->create();

            $this->createSessionAt($user, 'current-a', now()->subDays(1));
            $this->createSessionAt($user, 'current-b', now()->subDays(2));
            $this->createSessionAt($user, 'previous-a', now()->subMonths(2));

            $response = $this->actingAs($user)->getJson(route('dashboard.metrics'));

            $response->assertOk()
                ->assertJsonStructure([
                    'sessions' => [
                        '*' => ['key', 'label', 'count'],
                    ],
                ]);

            $sessions = collect($response->json('sessions'))->keyBy('key');

            $this->assertCount(6, $sessions);
            $this->assertSame(2, $sessions[now()->format('Y-m')]['count']);
            $this->assertSame(1, $sessions[now()->copy()->subMonths(2)->format('Y-m')]['count']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_demo_button_creates_preconfigured_session(): void
    {
        Carbon::setTestNow(now());

        try {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->post(route('dashboard.demo.activate'));

            $response->assertRedirect();

            $this->assertDatabaseHas('sesiones', [
                'user_id' => $user->id,
                'slug' => 'demo-es-en-' . $user->id,
                'titulo' => 'Demo Español e Inglés',
            ]);

            $sesion = Sesion::where('slug', 'demo-es-en-' . $user->id)->where('user_id', $user->id)->firstOrFail();

            $this->assertSame(['es-ES', 'en'], $sesion->idiomas);
            $this->assertSame('es-ES', $sesion->idioma_activo);
            $this->assertNotNull($sesion->demo_expires_at);
            $this->assertSame(20, (int) round(now()->diffInMinutes($sesion->demo_expires_at)));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_demo_master_can_publish_and_transmission_feed_receives_message(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('dashboard.demo.activate'))->assertRedirect();

        $sesion = Sesion::where('slug', 'demo-es-en-' . $user->id)->where('user_id', $user->id)->firstOrFail();

        $this->actingAs($user)->get(route('sesion.master', $sesion->slug))->assertOk();
        $this->get(route('sesion.transmision', $sesion->slug))->assertOk();

        $publishResponse = $this->actingAs($user)->postJson(route('sesiones.mensajes.store', $sesion->slug), [
            'texto' => 'Prueba de transmision demo',
            'idioma' => 'en',
            'tipo' => 'traduccion',
        ]);

        $publishResponse->assertOk()
            ->assertJsonPath('success', true);

        $feedResponse = $this->getJson(route('sesiones.mensajes.feed', $sesion->slug));

        $feedResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'texto' => 'Prueba de transmision demo',
                'idioma' => 'en',
            ]);

        $this->assertDatabaseHas('session_usage_events', [
            'user_id' => $user->id,
            'sesion_id' => $sesion->id,
            'slug' => $sesion->slug,
            'action' => 'master_opened',
        ]);

        Cache::forget('spikia:relay:' . $sesion->slug);
    }

    public function test_support_chat_returns_local_answer(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('soporte.chat'), [
            'message' => 'Necesito activar la licencia',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'answer' => 'La licencia suma tokens segun el plan que elijas. Gratis tiene 100, Media 250 y Premium 500.',
            ]);
    }

    private function createSessionAt(User $user, string $slug, Carbon $createdAt): void
    {
        $sesion = Sesion::create([
            'user_id' => $user->id,
            'titulo' => 'Sesion ' . $slug,
            'slug' => $slug,
            'idiomas' => ['es-ES'],
            'fecha_inicio' => $createdAt->toDateString(),
            'hora_inicio' => $createdAt->format('H:i'),
        ]);

        $sesion->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();
    }
}
