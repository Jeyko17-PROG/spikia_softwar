@extends('layouts.spikia')

@section('title', 'Spikia - Intérprete en vivo - ' . $sesion->titulo)

@section('content')
<div class="flex min-h-screen w-screen flex-col items-center gap-6 bg-black px-6 py-10 text-white">
    <div class="w-full max-w-2xl rounded-2xl border border-amber-400/30 bg-amber-400/10 px-5 py-3 text-center text-[11px] font-black uppercase tracking-[0.2em] text-amber-200">
        Modo experimental: la captura y el recorte de fondo funcionan en tu navegador.
        @if(config('spikia.livekit.url') && config('spikia.livekit.api_key'))
            Esta señal se transmite en vivo a los oyentes de la sala.
        @else
            Falta configurar LiveKit en el servidor (LIVEKIT_URL/API_KEY/API_SECRET) para que
            los oyentes reciban esta señal.
        @endif
    </div>

    <h1 class="text-xl font-light italic">
        Spikia <span class="font-black not-italic text-transparent bg-clip-text bg-gradient-to-r from-spikiaPurple via-zinc-400 to-neonBlue">Intérprete</span>
        <span class="ml-2 text-sm font-bold text-zinc-500">— {{ $sesion->titulo }}</span>
    </h1>

    <div class="relative aspect-video w-full max-w-2xl overflow-hidden rounded-2xl border border-white/10 bg-zinc-950 shadow-2xl">
        <canvas id="interprete-output-canvas" class="h-full w-full object-cover"></canvas>
        <video id="interprete-source-video" class="hidden" muted playsinline autoplay></video>
    </div>

    <p id="interprete-status" class="max-w-2xl text-center text-xs font-bold uppercase tracking-widest text-zinc-400"></p>
</div>

@php
    $interpreteConfig = [
        'slug' => $sesion->slug,
        'livekitTokenUrl' => route('sesiones.livekit-token.publisher', ['slug' => $sesion->slug], false),
    ];
@endphp

@push('head-scripts')
<script>
    window.__SPIKIA_INTERPRETE__ = @json($interpreteConfig);
</script>
@endpush

@vite('resources/js/avatar-interprete-broadcaster.js')
@endsection
