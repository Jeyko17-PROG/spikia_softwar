@extends('layouts.spikia')

@section('title', 'Spikia - ' . $sesion->titulo)

@push('styles')
@vite('resources/css/sessions-live.css')
@endpush

@section('content')
@php
    $translationDefaults = config('spikia.translation_simultaneous', []);
    $sessionTranslation = array_merge([
        'translation_mode' => 'voice_to_voice',
        'speech_to_text_model' => $translationDefaults['speech_to_text_model'] ?? 'gpt-4o-mini-transcribe',
        'translation_model' => $translationDefaults['translation_model'] ?? 'gpt-5.4-mini',
        'text_to_speech_model' => $translationDefaults['text_to_speech_model'] ?? 'gpt-4o-mini-tts',
        'voice_provider' => $translationDefaults['voice_provider'] ?? 'elevenlabs',
        'voice_gender_profile' => $translationDefaults['voice_gender_profile'] ?? 'female',
        'voice' => $translationDefaults['voice'] ?? 'marin',
        'audio_delivery_mode' => $translationDefaults['audio_delivery_mode'] ?? 'ultra_fast',
    ], is_array($sesion->translation_settings ?? null) ? $sesion->translation_settings : []);
@endphp
<div class="flex flex-col h-screen bg-black text-white font-sans overflow-hidden relative">
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_50%_0%,#1e1b4b,transparent)] opacity-60"></div>

    <header class="relative z-10 p-5 border-b border-white/5 bg-zinc-950/80 backdrop-blur-md flex justify-between items-center shadow-xl">
        <div class="flex items-center gap-3">
            <a href="{{ route('login') }}" class="group flex items-center gap-2 text-zinc-500 hover:text-white transition-all">
                <div class="p-1.5 rounded-lg bg-zinc-900 group-hover:bg-zinc-800 border border-white/5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                </div>
                <span class="text-[9px] font-black tracking-widest uppercase italic">Volver al login</span>
            </a>
            <h2 class="text-xl font-light italic text-white">Spikia <span class="font-black not-italic text-transparent bg-clip-text bg-gradient-to-r from-spikiaPurple via-zinc-400 to-neonBlue">Listener</span></h2>
        </div>
        <div class="flex items-center gap-2">
            <div class="hidden sm:flex items-center gap-2 bg-zinc-900 px-3 py-1.5 rounded-full border border-white/5">
                <span class="text-[9px] font-black uppercase tracking-[0.25em] text-zinc-500">Idioma</span>
                <span id="selected-language-label" class="text-[9px] font-black tracking-widest text-neonBlue">ESP-ES</span>
            </div>
            <div class="flex items-center gap-2 bg-zinc-900 px-3 py-1.5 rounded-full border border-white/5">
                <span id="status-dot" class="w-2 h-2 rounded-full bg-red-500 animate-pulse shadow-[0_0_8px_red]"></span>
                <span id="status-text" class="text-[9px] font-black tracking-widest text-zinc-400 mt-0.5">CONECTANDO</span>
            </div>
        </div>
    </header>

    <div class="relative z-10 px-6 pt-4">
        @include('modules.sessions.partials.demo-banner', ['sesion' => $sesion])
    </div>

    <div class="relative z-10 px-6 pt-3">
        <div class="inline-flex flex-wrap items-center gap-2 rounded-full border border-cyan-400/15 bg-cyan-400/5 px-4 py-2 text-[10px] font-black uppercase tracking-[0.28em] text-cyan-100">
            <span>{{ ($sessionTranslation['translation_mode'] ?? 'voice_to_voice') === 'voice_to_voice' ? 'Modo voz a voz' : 'Modo voz a texto' }}</span>
            <span class="text-zinc-500">/</span>
            <span>IA {{ $sessionTranslation['translation_model'] ?? 'gpt-5.4-mini' }}</span>
            <span class="text-zinc-500">/</span>
            <span>Voz {{ $sessionTranslation['voice'] ?? 'marin' }}</span>
        </div>
    </div>

    <div class="relative z-10 p-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 border-b border-white/5 bg-zinc-900/30 backdrop-blur-sm">
        @foreach(config('spikia.listener_languages', []) as $language)
            <button class="mobile-lang-btn py-3 rounded-xl text-[10px] font-black tracking-widest border border-white/5 text-zinc-500 hover:text-white bg-zinc-950 transition-all shadow-lg active:scale-95 {{ $language['id'] === 'es-ES' ? 'lang-active' : '' }}" data-lang="{{ $language['id'] }}">
                {{ $language['label'] }}
            </button>
        @endforeach
    </div>

    <div class="relative z-10 flex items-center justify-center gap-2 border-b border-white/5 bg-zinc-950/40 px-4 py-2">
        <span class="text-[9px] font-black uppercase tracking-[0.2em] text-zinc-600">Tamaño</span>
        <button type="button" data-subtitle-size="small" aria-label="Subtítulos pequeños" class="subtitle-size-btn rounded-lg border border-white/10 px-3 py-1 text-xs font-black text-zinc-400 transition-colors hover:border-cyan-400/50 hover:text-cyan-200">A-</button>
        <button type="button" data-subtitle-size="medium" aria-label="Subtítulos medianos" class="subtitle-size-btn rounded-lg border border-cyan-400/50 bg-cyan-400/10 px-3 py-1 text-xs font-black text-cyan-200 transition-colors">A</button>
        <button type="button" data-subtitle-size="large" aria-label="Subtítulos grandes" class="subtitle-size-btn rounded-lg border border-white/10 px-3 py-1 text-sm font-black text-zinc-400 transition-colors hover:border-cyan-400/50 hover:text-cyan-200">A+</button>
    </div>

    <main class="relative z-10 flex-1 px-4 pb-16 pt-6 flex flex-col justify-center items-center overflow-hidden text-center">
        <div id="subtitles-container" class="flex w-full max-w-3xl items-center justify-center min-h-[72px]">
            <p id="placeholder" class="text-zinc-600 font-light italic text-base sm:text-lg animate-pulse tracking-wide">Selecciona tu idioma arriba...</p>
        </div>
    </main>

    <div class="fixed bottom-8 left-8 z-50">
        <button id="keep-screen-on-btn" type="button" class="flex items-center justify-start gap-2 rounded-full border border-zinc-700/70 bg-zinc-950/90 px-3.5 py-2 text-[10px] font-black uppercase tracking-[0.18em] text-zinc-200 shadow-[0_0_20px_rgba(255,255,255,0.05)] transition-all duration-200 active:scale-95 min-w-[182px]">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17h4.5M7 9.5A5 5 0 0117 9.5v5.5a2 2 0 01-2 2H9a2 2 0 01-2-2V9.5zM9.5 4.5h5" />
            </svg>
            <span>Pantalla apagada</span>
        </button>
    </div>

    <div class="fixed bottom-8 right-8 z-50">
        <button id="toggle-audio-btn" type="button" aria-label="Activar volumen" class="relative flex items-center justify-center gap-2 rounded-full bg-zinc-900 border border-white/10 px-4 py-3 text-[10px] font-black uppercase tracking-[0.16em] text-zinc-200 shadow-[0_0_30px_rgba(0,0,0,0.8)] transition-all active:scale-90 overflow-hidden group">
            <div id="audio-btn-bg" class="absolute inset-0 rounded-full bg-neonBlue/20 opacity-0 transition-opacity"></div>
            <svg id="icon-audio-on" xmlns="http://www.w3.org/2000/svg" class="relative h-6 w-6 text-neonBlue hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
            </svg>
            <svg id="icon-audio-off" xmlns="http://www.w3.org/2000/svg" class="relative h-6 w-6 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2" />
            </svg>
            <span id="audio-btn-label" class="relative whitespace-nowrap">Activar volumen</span>
        </button>
    </div>

    {{-- Avatar 3D en Lengua de Señas: modulo opcional. Si el flag global o el toggle de esta
    sala estan apagados, Blade ni siquiera renderiza el contenedor ni carga avatar-engine.js:
    el navegador del oyente no descarga JS ni assets pesados en segundo plano. --}}
    @if(config('spikia.features.sign_avatar') && $sesion->has_sign_avatar)
        <div id="sign-avatar-container" class="hidden md:block fixed bottom-8 left-8 z-40 w-64 h-64 rounded-3xl border border-white/10 bg-zinc-950/80 backdrop-blur-md overflow-hidden shadow-2xl">
            <div class="pointer-events-none absolute top-1.5 left-1.5 right-1.5 z-10 rounded-full bg-amber-400/15 px-2 py-1 text-center text-[7px] font-black uppercase tracking-widest text-amber-200">
                Demo — no es LSE real
            </div>

            <div id="avatar-render-container" class="relative h-full w-full">
                @if($sesion->avatar_mode === 'video')
                    <video id="avatar-video-player" class="h-full w-full object-cover" muted playsinline></video>
                @elseif($sesion->avatar_mode === 'human_live')
                    <video id="avatar-video-player" class="h-full w-full object-cover" muted playsinline autoplay></video>
                    <p id="avatar-live-status" class="pointer-events-none absolute inset-x-0 bottom-6 px-2 text-center text-[7px] font-bold uppercase tracking-widest text-amber-200"></p>
                @else
                    <canvas id="avatar-canvas" class="w-full h-full"></canvas>
                @endif

                <p id="avatar-caption" class="pointer-events-none absolute bottom-2 left-1/2 -translate-x-1/2 text-center text-[10px] font-black uppercase tracking-widest text-cyan-300 drop-shadow-lg"></p>
            </div>
        </div>
        @if($sesion->avatar_mode === 'video')
            @vite(['resources/js/avatar-video-player.js'])
        @elseif($sesion->avatar_mode === 'human_live')
            @vite(['resources/js/avatar-interprete-viewer.js'])
        @else
            @vite(['resources/js/avatar-engine.js'])
        @endif
    @endif
</div>

@php
    $listenerLanguageLabels = [];
    foreach (config('spikia.listener_languages', []) as $language) {
        if (! empty($language['id']) && ! empty($language['label'])) {
            $listenerLanguageLabels[$language['id']] = $language['label'];
        }
    }
    $listenerConfig = [
        'slug' => $sesion->slug,
        'socketUrl' => config('spikia.socket_enabled') ? (config('spikia.socket_url') ?: request()->getScheme() . '://' . request()->getHost() . ':3000') : null,
        'feedUrl' => route('sesiones.mensajes.feed', ['slug' => $sesion->slug], false),
        'defaultLang' => 'es-ES',
        'languageLabels' => $listenerLanguageLabels,
        'voiceProvider' => $sessionTranslation['voice_provider'] ?? 'elevenlabs',
        'voiceEndpoint' => route('voz.elevenlabs', [], false),
        'voiceStreamEndpoint' => route('voz.elevenlabs.stream', [], false),
        'audioDefaultEnabled' => ($sessionTranslation['translation_mode'] ?? 'voice_to_voice') === 'voice_to_voice',
        'preferLowLatencyAudio' => ($sessionTranslation['audio_delivery_mode'] ?? 'ultra_fast') === 'ultra_fast',
        'translationSettings' => $sessionTranslation,
        'avatarCharacter' => $sesion->avatar_character,
        'avatarVideoUrl' => $sesion->avatar_video_url,
        'livekitTokenUrl' => $sesion->avatar_mode === 'human_live'
            ? route('sesiones.livekit-token.viewer', ['slug' => $sesion->slug], false)
            : null,
    ];
@endphp

@push('head-scripts')
<script>
    window.__SPIKIA_LISTENER__ = @json($listenerConfig);
</script>
@vite('resources/js/listener.js')
@endpush
@endsection
