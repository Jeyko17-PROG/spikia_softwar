<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Spikia | Traducción simultánea</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Tailwind Config -->
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

<body class="min-h-screen flex items-center justify-center 
            bg-gradient-to-br from-black via-zinc-900 to-black">

    <!-- CARD BORDE ANIMADO -->
    <div class="relative w-full max-w-md p-[2px] rounded-2xl 
                bg-gradient-to-r from-neonBlue via-spikiaPurple to-neonPink
                animate-glow">

        <!-- CARD INTERIOR -->
        <div class="bg-zinc-900/95 rounded-2xl px-10 py-10 backdrop-blur-xl text-center">

            <!-- LOGO -->
            <img
                src="{{ asset('storage/media/images/spikia-15.png') }}"
                alt="Spikia"
                class="mx-auto h-32 mb-6 drop-shadow-[0_0_30px_rgba(124,58,237,0.6)]"
            >

            <!-- TEXTO -->
            <p class="text-sm text-zinc-300 mb-8">
                Plataforma de traducción simultánea con inteligencia artificial
            </p>

            <!-- BOTONES -->
            <div class="flex flex-col sm:flex-row gap-5 justify-center">

                <a href="{{ route('login') }}"
                   class="px-8 py-3 rounded-xl text-sm font-semibold text-black
                          bg-neonBlue shadow-[0_0_25px_#4ffcff]
                          hover:shadow-[0_0_45px_#4ffcff]
                          hover:scale-105 transition-all duration-300">
                    Iniciar sesión
                </a>

                <a href="{{ route('register') }}"
                   class="px-8 py-3 rounded-xl text-sm font-semibold text-black
                          bg-neonPink shadow-[0_0_25px_#ff2fa0]
                          hover:shadow-[0_0_45px_#ff2fa0]
                          hover:scale-105 transition-all duration-300">
                    Crear cuenta
                </a>

            </div>

            <!-- FOOTER -->
            <p class="text-xs text-zinc-500 mt-8">
                © {{ date('Y') }} Spikia · Tecnología que conecta idiomas en tiempo real
            </p>

        </div>
    </div>

</body>
</html>
