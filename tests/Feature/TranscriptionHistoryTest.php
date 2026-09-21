<?php

namespace Tests\Feature;

use App\Models\Sesion;
use App\Models\Transcripcion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TranscriptionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_transcription_history_supports_filters_and_pagination(): void
    {
        Carbon::setTestNow('2026-04-20 09:00:00');

        try {
            $user = User::factory()->create();

            foreach (range(1, 21) as $index) {
                $sesion = $this->createSession($user, "sesion-{$index}", now()->subMinutes(22 - $index));
                $this->createTranscription($user, $sesion, "detalle {$index}", 'detalle', 'es', now()->subMinutes(22 - $index));
            }

            $targetSession = $this->createSession($user, 'enfoque-detalle', now());
            $this->createTranscription($user, $targetSession, 'frase objetivo detalle', 'detalle', 'es', now());
            $this->createTranscription($user, $targetSession, 'resumen general', 'resumen', 'es', now()->subSecond());

            $pageOne = $this->actingAs($user)->get(route('transcripciones.listado', [
                'modo' => 'detalle',
                'q' => 'objetivo',
            ]));

            $pageOne->assertOk()
                ->assertSee('frase objetivo detalle')
                ->assertDontSee('resumen general');

            $pageTwo = $this->actingAs($user)->get(route('transcripciones.listado', [
                'page' => 2,
            ]));

            $pageTwo->assertOk()
                ->assertSee('Sesion sesion-1')
                ->assertDontSee('Sesion sesion-21');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_transcription_download_routes_keep_text_and_audio_behavior(): void
    {
        $user = User::factory()->create();
        $sesion = $this->createSession($user, 'descargas', now());

        $this->createTranscription($user, $sesion, 'Primera linea', 'resumen', 'es', now()->subMinute(), null);
        $this->createTranscription($user, $sesion, 'Segunda linea', 'resumen', 'es', now(), null);

        Storage::fake('public');
        Storage::disk('public')->put('media/demo-audio.mp4', 'demo audio bytes');

        $sesion->forceFill([
            'grabacion_url' => '/storage/media/demo-audio.mp4',
        ])->saveQuietly();

        $textResponse = $this->actingAs($user)->get(route('transcripcion.descargar', [
            'slug' => $sesion->slug,
            'tipo' => 'texto',
            'idioma' => 'es',
        ]));

        $textResponse->assertOk();
        $this->assertStringContainsString('Primera linea', $textResponse->getContent());
        $this->assertStringContainsString('Segunda linea', $textResponse->getContent());

        $audioResponse = $this->actingAs($user)->get(route('transcripcion.descargar', [
            'slug' => $sesion->slug,
            'tipo' => 'audio',
            'idioma' => 'es',
        ]));

        $audioResponse->assertOk();
        $this->assertSame('demo audio bytes', $audioResponse->streamedContent());
    }

    private function createSession(User $user, string $slug, Carbon $timestamp): Sesion
    {
        $sesion = Sesion::create([
            'user_id' => $user->id,
            'titulo' => 'Sesion ' . $slug,
            'slug' => $slug,
            'idiomas' => ['es'],
            'fecha_inicio' => $timestamp->toDateString(),
            'hora_inicio' => $timestamp->format('H:i'),
        ]);

        $sesion->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->saveQuietly();

        return $sesion;
    }

    private function createTranscription(
        User $user,
        Sesion $sesion,
        string $texto,
        string $modo,
        string $idioma,
        Carbon $timestamp,
        ?string $audioUrl = null
    ): Transcripcion {
        $transcripcion = Transcripcion::create([
            'user_id' => $user->id,
            'sesion_id' => $sesion->id,
            'slug' => $sesion->slug,
            'texto' => $texto,
            'idioma' => $idioma,
            'audio_url' => $audioUrl,
            'modo' => $modo,
        ]);

        $transcripcion->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->saveQuietly();

        return $transcripcion;
    }
}
