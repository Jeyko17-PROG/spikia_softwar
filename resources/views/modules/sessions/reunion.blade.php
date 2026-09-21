@extends('layouts.spikia')

@section('title', 'Spikia Listener')

@push('styles')
@vite('resources/css/sessions-live.css')
@endpush

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
        'audioDefaultEnabled' => ($sessionTranslation['translation_mode'] ?? 'voice_to_voice') === 'voice_to_voice',
        'preferLowLatencyAudio' => ($sessionTranslation['audio_delivery_mode'] ?? 'ultra_fast') === 'ultra_fast',
        'translationSettings' => $sessionTranslation,
    ];
@endphp

@push('head-scripts')
<script>
    window.__SPIKIA_LISTENER__ = @json($listenerConfig);
</script>
@vite('resources/js/listener.js')
@endpush

@section('content')
<div class="flex flex-col h-screen relative">
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_50%_0%,#1e1b4b,transparent)] opacity-60"></div>

    <header class="relative z-10 p-5 border-b border-white/5 bg-zinc-950/80 backdrop-blur-md flex justify-between items-center shadow-xl">
        <h2 class="text-xl font-light italic text-white">Spikia <span class="font-black not-italic text-transparent bg-clip-text bg-gradient-to-r from-spikiaPurple via-zinc-400 to-neonBlue">Listener</span></h2>
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

    <main class="relative z-10 flex-1 p-6 flex flex-col justify-between pb-12 overflow-hidden gap-6">
        <div class="flex-1 flex flex-col justify-center items-center text-center">
            <div id="subtitles-container" class="space-y-6 flex flex-col items-center w-full max-w-3xl mx-auto">
                <p id="placeholder" class="text-zinc-600 font-light italic text-lg animate-pulse tracking-wide">Selecciona tu idioma arriba...</p>
            </div>
        </div>

        <section class="w-full max-w-4xl mx-auto rounded-[2rem] border border-white/10 bg-zinc-950/70 backdrop-blur-xl shadow-[0_0_35px_rgba(0,0,0,0.35)] overflow-hidden">
            <div class="flex items-center justify-between gap-4 border-b border-white/5 px-5 py-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Linea de tiempo</p>
                    <p id="timeline-meta" class="mt-1 text-xs text-zinc-400">Esperando mensajes...</p>
                </div>
                <div class="text-right">
                    <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Estado relay</p>
                    <p id="pending-count" class="mt-1 text-xs text-neonBlue">0 pendientes</p>
                </div>
            </div>
            <div id="timeline-list" class="max-h-56 overflow-y-auto p-4 space-y-3">
                <div class="rounded-2xl border border-dashed border-white/10 bg-white/5 px-4 py-3 text-sm text-zinc-500">
                    Los mensajes traducidos aparecera aqui casi en tiempo real.
                </div>
            </div>
        </section>
    </main>

    <div class="fixed bottom-8 right-8 z-50">
        <button id="toggle-audio-btn" class="flex items-center justify-center w-14 h-14 rounded-full bg-zinc-900 border border-white/10 shadow-[0_0_20px_rgba(0,0,0,0.5)] transition-all active:scale-90 overflow-hidden group">
            <div id="audio-btn-bg" class="absolute inset-0 bg-neonBlue/10 opacity-0 transition-opacity"></div>
            <svg id="icon-audio-on" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-neonBlue hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
            </svg>
            <svg id="icon-audio-off" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2" />
            </svg>
        </button>
    </div>
</div>
@endsection
