@extends('layouts.spikia')

@section('title', 'Sesión no disponible | Spikia')

@section('content')
<div class="flex min-h-screen items-center justify-center bg-[#050505] px-6 py-12 text-white">
    <div class="w-full max-w-md rounded-[2.5rem] border border-white/10 bg-zinc-950/90 p-8 text-center shadow-[0_25px_80px_rgba(0,0,0,0.55)] backdrop-blur-xl">
        @if(Storage::disk('public')->exists('media/images/spikia-15.png'))
            <img src="{{ asset('storage/media/images/spikia-15.png') }}" alt="Spikia" class="mx-auto mb-6 h-14 w-auto opacity-90">
        @endif

        <p class="text-[10px] font-black uppercase tracking-[0.4em] text-zinc-500">Enlace vencido</p>
        <h1 class="mt-3 text-2xl font-black italic uppercase tracking-tight">Esta sesión ya no está disponible</h1>
        <p class="mt-4 text-sm leading-6 text-zinc-400">
            {{ $reason ?? 'El código QR o el enlace que usaste corresponde a una sesión que ya terminó o fue eliminada. Pedile al organizador un código nuevo.' }}
        </p>
    </div>
</div>
@endsection
