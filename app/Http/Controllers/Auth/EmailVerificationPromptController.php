<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationPromptController extends Controller
{
    /**
     * Display the verification prompt.
     */
    public function __invoke(Request $request): View
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
}