@extends('layouts.spikia')

@section('title', 'Acceso a actividad | Spikia')

@section('content')
<div class="flex min-h-screen items-center justify-center bg-[#050505] px-6 py-12 text-white">
    <div class="w-full max-w-lg rounded-[2.5rem] border border-white/10 bg-zinc-950/90 p-8 shadow-[0_25px_80px_rgba(0,0,0,0.55)] backdrop-blur-xl">
        <div class="mb-6 flex items-center gap-4">
            <img src="{{ asset('storage/media/images/spikia-15.png') }}" alt="Spikia" class="h-12 w-auto">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Acceso restringido</p>
                <h1 class="text-3xl font-black italic tracking-tighter">Actividad</h1>
            </div>
        </div>

        <p class="text-sm leading-6 text-zinc-300">
            Esta sección está protegida. Si no eres el correo administrador, escribe el PIN de 4 dígitos para continuar.
        </p>

        @if ($errors->has('pin'))
            <div class="mt-5 rounded-2xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                {{ $errors->first('pin') }}
            </div>
        @endif

        <form method="POST" action="{{ route('actividad.pin.verify') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="pin" class="mb-2 ml-1 block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Pin de acceso</label>
                <input
                    id="pin"
                    name="pin"
                    type="password"
                    inputmode="numeric"
                    maxlength="4"
                    autocomplete="one-time-code"
                    required
                    autofocus
                    class="w-full rounded-2xl border border-white/10 bg-black/70 px-5 py-4 text-center text-3xl font-black tracking-[0.6em] text-white outline-none transition placeholder:text-zinc-700 focus:border-neonBlue focus:shadow-[0_0_20px_rgba(79,252,255,0.18)]"
                    placeholder="0000"
                >
            </div>

            <button type="submit" class="w-full rounded-2xl bg-white px-5 py-4 text-sm font-black uppercase tracking-[0.35em] text-black transition hover:bg-neonBlue hover:text-white">
                Entrar a actividad
            </button>
        </form>
    </div>
</div>
@endsection
