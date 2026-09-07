<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sesion extends Model
{
    use SoftDeletes;

    protected $table = 'sesiones';

    protected $fillable = [
        'user_id',
        'titulo',
        'presentador',
        'cuenta',
        'fecha_inicio',
        'hora_inicio',
        'hora_fin',
        'zoom_link',
        'idiomas',
        'translation_settings',
        'subtitulos',
        'has_sign_avatar',
        'avatar_mode',
        'avatar_character',
        'avatar_video_url',
        'slug',
        'glosario_id',
        'idioma_activo',
        'demo_expires_at',
        'extra_time_minutes',
        'extension_count',
        'extension_deadline_at',
        'last_extended_at',
        'grabacion_url',
        'cloned_voice_id',
        'voice_consent_at',
        'live_started_at',
        'live_accumulated_seconds',
        'meeting_bot_status',
        'meeting_bot_source_lang',
        // 'bot_ingest_token' se setea explicitamente desde el controlador (Str::random),
        // nunca por mass-assignment: un PATCH malicioso al endpoint de update() no debe poder
        // sobreescribir el token que autentica al worker del bot.
    ];

    protected $casts = [
        'idiomas' => 'array',
        'translation_settings' => 'array',
        'subtitulos' => 'array',
        'has_sign_avatar' => 'boolean',
        'demo_expires_at' => 'datetime',
        'extra_time_minutes' => 'integer',
        'extension_count' => 'integer',
        'extension_deadline_at' => 'datetime',
        'last_extended_at' => 'datetime',
        'voice_consent_at' => 'datetime',
        'live_started_at' => 'datetime',
        'live_accumulated_seconds' => 'integer',
        'meeting_bot_last_heartbeat_at' => 'datetime',
    ];

    /**
     * Segundos totales transcurridos "en vivo": lo acumulado de segmentos anteriores mas lo
     * que lleva corriendo el segmento actual, si sigue en vivo. Es la fuente de verdad que el
     * cronometro del master consulta al cargar/recargar la pagina, para no volver a 00:00:00.
     */
    public function getLiveElapsedSecondsAttribute(): int
    {
        // Resta directa de timestamps Unix a proposito, no Carbon::diffInSeconds(): el signo
        // de diffInSeconds() depende de cual objeto es el receptor vs el argumento y es facil
        // de invertir por error (paso por ahi: devolvia el diff negado y max(0,...) lo tapaba
        // silenciosamente como 0 en vez de fallar ruidoso).
        $running = $this->live_started_at
            ? max(0, now()->timestamp - $this->live_started_at->timestamp)
            : 0;

        return (int) $this->live_accumulated_seconds + (int) $running;
    }

    public function glosario()
    {
        return $this->belongsTo(Glosario::class, 'glosario_id');
    }

    public function transcripciones()
    {
        return $this->hasMany(Transcripcion::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getDemoExpiredAttribute(): bool
    {
        return (bool) $this->demo_expires_at && now()->greaterThanOrEqualTo($this->demo_expires_at);
    }

    // El worker del bot de reunion manda un heartbeat mientras esta activo; si no llega uno
    // reciente asumimos que el proceso murio (crash, VPS caido, etc.) sin haber avisado.
    public function getMeetingBotStaleAttribute(): bool
    {
        // Ojo: si meeting_bot_last_heartbeat_at todavia es null NO es lo mismo que "viejo".
        // Justo despues de que el worker confirma 'active' puede pasar un instante hasta que
        // llegue el primer chunk de audio (el heartbeat solo se actualiza en ingestBotAudio);
        // tratar ese hueco como "stale" marcaba error en la UI aunque el bot recien empezaba.
        return $this->meeting_bot_status === 'active'
            && $this->meeting_bot_last_heartbeat_at
            && now()->diffInSeconds($this->meeting_bot_last_heartbeat_at) > 90;
    }

    public function getDemoRemainingMinutesAttribute(): ?int
    {
        if (! $this->demo_expires_at) {
            return null;
        }

        return max(0, now()->diffInMinutes($this->demo_expires_at, false));
    }

    public function scheduledEndAt(): ?\Illuminate\Support\Carbon
    {
        if (! $this->fecha_inicio || ! $this->hora_fin) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse(trim((string) $this->fecha_inicio . ' ' . (string) $this->hora_fin));
        } catch (\Throwable) {
            return null;
        }
    }

    public function getExtensionGraceExpiresAtAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->extension_deadline_at;
    }

    public function getCanExtendNowAttribute(): bool
    {
        $end = $this->scheduledEndAt();

        return (bool) (
            ! $this->demo_expires_at
            && $end
            && now()->greaterThanOrEqualTo($end)
            && $this->extension_deadline_at
            && now()->lessThanOrEqualTo($this->extension_deadline_at)
        );
    }

    public function getSessionExpiredForDeletionAttribute(): bool
    {
        if ($this->demo_expired) {
            return true;
        }

        $end = $this->scheduledEndAt();

        return (bool) (
            ! $this->demo_expires_at
            && $end
            && $this->extension_deadline_at
            && now()->greaterThan($this->extension_deadline_at)
        );
    }
}
