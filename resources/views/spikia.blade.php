<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Spikia Panel')</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- AlpineJS (para menú desplegable) -->
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>

    <!-- Tailwind Config -->
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

<body class="min-h-screen bg-gradient-to-br from-black via-zinc-900 to-black text-white">

<div class="flex min-h-screen">

    <!-- SIDEBAR -->
    <aside class="w-64 bg-zinc-950 border-r border-white/10 px-5 py-6">

        <!-- LOGO -->
        <div class="flex items-center gap-3 mb-10">
            <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="h-10">
            <span class="font-bold text-lg tracking-wide">SPIKIA</span>
        </div>

        <!-- MENU -->
        <nav class="space-y-2 text-sm">

            <a href="{{ route('dashboard') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-xl hover:bg-white/5 transition">
                <span>Portada</span>
            </a>

            <a href="{{ route('sesiones.index') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-xl hover:bg-white/5 transition">
                <span>Sesiones</span>
            </a>

            @if(auth()->check() && auth()->user()->email === 'luisgarciab193@gmail.com')
            <a href="{{ route('actividad.index') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-xl hover:bg-white/5 transition">
                <span>Log</span>
            </a>
            @endif

            <a href="{{ route('transcripciones.listado') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-xl hover:bg-white/5 transition">
                <span>Transcripciones</span>
            </a>

            <a href="{{ route('glosarios') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-xl hover:bg-white/5 transition">
                <span>Glosarios</span>
            </a>

            <a href="{{ route('soporte') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-xl hover:bg-white/5 transition">
                <span>Soporte</span>
            </a>

            <!-- LOGOUT -->
            <form method="POST" action="{{ route('logout') }}" class="pt-4 border-t border-white/10 mt-4">
                @csrf
                <button type="submit"
                    class="flex items-center gap-3 w-full px-4 py-2.5 rounded-xl
                           text-red-400 hover:bg-red-500/10 transition">
                    <span>Cerrar sesion</span>
                </button>
            </form>

        </nav>
    </aside>

    <!-- CONTENIDO -->
    <main class="flex-1 p-8">
        @yield('content')
    </main>

</div>

</body>
</html>



