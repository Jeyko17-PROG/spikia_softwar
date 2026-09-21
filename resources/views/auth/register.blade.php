<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro | Spikia</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        spikiaPurple: '#7C3AED',
                        neonBlue: '#4ffcff',
                        neonPink: '#ff2fa0'
                    },
                    boxShadow: {
                        glow: '0 0 40px rgba(124,58,237,0.8)',
                    },
                    animation: {
                        glow: 'glow 3s ease-in-out infinite alternate',
                    },
                    keyframes: {
                        glow: {
                            '0%': { boxShadow: '0 0 20px #7C3AED' },
                            '100%': { boxShadow: '0 0 45px #4ffcff' },
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen flex items-center justify-center bg-gradient-to-br from-black via-zinc-900 to-black text-white">
    <div class="relative w-full max-w-sm p-[2px] rounded-2xl bg-gradient-to-r from-neonPink via-spikiaPurple to-neonBlue animate-glow">
        <div class="bg-zinc-900/95 rounded-2xl px-7 py-7 backdrop-blur-xl">
            <div class="flex flex-col items-center mb-5">
                <img src="{{ asset('storage/media/images/spikia-15.png') }}" alt="Spikia" class="h-20 mb-1 drop-shadow-[0_0_30px_rgba(124,58,237,0.6)]">
                <h2 class="text-sm text-zinc-400">Crear cuenta</h2>
                <p class="mt-2 text-center text-[11px] leading-5 text-zinc-500">
                    Usa tu correo Gmail para registrarte y recibir el codigo de verificacion por email.
                </p>
            </div>

            @if ($errors->any())
                <div class="mb-3 rounded-xl border border-red-700 bg-red-900/40 p-3 text-xs text-red-300">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('register') }}" class="space-y-3">
                @csrf
                <input type="text" name="name" required placeholder="Nombre completo" value="{{ old('name') }}" class="w-full rounded-xl border border-spikiaPurple/40 bg-black px-4 py-2.5 text-sm text-white placeholder:text-zinc-500 transition focus:outline-none focus:ring-2 focus:ring-neonPink focus:shadow-[0_0_15px_#ff2fa0]">

                <input type="email" name="email" required placeholder="tuusuario@gmail.com" value="{{ old('email') }}" class="w-full rounded-xl border border-spikiaPurple/40 bg-black px-4 py-2.5 text-sm text-white placeholder:text-zinc-500 transition focus:outline-none focus:ring-2 focus:ring-neonBlue focus:shadow-[0_0_15px_#4ffcff]">

                <input type="password" name="password" required placeholder="Contrasena" class="w-full rounded-xl border border-spikiaPurple/40 bg-black px-4 py-2.5 text-sm text-white placeholder:text-zinc-500 transition focus:outline-none focus:ring-2 focus:ring-neonPink focus:shadow-[0_0_15px_#ff2fa0]">

                <input type="password" name="password_confirmation" required placeholder="Confirmar contrasena" class="w-full rounded-xl border border-spikiaPurple/40 bg-black px-4 py-2.5 text-sm text-white placeholder:text-zinc-500 transition focus:outline-none focus:ring-2 focus:ring-neonPink focus:shadow-[0_0_15px_#ff2fa0]">

                <button class="mt-2 w-full rounded-xl bg-neonPink py-2.5 text-sm font-semibold text-black shadow-[0_0_25px_#ff2fa0] transition-all duration-300 hover:scale-105 hover:shadow-[0_0_45px_#ff2fa0]">
                    Crear cuenta
                </button>
            </form>

            <p class="mt-4 text-center text-xs text-zinc-400">
                Ya tienes cuenta?
                <a href="{{ route('login') }}" class="text-neonBlue hover:underline">Inicia sesion</a>
            </p>
        </div>
    </div>
</body>
</html>
