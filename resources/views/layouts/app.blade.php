<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LogixFix</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-gray-950 text-white">

    <div class="min-h-screen">

        {{-- Navbar si tienes una --}}
        @includeIf('layouts.navigation')

        <main class="py-6">
            @yield('content')
        </main>

    </div>

</body>
</html>