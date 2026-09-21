@use('SimpleSoftwareIO\QrCode\Facades\QrCode')
@php use App\Support\SpikiaUrl; @endphp
@extends('layouts.spikia')

@push('styles')
<style>
    /* Barra de scroll horizontal delgada para valores largos (STT/Traduccion/TTS) que no
       caben en las cajas angostas del panel "Salida de voz IA" — antes se cortaban con "...". */
    .spikia-hscroll {
        overflow-x: auto;
        white-space: nowrap;
        scrollbar-width: thin;
        scrollbar-color: rgba(34, 211, 238, 0.4) transparent;
    }
    .spikia-hscroll::-webkit-scrollbar {
        height: 4px;
    }
    .spikia-hscroll::-webkit-scrollbar-thumb {
        background-color: rgba(34, 211, 238, 0.4);
        border-radius: 9999px;
    }
    .spikia-hscroll::-webkit-scrollbar-track {
        background: transparent;
    }
</style>
@endpush

@section('content')
@php
    $masterLanguages = config('spikia.master_languages', []);
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
        'master_translation_prompt' => $translationDefaults['master_translation_prompt'] ?? '',
    ], is_array($sesion->translation_settings ?? null) ? $sesion->translation_settings : []);
    $sessionLanguages = is_array($sesion->idiomas ?? null) ? array_values($sesion->idiomas) : [];
    $targetLanguages = $sessionLanguages !== []
        ? $sessionLanguages
        : array_values(array_filter(config('spikia.default_targets', ['en', 'pt', 'it', 'fr'])));
    $masterLanguageLabels = [];
    foreach ($masterLanguages as $language) {
        if (! empty($language['id']) && ! empty($language['name'])) {
            $masterLanguageLabels[$language['id']] = $language['name'];
        }
    }
    $urlReunion = SpikiaUrl::public(route('sesion.short', ['code' => $sesion->short_code]));
    $glosarioRel = $sesion->glosario;
    $glossaryTerms = $glosarioRel ? $glosarioRel->getTermsList() : [];
    $isDemoSession = ! empty($sesion->demo_expires_at);
    $masterConfig = [
        'sesionId' => $sesion->id,
        'slug' => $sesion->slug,
        'socketUrl' => config('spikia.socket_enabled') ? (config('spikia.socket_url') ?: request()->getScheme() . '://' . request()->getHost() . ':3000') : null,
        'relayUrl' => route('sesiones.mensajes.store', ['slug' => $sesion->slug], false),
        'audioProcessUrl' => route('sesiones.audio.process', ['slug' => $sesion->slug], false),
        'interimUrl' => route('sesiones.interim.update', ['slug' => $sesion->slug], false),
        'transcripcionUrl' => route('transcripciones.store', [], false),
        'csrfToken' => csrf_token(),
        'sessionLanguages' => $sessionLanguages,
        'targetLanguages' => $targetLanguages,
        'languageLabels' => $masterLanguageLabels,
        'defaultTargets' => config('spikia.default_targets', ['en', 'pt', 'it', 'fr']),
        'brandingLogoUrl' => asset('storage/media/images/spikia-15.png'),
        'brandTitle' => 'SPIKIA',
        'shortCode' => $sesion->short_code_formatted,
        // OJO: nunca poner aca la URL del panel Master (SpikiaUrl::master + sesion.master):
        // esta branding se imprime/comparte en el QR de "Acceso de invitados", y esa URL
        // le da control total de la sesion a quien la vea.
        'brandUrl' => $urlReunion,
        'demoExpiresAt' => $sesion->demo_expires_at?->toIso8601String(),
        'voiceProviderDefault' => $sessionTranslation['voice_provider'] ?? 'elevenlabs',
        'voiceEndpoint' => route('voz.elevenlabs', [], false),
        'translationSettings' => $sessionTranslation,
        'deepgramTokenUrl' => null,
        'useDeepgram' => false,
        // Reconocimiento del NAVEGADOR como motor principal: transcribe solo cuando hay
        // voz real (no alucina en silencios como Whisper con bloques de 5s) y es de baja
        // latencia. OpenAI se usa solo para TRADUCIR el texto, no para transcribir.
        'useBackendAudioPipeline' => false,
        'glossaryTerms' => $glossaryTerms,
    ];
    $masterQrSvg = QrCode::format('svg')->errorCorrection('H')->size(300)->margin(2)->generate($urlReunion);
    $availableVoices = $translationDefaults['voice_profiles'] ?? [];
    $masterConfig['translationSettingsUrl'] = route('sesiones.translation.update', ['slug' => $sesion->slug], false);
    $masterConfig['availableVoices'] = $availableVoices;
    $masterConfig['voiceCloneUrl'] = route('sesiones.voice-clone.store', ['slug' => $sesion->slug], false);
    $masterConfig['clonedVoiceId'] = $sesion->cloned_voice_id;
    $masterConfig['liveTimerStartUrl'] = route('sesiones.live-timer.start', ['slug' => $sesion->slug], false);
    $masterConfig['liveTimerStopUrl'] = route('sesiones.live-timer.stop', ['slug' => $sesion->slug], false);
    if (config('spikia.features.meeting_bot')) {
        $masterConfig['meetingBotStartUrl'] = route('sesiones.meeting-bot.start', ['slug' => $sesion->slug], false);
        $masterConfig['meetingBotStopUrl'] = route('sesiones.meeting-bot.stop', ['slug' => $sesion->slug], false);
        $masterConfig['meetingBotStatusUrl'] = route('sesiones.meeting-bot.status', ['slug' => $sesion->slug], false);
    }
    $masterConfig['liveStartedAt'] = $sesion->live_started_at?->toIso8601String();
    $masterConfig['liveAccumulatedSeconds'] = (int) $sesion->live_accumulated_seconds;
    $masterConfig['hasMeetingLink'] = (bool) $sesion->zoom_link;

    // PoC/benchmark (ver informe "A vs B: menor latencia real"): 'current' no agrega
    // NINGUN request ni conexion nueva - todo el codigo de los 3 brazos queda inerte.
    $masterConfig['translationEngine'] = config('spikia.realtime_translation.engine', 'current');
    $masterConfig['realtimePocLanguage'] = config('spikia.realtime_translation.poc_target_language', 'en');
    $realtimeExperimentalEngines = [
        'realtime_experimental',
        'realtime_audio_experimental',
        'realtime_translate_dedicated_experimental',
    ];
    if (in_array($masterConfig['translationEngine'], $realtimeExperimentalEngines, true)) {
        $masterConfig['realtimeTokenUrl'] = route('traducciones.realtime-token', [], false);
        $masterConfig['realtimeBenchmarkUrl'] = route('traducciones.realtime-benchmark', [], false);
        $masterConfig['realtimeSegmentGapMs'] = config('spikia.realtime_translation.segment_gap_ms', 900);
    }
@endphp

@push('head-scripts')
<script>
window.__SPIKIA_MASTER__ = @json($masterConfig);
</script>
@vite('resources/js/master.js')
@endpush

<div class="flex min-h-screen bg-black text-white font-sans overflow-hidden lg:h-screen">
    {{-- En celular el panel de controles (fuente de audio, micrófono/pestaña, botón de
    vivo, QR) vivía en un <aside> con max-lg:hidden: quedaba totalmente inaccesible desde
    el teléfono. Ahora es un cajón deslizable (mismo patrón que el sidebar del Dashboard)
    que se abre con el botón hamburguesa; en pantallas lg+ queda fijo como antes. --}}
    <div id="master-sidebar-overlay" class="fixed inset-0 z-30 hidden bg-black/60 backdrop-blur-sm lg:hidden"></div>
    <aside id="master-sidebar" class="fixed inset-y-0 left-0 z-40 w-72 shrink-0 -translate-x-full transform overflow-y-auto bg-zinc-950 border-r border-white/5 p-5 flex flex-col gap-5 transition-transform duration-300 lg:static lg:z-20 lg:w-72 lg:translate-x-0 lg:max-h-screen spikia-scroll">
        <a href="{{ route('sesiones.index') }}" class="group flex items-center gap-2 text-zinc-500 hover:text-white transition-all mb-1">
            <div class="p-1.5 rounded-lg bg-zinc-900 group-hover:bg-zinc-800 border border-white/5">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </div>
            <span class="text-[9px] font-black tracking-widest uppercase italic">Volver al listado</span>
        </a>

        <div class="flex flex-col items-center justify-center py-4 mb-2">
            <div class="relative group cursor-pointer" onclick="document.getElementById('qr-modal').classList.remove('hidden')">
                <div id="logo-aura" class="absolute inset-0 rounded-full bg-indigo-600/10 blur-xl scale-125 group-hover:bg-indigo-600/30 transition-all"></div>
                <div class="relative bg-zinc-900/40 backdrop-blur-3xl border border-white/10 rounded-[1.5rem] p-4 shadow-2xl transition-transform group-hover:scale-105">
                    <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="h-14 w-auto object-contain" alt="Spikia">
                </div>
            </div>
            <span class="text-[7px] font-black text-zinc-600 uppercase tracking-[0.4em] mt-5 italic text-center underline decoration-indigo-600/40">Haz clic para ver el QR</span>
        </div>

        <div id="ia-panel" class="rounded-[1.5rem] border border-white/5 bg-zinc-900/40 overflow-hidden">
            <button type="button" id="ia-panel-toggle" class="w-full flex items-center justify-between gap-3 px-4 py-3 text-left hover:bg-white/5 transition" aria-expanded="false" aria-controls="ia-panel-body">
                <span class="flex items-center gap-2">
                    <span class="inline-flex h-2 w-2 rounded-full bg-cyan-400 shadow-[0_0_8px_#22d3ee]"></span>
                    <span class="text-[9px] font-black uppercase tracking-[0.25em] text-cyan-200">Salida de voz IA</span>
                </span>
                <span class="flex items-center gap-2">
                    <span id="ia-panel-summary" class="text-[8px] font-black uppercase tracking-[0.2em] text-zinc-500 truncate max-w-[110px]">
                        {{ ($sessionTranslation['translation_mode'] ?? 'voice_to_voice') === 'voice_to_voice' ? 'Voz' : 'Texto' }} · {{ $sessionTranslation['voice'] ?? 'marin' }}
                    </span>
                    <svg id="ia-panel-caret" class="h-3 w-3 text-zinc-500 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </span>
            </button>
            <div id="ia-panel-body" class="hidden px-4 pb-4 pt-1 space-y-3">
                <div class="grid grid-cols-2 gap-2">
                    <div class="rounded-xl border border-white/10 bg-black/30 px-3 py-2 min-w-0">
                        <p class="text-[8px] font-black uppercase tracking-[0.25em] text-zinc-500">Modo</p>
                        <p class="mt-1 text-[9px] font-black uppercase tracking-[0.2em] text-white spikia-hscroll">{{ ($sessionTranslation['translation_mode'] ?? 'voice_to_voice') === 'voice_to_voice' ? 'Voz a voz' : 'Voz a texto' }}</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-black/30 px-3 py-2 min-w-0">
                        <p class="text-[8px] font-black uppercase tracking-[0.25em] text-zinc-500">STT</p>
                        <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em] text-white spikia-hscroll">{{ $sessionTranslation['speech_to_text_model'] ?? 'gpt-4o-mini-transcribe' }}</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-black/30 px-3 py-2 min-w-0">
                        <p class="text-[8px] font-black uppercase tracking-[0.25em] text-zinc-500">Traduccion</p>
                        <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em] text-white spikia-hscroll">{{ $sessionTranslation['translation_model'] ?? 'gpt-5.4-mini' }}</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-black/30 px-3 py-2 min-w-0">
                        <p class="text-[8px] font-black uppercase tracking-[0.25em] text-zinc-500">TTS</p>
                        <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em] text-white spikia-hscroll">{{ $sessionTranslation['text_to_speech_model'] ?? 'gpt-4o-mini-tts' }}</p>
                    </div>
                </div>

                <div class="rounded-xl border border-white/10 bg-black/30 px-3 py-3">
                    <label for="master-voice-select" class="text-[8px] font-black uppercase tracking-[0.25em] text-zinc-500 block">Voz IA</label>
                    <select id="master-voice-select" class="mt-2 w-full bg-zinc-950 border border-white/10 rounded-xl px-3 py-2 text-[10px] font-black uppercase tracking-widest text-white">
                        @foreach($availableVoices as $voiceOption)
                            <option value="{{ $voiceOption['value'] }}" data-gender="{{ $voiceOption['gender'] ?? '' }}" {{ ($sessionTranslation['voice'] ?? 'marin') === ($voiceOption['value'] ?? '') ? 'selected' : '' }}>{{ $voiceOption['label'] ?? $voiceOption['value'] }}</option>
                        @endforeach
                    </select>
                    <p id="master-voice-status" class="mt-2 text-[8px] uppercase tracking-[0.25em] text-zinc-600">Se aplica al siguiente bloque de audio.</p>
                </div>

                <div class="rounded-xl border border-white/10 bg-black/30 px-3 py-3">
                    <p class="text-[8px] font-black uppercase tracking-[0.25em] text-zinc-500">Perfil de voz</p>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <button id="voice-male" type="button" class="voice-gender-btn py-2 rounded-xl text-[9px] font-black border border-white/10 transition-all" data-gender="male">MALE</button>
                        <button id="voice-female" type="button" class="voice-gender-btn py-2 rounded-xl text-[9px] font-black border border-white/10 transition-all" data-gender="female">FEMALE</button>
                    </div>
                    <p class="mt-2 text-[8px] uppercase tracking-[0.25em] text-zinc-600">El perfil manda sobre la voz que escuchará la traducción.</p>
                </div>

                <div class="rounded-xl border border-cyan-400/15 bg-cyan-400/5 px-3 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[8px] font-black uppercase tracking-[0.25em] text-zinc-500">Proveedor</p>
                            <p id="master-voice-provider-label" class="mt-1 text-[10px] font-black uppercase tracking-[0.22em] text-white truncate">ElevenLabs</p>
                        </div>
                        <span class="inline-flex items-center rounded-full border border-cyan-400/20 bg-cyan-400/10 px-3 py-2 text-[9px] font-black uppercase tracking-[0.25em] text-cyan-200">
                            Tiempo real
                        </span>
                    </div>
                </div>

                <div id="voice-clone-panel" class="rounded-xl border border-violet-400/15 bg-violet-400/5 px-3 py-3 space-y-2.5" data-cloned="{{ $sesion->cloned_voice_id ? '1' : '0' }}">
                    <p class="text-[8px] font-black uppercase tracking-[0.25em] text-zinc-500">Clonar voz del orador</p>

                    <div id="voice-clone-idle" class="{{ $sesion->cloned_voice_id ? 'hidden' : '' }} space-y-2.5">
                        <label class="flex items-start gap-2 text-[9px] leading-relaxed text-zinc-400">
                            <input type="checkbox" id="voice-clone-consent" class="mt-0.5 rounded border-white/20 bg-zinc-950 text-violet-500 focus:ring-violet-500">
                            <span>Confirmo que el orador dio su consentimiento explícito para grabar y clonar su voz con IA, exclusivamente para esta sesión.</span>
                        </label>
                        <button id="voice-clone-record" type="button" disabled class="w-full rounded-xl border border-violet-400/30 bg-violet-400/10 px-3 py-2 text-[9px] font-black uppercase tracking-[0.2em] text-violet-200 transition-all disabled:opacity-40 disabled:cursor-not-allowed">
                            Grabar muestra de voz
                        </button>
                        <p id="voice-clone-status" class="text-[8px] uppercase tracking-[0.2em] text-zinc-600">Marca el consentimiento para habilitar la grabación. Ideal: 30-60s de voz clara y continua.</p>
                    </div>

                    <div id="voice-clone-active" class="{{ $sesion->cloned_voice_id ? '' : 'hidden' }} flex items-center justify-between gap-2">
                        <p class="text-[9px] font-black uppercase tracking-[0.2em] text-violet-200">Voz del orador activa</p>
                        <button id="voice-clone-remove" type="button" class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[8px] font-black uppercase tracking-[0.2em] text-zinc-400 hover:text-white transition-all">
                            Quitar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-[1.5rem] border border-white/10 bg-zinc-950/40 px-4 py-4 space-y-3">
            <div class="flex items-center justify-between gap-2">
                <p class="text-[8px] font-black uppercase tracking-[0.25em] text-zinc-500">Fuente de audio</p>
                <button id="master-mic-test" type="button" class="rounded-full border border-cyan-400/30 bg-cyan-400/10 px-3 py-1 text-[8px] font-black uppercase tracking-[0.2em] text-cyan-200 transition-all">Probar</button>
            </div>
            @if($sesion->zoom_link)
                <a href="{{ $sesion->zoom_link }}" target="_blank" rel="noopener" class="block w-full rounded-xl border border-emerald-400/30 bg-emerald-400/10 py-2 text-center text-[9px] font-black uppercase tracking-[0.2em] text-emerald-200 hover:bg-emerald-400/20 transition-all">
                    Abrir reunión (Zoom/Meet)
                </a>
            @endif
            <div class="grid grid-cols-2 gap-2">
                <button id="master-source-mic" type="button" class="source-btn py-2 rounded-xl text-[9px] font-black border border-white/10 transition-all" data-source="microphone">MICRÓFONO</button>
                <button id="master-source-tab" type="button" class="source-btn py-2 rounded-xl text-[9px] font-black border border-white/10 transition-all" data-source="tab">{{ $sesion->zoom_link ? 'PESTAÑA / ZOOM / MEET' : 'PESTAÑA / VIDEO' }}</button>
            </div>
            <select id="master-mic-select" class="w-full bg-zinc-950 border border-white/10 rounded-xl px-3 py-2 text-[10px] font-black uppercase tracking-widest text-white">
                <option value="">Micrófono predeterminado</option>
            </select>
            <div class="h-2.5 w-full overflow-hidden rounded-full bg-white/5 border border-white/10">
                <div id="master-mic-level" class="h-full w-0 bg-gradient-to-r from-cyan-400 to-emerald-400 transition-[width] duration-75"></div>
            </div>
            <p id="master-mic-hint" class="text-[8px] uppercase tracking-[0.2em] text-zinc-600">Elige tu micrófono y pulsa Probar: la barra debe moverse al hablar.</p>
        </div>

        @if(config('spikia.features.meeting_bot') && $sesion->zoom_link)
            <details class="rounded-[1.5rem] border border-fuchsia-400/15 bg-fuchsia-400/5 px-4 py-3">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-2">
                    <span class="text-[8px] font-black uppercase tracking-[0.25em] text-zinc-500">Configuración avanzada · Bot de reunión (beta)</span>
                    <span id="meeting-bot-status-badge" class="rounded-full border border-white/10 bg-black/30 px-2 py-1 text-[8px] font-black uppercase tracking-[0.2em] text-zinc-400">Inactivo</span>
                </summary>
                <div class="mt-3 space-y-3">
                    <p class="text-[8px] leading-relaxed text-zinc-500">Spikia entra a la reunión como invitado usando el enlace de arriba y traduce lo que se dice ahí. El anfitrión debe admitirlo cuando pida unirse.</p>
                    <div class="grid grid-cols-2 gap-2">
                        <button id="meeting-bot-start" type="button" class="py-2 rounded-xl text-[9px] font-black border border-emerald-400/30 bg-emerald-400/10 text-emerald-200 hover:bg-emerald-400/20 transition-all">Unir bot</button>
                        <button id="meeting-bot-stop" type="button" class="py-2 rounded-xl text-[9px] font-black border border-red-400/30 bg-red-400/10 text-red-200 hover:bg-red-400/20 transition-all">Detener bot</button>
                    </div>
                </div>
            </details>
        @endif

        <button id="master-live-btn" class="group relative w-full bg-zinc-900 border border-white/10 hover:border-indigo-600/50 rounded-[1.5rem] p-5 transition-all duration-500 overflow-hidden shadow-2xl">
            <div class="relative z-10 flex flex-col items-center gap-2">
                <div id="status-pulse" class="flex h-3 w-3">
                    <span id="status-dot" class="relative inline-flex rounded-full h-3 w-3 bg-zinc-700"></span>
                </div>
                <span id="status-text" class="text-[9px] font-black text-zinc-500 uppercase tracking-[0.3em]">En espera</span>
            </div>
            <div id="btn-bg-active" class="absolute inset-0 bg-gradient-to-br from-indigo-600/40 via-blue-500/10 to-transparent opacity-0 transition-opacity duration-700"></div>
        </button>

        <button id="keep-screen-on-btn" type="button" class="mt-3 flex w-full items-center justify-start gap-2 rounded-full border border-zinc-700/70 bg-zinc-950/90 px-3.5 py-2 text-left text-[10px] font-black uppercase tracking-[0.18em] text-zinc-200 shadow-[0_0_20px_rgba(255,255,255,0.05)] transition-all duration-200 active:scale-95">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17h4.5M7 9.5A5 5 0 0117 9.5v5.5a2 2 0 01-2 2H9a2 2 0 01-2-2V9.5zM9.5 4.5h5" />
            </svg>
            <span>Pantalla apagada</span>
        </button>

        <div class="bg-zinc-900/20 rounded-[1.5rem] border border-white/5 p-5 flex flex-col min-h-[140px]">
            <span class="text-[8px] font-black text-zinc-600 uppercase tracking-[0.2em] mb-4 text-center">Frecuencia de entrada</span>
            <div id="audio-visualizer" class="flex-1 flex items-end justify-center gap-1.5 px-1 pb-2">
                @for ($i = 0; $i < 15; $i++)
                    <div class="w-1 bg-zinc-800 rounded-full transition-all duration-150 bar" style="height: 15%"></div>
                @endfor
            </div>
        </div>
    </aside>

    <main class="min-w-0 flex-1 flex flex-col p-4 sm:p-6 lg:p-8 bg-black relative">
        <div class="relative z-20">
            @include('modules.sessions.partials.demo-banner', ['sesion' => $sesion, 'isHost' => true])
        </div>
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_50%_-20%,#1e1b4b,transparent)] opacity-50"></div>

        <button id="master-sidebar-toggle" type="button" class="relative z-10 mb-4 flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-[10px] font-black uppercase tracking-[0.25em] text-zinc-300 lg:hidden">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            Controles de la sesión
        </button>

        <header class="relative z-10 mb-8 flex flex-col gap-4 lg:flex-row lg:justify-between lg:items-start">
            <div>
                <h2 class="text-4xl font-extralight tracking-tighter mb-2 text-white italic">Spikia <span class="font-black not-italic text-transparent bg-clip-text bg-gradient-to-r from-white via-zinc-400 to-zinc-600">Master Control</span></h2>
                <p class="text-zinc-500 font-bold text-[12px] tracking-widest uppercase italic">{{ $sesion->titulo }}</p>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <div class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2">
                        <span class="text-[9px] font-black uppercase tracking-[0.3em] text-zinc-500">Idioma activo</span>
                        <span id="selected-language-label" class="text-[10px] font-black uppercase tracking-[0.22em] text-neonBlue">Español España</span>
                    </div>
                    <span id="capture-status-badge" class="hidden inline-flex items-center gap-2 rounded-full border border-cyan-400/30 bg-cyan-400/10 px-4 py-2 text-[10px] font-black uppercase tracking-[0.15em] text-cyan-100"></span>
                </div>
            </div>
            <div class="px-8 py-3 bg-zinc-950/80 backdrop-blur-xl rounded-[1.5rem] border border-white/10 shadow-2xl">
                <span id="session-timer" class="text-3xl font-light text-zinc-400 italic">00:00:00</span>
            </div>
        </header>

        <div class="relative z-10 mb-8 flex flex-col items-start gap-2 rounded-[1.5rem] border border-white/10 bg-zinc-900/30 p-4 sm:flex-row sm:items-center sm:gap-3">
            <label for="master-lang-select" class="shrink-0 text-[9px] font-black uppercase tracking-[0.3em] text-zinc-500">Estoy hablando en</label>
            <select id="master-lang-select" class="w-full rounded-xl border border-white/10 bg-zinc-950 px-4 py-2.5 text-sm font-black uppercase tracking-widest text-white outline-none transition-colors focus:border-indigo-500 sm:max-w-xs">
                @foreach($masterLanguages as $lang)
                    <option value="{{ $lang['id'] }}"
                        data-lang-base="{{ $lang['base'] }}"
                        data-speech-lang="{{ $lang['speech'] }}"
                        data-lang-name="{{ $lang['name'] }}"
                        {{ $lang['id'] == 'es-ES' ? 'selected' : '' }}>
                        {{ $lang['name'] }} ({{ $lang['id'] }})
                    </option>
                @endforeach
            </select>
        </div>

        <section class="relative z-10 mb-6 rounded-[2rem] border border-white/10 bg-zinc-950/60 backdrop-blur-xl p-5 shadow-2xl">
            <div class="flex items-center justify-between gap-4 border-b border-white/5 pb-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Listeners conectados</p>
                    <p class="mt-1 text-sm text-zinc-400">Idiomas activos y reproduccion en tiempo real.</p>
                </div>
                <div class="rounded-full border border-cyan-400/20 bg-cyan-400/10 px-4 py-2 text-[10px] font-black uppercase tracking-[0.3em] text-cyan-200">
                    <span id="listener-presence-count">0 activos</span>
                </div>
            </div>
            <div id="listener-presence-list" class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-2xl border border-dashed border-white/10 bg-white/5 px-4 py-4 text-sm text-zinc-500">
                    Esperando listeners conectados...
                </div>
            </div>
        </section>

        <div class="relative z-10 flex-1 flex flex-col bg-zinc-950/20 backdrop-blur-sm border border-white/5 rounded-[3rem] p-8 overflow-hidden shadow-inner">
            <div id="transcription-box" class="flex-1 overflow-y-auto px-4 space-y-4 flex flex-col pt-10">
                <p id="placeholder-text" class="text-lg font-light text-zinc-800 italic uppercase text-center">Esperando señal de voz...</p>
            </div>
        </div>
    </main>
</div>

<div id="qr-modal" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/95 backdrop-blur-md">
    <div class="absolute inset-0" onclick="document.getElementById('qr-modal').classList.add('hidden')"></div>
    <div class="relative bg-zinc-900 border border-white/10 p-10 rounded-[3rem] text-center max-w-lg shadow-[0_0_150px_rgba(112,0,255,0.2)] backdrop-blur-xl">
        <p class="text-[10px] font-black uppercase tracking-[0.4em] text-indigo-400 mb-1">Spikia Live</p>
        <h3 class="text-3xl font-black italic uppercase tracking-tighter mb-4 text-white">Acceso de invitados</h3>
        <div id="master-qr-wrap" class="bg-white p-4 rounded-[2rem] inline-block shadow-2xl border-[8px] border-indigo-600/10 ring-1 ring-black/5 overflow-hidden">
            {!! $masterQrSvg !!}
        </div>
        <p class="mt-5 text-3xl font-black tracking-[0.2em] text-white">{{ $sesion->short_code_formatted }}</p>
        <div class="mt-3">
            <p class="text-[10px] font-bold text-zinc-400">Escanea el código con la cámara de tu celular, o entra a</p>
            <code class="mt-2 inline-block text-indigo-400 font-bold text-[10px] bg-black/50 px-4 py-2 rounded-full border border-white/5 break-all">{{ $urlReunion }}</code>
            <div class="mt-6 flex flex-col gap-3">
                <button onclick="downloadMasterQrPng()" class="px-8 py-3 bg-zinc-800 text-white hover:bg-neonBlue hover:text-black rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all">
                    Descargar ZIP
                </button>
                <button onclick="document.getElementById('qr-modal').classList.add('hidden')" class="px-8 py-3 bg-white text-black hover:bg-indigo-600 hover:text-white rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all">
                    Cerrar panel
                </button>
            </div>
        </div>
    </div>
</div>

@endsection
