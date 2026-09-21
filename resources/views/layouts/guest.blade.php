<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Spikia | Acceso</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/cssAfamily=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen flex items-center justify-center bg-gradient-to-br from-black via-zinc-900 to-black text-white">
        <div class="relative w-full max-w-sm p-[2px] rounded-2xl bg-gradient-to-r from-[#4ffcff] via-[#7C3AED] to-[#ff2fa0] shadow-[0_0_40px_rgba(124,58,237,0.45)]">
            <div class="bg-zinc-900/95 rounded-2xl px-7 py-7 backdrop-blur-xl">
                <div class="flex flex-col items-center mb-6">
                    <a href="/" class="block">
                        <img src="{{ asset('storage/media/images/spikia-15.png') }}" alt="Spikia" class="h-24 mb-2 drop-shadow-[0_0_30px_rgba(124,58,237,0.6)]">
                    </a>
                    <h2 class="text-sm text-zinc-400">Acceso a tu cuenta</h2>
                    <p class="mt-2 text-center text-[11px] leading-5 text-zinc-500">
                        Usa tu correo para entrar o restablecer tu acceso a Spikia.
                    </p>
                </div>

                {{ $slot }}
            </div>
        </div>
    </body>
</html>
