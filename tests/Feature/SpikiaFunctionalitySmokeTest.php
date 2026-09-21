<?php

namespace Tests\Feature;

use App\Models\Sesion;
use App\Models\User;
use App\Services\MeetingBotClient;
use App\Services\QrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SpikiaFunctionalitySmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_qr_service_generates_svg_for_transmission_url(): void
    {
        $svg = app(QrCodeService::class)->transmissionSvg(
            'mi-sesion',
            'https://spikia.test/sesiones/mi-sesion/transmision'
        );

        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_sessions_index_renders_with_qr_and_lists_session(): void
    {
        $user = User::factory()->create();
        Sesion::create([
            'user_id' => $user->id,
            'titulo' => 'Sesion QR',
            'slug' => 'sesion-qr-test',
            'idiomas' => ['es-ES'],
        ]);

        $response = $this->actingAs($user)->get(route('sesiones.index'));

        $response->assertOk();
        $response->assertSee('Sesion QR');
        $response->assertSee('<svg', false);
    }

    public function test_can_create_session_with_meeting_link_and_bot_language(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('sesiones.store'), [
            'titulo' => 'Sesion con Meet',
            'zoom_link' => 'https://meet.google.com/abc-defg-hij',
            'meeting_bot_source_lang' => 'es-ES',
        ]);

        $response->assertRedirect(route('sesiones.index'));

        $this->assertDatabaseHas('sesiones', [
            'user_id' => $user->id,
            'titulo' => 'Sesion con Meet',
            'zoom_link' => 'https://meet.google.com/abc-defg-hij',
            'meeting_bot_source_lang' => 'es-ES',
        ]);
    }

    public function test_master_and_transmision_pages_render(): void
    {
        $user = User::factory()->create();
        $sesion = Sesion::create([
            'user_id' => $user->id,
            'titulo' => 'Sesion Master',
            'slug' => 'sesion-master-test',
            'idiomas' => ['es-ES'],
        ]);

        $this->actingAs($user)->get(route('sesion.master', $sesion->slug))->assertOk();
        $this->get(route('sesion.transmision', $sesion->slug))->assertOk();
    }

    public function test_extend_time_uses_ten_minute_grace_window(): void
    {
        Carbon::setTestNow('2026-08-05 10:00:00');

        try {
            $user = User::factory()->create();
            $sesion = Sesion::create([
                'user_id' => $user->id,
                'titulo' => 'Sesion Vencida',
                'slug' => 'sesion-vencida-test',
                'idiomas' => ['es-ES'],
                'fecha_inicio' => now()->toDateString(),
                'hora_inicio' => now()->subHour()->format('H:i'),
                'hora_fin' => now()->subMinutes(5)->format('H:i'),
            ]);

            // Visitar el listado dispara cleanupExpiredSessions().
            $this->actingAs($user)->get(route('sesiones.index'))->assertOk();

            $sesion->refresh();
            $this->assertNotNull($sesion->extension_deadline_at);
            $this->assertEqualsWithDelta(
                600,
                now()->diffInSeconds($sesion->extension_deadline_at),
                2
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_meeting_bot_start_stop_status_flow_with_mocked_worker(): void
    {
        config(['spikia.features.meeting_bot' => true]);

        $this->mock(MeetingBotClient::class, function ($mock) {
            $mock->shouldReceive('join')->once()->andReturn(['status' => 'joining']);
            $mock->shouldReceive('status')->andReturn(['status' => 'active', 'error' => null]);
            $mock->shouldReceive('leave')->once()->andReturn(['status' => 'stopped']);
        });

        $user = User::factory()->create();
        $sesion = Sesion::create([
            'user_id' => $user->id,
            'titulo' => 'Sesion Bot',
            'slug' => 'sesion-bot-test',
            'idiomas' => ['es-ES'],
            'zoom_link' => 'https://meet.google.com/abc-defg-hij',
        ]);

        $start = $this->actingAs($user)->post(route('sesiones.meeting-bot.start', $sesion->slug));
        $start->assertOk()->assertJsonPath('success', true);

        $sesion->refresh();
        $this->assertNotNull($sesion->bot_ingest_token);
        $this->assertSame('joining', $sesion->meeting_bot_status);

        $status = $this->actingAs($user)->getJson(route('sesiones.meeting-bot.status', $sesion->slug));
        $status->assertOk()->assertJsonPath('status', 'active');

        $stop = $this->actingAs($user)->post(route('sesiones.meeting-bot.stop', $sesion->slug));
        $stop->assertOk()->assertJsonPath('success', true);

        $sesion->refresh();
        $this->assertSame('stopped', $sesion->meeting_bot_status);
        $this->assertNull($sesion->bot_ingest_token);
    }

    public function test_livekit_publisher_token_requires_human_live_mode_and_config(): void
    {
        config(['spikia.features.sign_avatar' => true]);
        $user = User::factory()->create();
        $sesion = Sesion::create([
            'user_id' => $user->id,
            'titulo' => 'Sesion LiveKit',
            'slug' => 'sesion-livekit-test',
            'idiomas' => ['es-ES'],
            'has_sign_avatar' => true,
            'avatar_mode' => 'human_live',
        ]);

        // Sin LIVEKIT_URL/API_KEY configurados: 503, no un 500 crudo.
        config(['spikia.livekit' => ['url' => '', 'api_key' => '', 'api_secret' => '']]);
        $this->actingAs($user)
            ->get(route('sesiones.livekit-token.publisher', $sesion->slug))
            ->assertStatus(503);

        // Con LiveKit configurado, devuelve token + url.
        config(['spikia.livekit' => ['url' => 'wss://fake.livekit.cloud', 'api_key' => 'APIkey', 'api_secret' => 'secret123']]);
        $response = $this->actingAs($user)
            ->get(route('sesiones.livekit-token.publisher', $sesion->slug));
        $response->assertOk()->assertJsonStructure(['url', 'token']);
        $this->assertSame('wss://fake.livekit.cloud', $response->json('url'));

        $token = $response->json('token');
        [$headerB64, $payloadB64, ] = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
        $this->assertSame('sesion-livekit-test', $payload['video']['room']);
        $this->assertTrue($payload['video']['canPublish']);

        // Sin avatar_mode = human_live: 404.
        $sesion->update(['avatar_mode' => '3d']);
        $this->actingAs($user)
            ->get(route('sesiones.livekit-token.publisher', $sesion->slug))
            ->assertStatus(404);
    }

    public function test_livekit_viewer_token_is_public_and_subscribe_only(): void
    {
        config([
            'spikia.features.sign_avatar' => true,
            'spikia.livekit' => ['url' => 'wss://fake.livekit.cloud', 'api_key' => 'APIkey', 'api_secret' => 'secret123'],
        ]);
        $sesion = Sesion::create([
            'user_id' => User::factory()->create()->id,
            'titulo' => 'Sesion LiveKit Viewer',
            'slug' => 'sesion-livekit-viewer-test',
            'idiomas' => ['es-ES'],
            'has_sign_avatar' => true,
            'avatar_mode' => 'human_live',
        ]);

        // Publico: no requiere sesion autenticada.
        $response = $this->get(route('sesiones.livekit-token.viewer', $sesion->slug));
        $response->assertOk()->assertJsonStructure(['url', 'token']);

        $token = $response->json('token');
        [, $payloadB64, ] = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
        $this->assertSame('sesion-livekit-viewer-test', $payload['video']['room']);
        $this->assertFalse($payload['video']['canPublish']);
        $this->assertTrue($payload['video']['canSubscribe']);
    }

    public function test_meeting_bot_ingest_requires_valid_token(): void
    {
        config(['spikia.features.meeting_bot' => true]);

        $sesion = Sesion::create([
            'user_id' => User::factory()->create()->id,
            'titulo' => 'Sesion Ingest',
            'slug' => 'sesion-ingest-test',
            'idiomas' => ['es-ES'],
        ]);
        $sesion->forceFill(['bot_ingest_token' => 'token-correcto'])->save();

        $response = $this->postJson(route('sesiones.bot-audio.ingest', $sesion->slug), [], [
            'X-Spikia-Bot-Token' => 'token-incorrecto',
        ]);

        $response->assertStatus(401);
    }
}
