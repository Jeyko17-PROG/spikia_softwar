<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica tu correo | Spikia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        spikiaPurple: '#7C3AED',
                        neonBlue: '#4ffcff',
                        neonPink: '#ff2fa0'
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-gradient-to-br from-black via-zinc-950 to-black text-white">
    <div class="min-h-screen flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-lg overflow-hidden rounded-[2rem] border border-white/10 bg-zinc-900/80 shadow-[0_0_60px_rgba(0,0,0,0.45)] backdrop-blur-xl">
            <div class="border-b border-white/5 bg-[radial-gradient(circle_at_top,rgba(124,58,237,0.35),transparent_55%)] px-8 py-8">
                <div class="flex items-center gap-4">
                    <img src="{{ asset('storage/media/images/spikia-15.png') }}" alt="Spikia" class="h-14 w-auto drop-shadow-[0_0_18px_rgba(124,58,237,0.7)]">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Verificacion por correo</p>
                        <h1 class="text-3xl font-black italic tracking-tighter">Confirma tu acceso</h1>
                    </div>
                </div>
            </div>

            <div class="px-8 py-8">
                <p class="text-sm leading-6 text-zinc-300">
                    Te enviamos un codigo de 6 digitos a <span class="font-semibold text-white">{{ $destination ?? 'tu correo Gmail' }}</span>.
                    Ingresalo para activar tu cuenta. El codigo expira en 15 minutos.
                </p>

                @if (session('status') === 'verification-code-sent-email')
                    <div class="mt-5 rounded-2xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                        Enviamos un nuevo codigo a tu correo.
                    </div>
                @endif

                @if (session('status') === 'verification-code-send-failed')
                    <div class="mt-5 rounded-2xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                        La cuenta se creo, pero no pudimos enviar el codigo al primer intento. Puedes reenviarlo por correo.
                    </div>
                @endif

                @error('code')
                    <div class="mt-5 rounded-2xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                        {{ $message }}
                    </div>
                @enderror

                <form method="POST" action="{{ route('verification.code') }}" class="mt-6 space-y-4">
                    @csrf
                    <div>
                        <label for="code" class="mb-3 ml-1 block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Codigo de 6 digitos</label>
                        <input id="code" name="code" type="text" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required autofocus
                            class="w-full rounded-2xl border border-white/10 bg-black/70 px-5 py-4 text-center text-2xl font-black tracking-[0.45em] text-white outline-none transition focus:border-neonBlue focus:shadow-[0_0_20px_rgba(79,252,255,0.18)]"
                            placeholder="000000">
                    </div>

                    <button type="submit" class="w-full rounded-2xl bg-white px-5 py-4 text-sm font-black uppercase tracking-[0.35em] text-black transition hover:bg-neonBlue hover:text-white">
                        Verificar codigo
                    </button>
                </form>

                <div class="mt-8 rounded-[1.5rem] border border-white/10 bg-white/5 p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">No llego el codigo</p>
                    <p class="mt-2 text-sm text-zinc-300">
                        Puedes reenviarlo al mismo correo Gmail sin crear otra cuenta.
                    </p>

                    <div class="mt-4 grid gap-3">
                        <form method="POST" action="{{ route('verification.send') }}" class="flex-1">
                            @csrf
                            <button
                                type="submit"
                                data-resend-button
                                disabled
                                class="w-full rounded-2xl bg-spikiaPurple px-5 py-4 text-sm font-black uppercase tracking-[0.35em] text-white transition hover:bg-neonPink disabled:cursor-not-allowed disabled:bg-white/10 disabled:text-zinc-500">
                                <span data-resend-label>Reenviar codigo</span>
                                <span class="ml-2 text-[10px] font-semibold tracking-[0.25em] text-white/70" data-resend-timer>(40s)</span>
                            </button>
                        </form>

                        <form method="POST" action="{{ route('logout') }}" class="flex-1">
                            @csrf
                            <button type="submit" class="w-full rounded-2xl border border-white/10 bg-black/40 px-5 py-4 text-sm font-black uppercase tracking-[0.35em] text-zinc-400 transition hover:text-red-300">
                                Cerrar sesion
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        (() => {
            const button = document.querySelector('[data-resend-button]');
            const timerLabel = document.querySelector('[data-resend-timer]');
            const label = document.querySelector('[data-resend-label]');

            if (!button || !timerLabel || !label) {
                return;
            }

            let seconds = 40;

            const tick = () => {
                timerLabel.textContent = `(${seconds}s)`;

                if (seconds <= 0) {
                    button.disabled = false;
                    timerLabel.remove();
                    label.textContent = 'Reenviar codigo';
                    return;
                }

                seconds -= 1;
                window.setTimeout(tick, 1000);
            };

            tick();
        })();
    </script>
</body>
</html>
