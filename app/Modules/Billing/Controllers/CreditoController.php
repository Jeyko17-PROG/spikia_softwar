<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreditoController extends Controller
{
    public function consume(Request $request)
    {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }

            if (method_exists($user, 'hasUnlimitedCredits') && $user->hasUnlimitedCredits()) {
                return response()->json([
                    'success' => true,
                    'unlimited' => true,
                    'used' => (int) ($user->credit_used ?? 0),
                    'limit' => null,
                    'remaining' => null,
                    'percent' => 0,
                ]);
            }

            $limit = (int) ($user->credit_limit ?? 100);
            $used = (int) ($user->credit_used ?? 0);

            if ($used >= $limit) {
                return response()->json(['message' => 'Sin creditos disponibles.'], 422);
            }

            $user->forceFill([
                'credit_used' => $used + 1,
            ])->save();

            return response()->json([
                'success' => true,
                'used' => $user->credit_used,
                'limit' => $limit,
                'remaining' => $user->creditos_restantes,
                'percent' => $user->creditos_porcentaje,
            ]);
        } catch (Throwable $e) {
            Log::error('Error consumiendo credito', [
                'user_id' => optional($request->user())->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No fue posible consumir un credito.',
            ], 500);
        }
    }
}