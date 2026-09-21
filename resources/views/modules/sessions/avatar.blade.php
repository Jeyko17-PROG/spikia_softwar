@extends('layouts.spikia')

@section('title', 'Spikia - Avatar 3D - ' . $sesion->titulo)

@section('content')
<div class="relative flex h-screen w-screen items-center justify-center bg-transparent">
    <div class="pointer-events-none absolute top-6 left-1/2 -translate-x-1/2 z-10 rounded-full border border-amber-400/30 bg-amber-400/10 px-5 py-2 text-center text-[10px] font-black uppercase tracking-[0.25em] text-amber-200">
        Demostración del avatar 3D — no es una interpretación real de Lengua de Señas
    </div>

    {{-- Contenedor dinamico: lo que va adentro depende de avatar_mode ('3d' | 'video' |
    'human_live'). Los 3 modos comparten el mismo subtitulo de glosa (#avatar-caption). --}}
    <div id="avatar-render-container" class="relative h-full w-full">
        @if($sesion->avatar_mode === 'video')
            <video id="avatar-video-player" class="h-full w-full object-cover" muted playsinline></video>
        @elseif($sesion->avatar_mode === 'human_live')
            <video id="avatar-video-player" class="h-full w-full object-cover" muted playsinline autoplay></video>
            <p id="avatar-live-status" class="pointer-events-none absolute inset-x-0 bottom-24 px-6 text-center text-xs font-bold uppercase tracking-widest text-amber-200"></p>
        @else
            <canvas id="avatar-canvas" class="h-full w-full object-cover" style="display: block;"></canvas>
        @endif

        <p id="avatar-caption" class="pointer-events-none absolute bottom-10 left-1/2 -translate-x-1/2 text-center text-2xl font-black uppercase tracking-widest text-cyan-300 drop-shadow-lg"></p>
    </div>

    @if($sesion->avatar_mode === '3d')
        <div class="absolute bottom-6 right-6 z-10 flex gap-2">
            @foreach(['avatar_femenino' => 'Avatar 1', 'avatar_masculino' => 'Avatar 2'] as $characterId => $label)
                <button
                    type="button"
                    onclick="window.SpikiaAvatarEngine && window.SpikiaAvatarEngine.switchAvatar('{{ $characterId }}')"
                    class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-[10px] font-black uppercase tracking-widest text-zinc-200 transition hover:bg-white/10"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    @endif
</div>

@php
    $avatarConfig = [
        'slug' => $sesion->slug,
        'avatarCharacter' => $sesion->avatar_character,
        'avatarVideoUrl' => $sesion->avatar_video_url,
    ];
@endphp

@push('head-scripts')
<script>
    window.__SPIKIA_LISTENER__ = @json($avatarConfig);
</script>
@endpush

@if($sesion->avatar_mode === 'video')
    @vite('resources/js/avatar-video-player.js')
@elseif($sesion->avatar_mode === 'human_live')
    @vite('resources/js/avatar-interprete-viewer.js')
@else
    @vite('resources/js/avatar-engine.js')
@endif
@endsection
