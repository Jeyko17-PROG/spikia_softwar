<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class LicenseActivationService
{
    public function handle(User $user, array $payload): array
    {
        $plans = config('spikia.license_plans', []);
        $defaultPlan = (string) config('spikia.default_license_plan', 'free');
        $planKey = (string) ($payload['plan'] ?? $defaultPlan);
        $plan = $plans[$planKey] ?? ($plans[$defaultPlan] ?? ['credits' => 100, 'label' => 'Gratis']);

        if ($planKey === 'free') {
            $user->forceFill([
                'license_plan' => 'free',
                'credit_limit' => (int) ($plan['credits'] ?? 100),
            ])->save();

            return [
                'type' => 'status',
                'message' => 'Plan gratuito activado con 100 tokens.',
            ];
        }

        $inputCode = trim((string) ($payload['u_code'] ?? ''));

        if ($inputCode === '') {
            $recipient = trim((string) ($payload['email'] ?? $user->email));

            if ($recipient === '') {
                throw new InvalidArgumentException('Debes proporcionar un correo para enviar el código.');
            }

            $generatedCode = (string) random_int(100000, 999999);

            session([
                'activation_code' => $generatedCode,
                'pending_plan' => $planKey,
            ]);

            Mail::raw("Tu código de activación para Spikia es: {$generatedCode}", function ($message) use ($recipient) {
                $message->to($recipient)
                    ->subject('Código de Activación - Spikia');
            });

            return [
                'type' => 'status',
                'message' => 'Código enviado a ' . $recipient,
            ];
        }

        if (hash_equals((string) session('activation_code', ''), $inputCode)) {
            $resolvedPlan = (string) session('pending_plan', $planKey);
            $resolvedConfig = $plans[$resolvedPlan] ?? $plan;

            $user->forceFill([
                'license_plan' => $resolvedPlan,
                'credit_limit' => (int) ($resolvedConfig['credits'] ?? 100),
            ])->save();

            session()->forget(['activation_code', 'pending_plan']);

            return [
                'type' => 'status',
                'message' => 'Licencia activada con éxito.',
            ];
        }

        return [
            'type' => 'error',
            'message' => 'El código ingresado es incorrecto.',
        ];
    }
}
