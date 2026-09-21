<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Spikia')</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @stack('head-scripts')
    @stack('styles')
    @vite(request()->routeIs('sesion.master', 'sesion.transmision', 'sesion.movil', 'sesion.avatar', 'sesion.interprete', 'sesion.subtitulos') ? ['resources/css/app.css'] : ['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-gradient-to-br from-black via-zinc-900 to-black text-white">

    <!-- CONTENIDO -->
    @yield('content')

</body>
</html>
