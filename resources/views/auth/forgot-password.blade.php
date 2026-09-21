<x-guest-layout>
    <div class="text-center mb-6">
        <h1 class="text-lg font-semibold text-white">Restablecer contrasena</h1>
        <p class="mt-2 text-sm text-zinc-400">
            Te enviaremos un enlace a tu correo para que puedas crear una nueva contrasena.
        </p>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Correo electronico')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('login') }}" class="inline-flex items-center px-5 py-2.5 text-sm font-semibold rounded-xl border border-white/10 text-zinc-400 hover:text-white hover:border-white/20 bg-black/40 transition-all">
                Volver al login
            </a>

            <x-primary-button>
                Enviar enlace
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
