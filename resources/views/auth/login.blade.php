<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login | Spikia</title>
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
    <div class="relative w-full max-w-sm p-[2px] rounded-2xl bg-gradient-to-r from-neonBlue via-spikiaPurple to-neonPink animate-glow">
        <div class="bg-zinc-900/95 rounded-2xl px-7 py-7 backdrop-blur-xl">
            <div class="flex flex-col items-center mb-6">
                <img src="{{ asset('storage/media/images/spikia-15.png') }}" alt="Spikia" class="h-24 mb-2 drop-shadow-[0_0_30px_rgba(124,58,237,0.6)]">
                <h2 class="text-sm text-zinc-400">Iniciar sesion</h2>
                <p class="mt-2 text-center text-[11px] leading-5 text-zinc-500">
                    Entra con tu correo Gmail y continua con tu cuenta de Spikia.
                </p>
            </div>

            @if ($errors->any())
                <div class="mb-3 text-xs bg-red-900/40 border border-red-700 text-red-300 rounded-lg p-2">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf

                <input type="email" name="email" required autofocus
                    placeholder="correo@gmail.com"
                    class="w-full px-4 py-2.5 text-sm bg-black text-white border border-spikiaPurple/40 rounded-xl placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-neonBlue focus:shadow-[0_0_15px_#4ffcff] transition">

                <input type="password" name="password" required
                    placeholder="Contrasena"
                    class="w-full px-4 py-2.5 text-sm bg-black text-white border border-spikiaPurple/40 rounded-xl placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-neonBlue focus:shadow-[0_0_15px_#4ffcff] transition">

                <div class="flex items-center justify-between text-xs text-zinc-400">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="remember">
                        Recordarme
                    </label>

                    <a href="{{ route('password.request') }}" class="text-neonBlue hover:underline">
                        Olvidaste tu contrasena?
                    </a>
                </div>

                <button class="w-full py-2.5 text-sm font-semibold rounded-xl text-black bg-neonBlue shadow-[0_0_25px_#4ffcff] hover:shadow-[0_0_45px_#4ffcff] hover:scale-105 transition-all duration-300">
                    Iniciar sesion
                </button>
            </form>

            <p class="text-center text-xs text-zinc-400 mt-5">
                No tienes cuenta?
                <a href="{{ route('register') }}" class="text-neonPink hover:underline">Registrate</a>
            </p>
        </div>
    </div>
</body>
</html>
