<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VerificationCodeController extends Controller
{
    public function show(Request $request): RedirectResponse|View
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        return view('auth.verify-code', [
            'destination' => $request->user()->email,
            'channel' => 'email',
            'expiresAt' => $request->user()->verification_code_expires_at,
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        if ($user->verifyWithCode($data['code'])) {
            event(new Verified($user));

            return redirect()->intended(route('dashboard', absolute: false))
                ->with('status', 'verification-code-verified');
        }

        return back()->withErrors([
            'code' => 'El codigo es invalido o ya expiro.',
        ]);
    }

    public function resend(Request $request): RedirectResponse
    {
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-code-sent-email');
    }
}
