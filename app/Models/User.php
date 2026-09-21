<?php

namespace App\Models;

use App\Notifications\VerificationCodeNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'license_plan',
        'credit_limit',
        'credit_used',
        'credit_half_alerted_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'credit_half_alerted_at' => 'datetime',
            'verification_code_expires_at' => 'datetime',
        ];
    }

    public function sesiones()
    {
        return $this->hasMany(Sesion::class);
    }

    public function glosarios()
    {
        return $this->hasMany(Glosario::class);
    }

    public function videos()
    {
        return $this->hasMany(Video::class);
    }

    public function transcripciones()
    {
        return $this->hasMany(Transcripcion::class);
    }

    public function generateVerificationCode(): string
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'verification_code_hash' => Hash::make($code),
            'verification_code_expires_at' => now()->addMinutes(15),
        ])->save();

        return $code;
    }

    public function getEmailForVerification(): string
    {
        return $this->email;
    }

    public function hasVerifiedEmail(): bool
    {
        return ! is_null($this->email_verified_at);
    }

    public function markEmailAsVerified(): bool
    {
        if ($this->hasVerifiedEmail()) {
            return false;
        }

        return $this->forceFill([
            'email_verified_at' => now(),
        ])->save();
    }

    public function sendEmailVerificationNotification(): void
    {
        $code = $this->generateVerificationCode();
        $this->sendVerificationCodeEmail($code);
    }

    public function sendVerificationCodeEmail(string $code): void
    {
        $this->notify(new VerificationCodeNotification($code));
    }

    public function hasUnlimitedCredits(): bool
    {
        return strtolower((string) $this->email) === 'luisgarciab193@gmail.com';
    }

    public function verifyWithCode(string $code): bool
    {
        if (! $this->verification_code_hash || ! $this->verification_code_expires_at) {
            return false;
        }

        if (now()->greaterThan($this->verification_code_expires_at)) {
            return false;
        }

        if (! Hash::check($code, $this->verification_code_hash)) {
            return false;
        }

        $this->forceFill([
            'email_verified_at' => now(),
            'verification_code_hash' => null,
            'verification_code_expires_at' => null,
        ])->save();

        return true;
    }

    public function getCreditosRestantesAttribute(): int
    {
        $limit = (int) ($this->credit_limit ?? 100);
        $used = (int) ($this->credit_used ?? 0);

        return max(0, $limit - $used);
    }

    public function getCreditosPorcentajeAttribute(): int
    {
        $limit = max(1, (int) ($this->credit_limit ?? 100));
        $used = (int) ($this->credit_used ?? 0);

        return (int) min(100, round(($used / $limit) * 100));
    }

    public function getCreditoMitadActivaAttribute(): bool
    {
        $limit = (int) ($this->credit_limit ?? 100);
        $used = (int) ($this->credit_used ?? 0);

        return $used >= (int) ceil($limit / 2);
    }
}
