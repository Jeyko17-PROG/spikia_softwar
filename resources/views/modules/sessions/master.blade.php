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
        'audioArchiveUrl' => route('sesiones.audio.archive', ['slug' => $sesion->slug], false),
        'audioDetectLanguageUrl' => route('sesiones.audio.detect-language', ['slug' => $sesion->slug], false),
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
                    <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="h-16 w-auto object-contain" alt="Spikia">
                </div>
            </div>
            <span class="text-[7px] font-black text-zinc-600 uppercase tracking-[0.4em] mt-5 italic text-center underline decoration-indigo-600/40">Haz clic para ver el QR</span>
        </div>

        <div id="ia-panel" class="shrink-0 rounded-[1.5rem] border border-white/5 bg-zinc-900/40 overflow-hidden">
            <button type="button" id="ia-panel-toggle" class="w-full flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 py-3 text-left hover:bg-white/5 transition" aria-expanded="false" aria-controls="ia-panel-body">
                <span class="flex items-center gap-2 min-w-0">
                    <svg class="h-3.5 w-3.5 shrink-0 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <span class="text-[9px] font-black uppercase tracking-[0.25em] text-zinc-400 whitespace-nowrap">Configuración avanzada</span>
                </span>
                <svg id="ia-panel-caret" class="h-3 w-3 shrink-0 text-zinc-500 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
                <span id="ia-panel-summary" class="w-full text-[8px] font-black uppercase tracking-[0.2em] text-zinc-600 break-words">
                    Probar audio · voz de IA · clonar voz
                </span>
            </button>
            <div id="ia-panel-body" class="hidden px-4 pb-4 pt-1 space-y-4">
                <div class="space-y-3">
                    <p class="text-[8px] font-black uppercase tracking-[0.25em] text-zinc-500">Fuente de audio</p>
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
                    {{-- id="master-mic-level"/"master-mic-hint" (el boton "Probar") se sacaron a
                    pedido; el medidor real en vivo es "Frecuencia de entrada", abajo del todo. --}}
                </div>

                <div class="h-px bg-white/5"></div>

                {{-- Apagado por defecto a proposito: sin esto, Whisper recibe de antemano el
                idioma elegido arriba, lo que ayuda a transcribir mejor. Prendido, detecta
                solo que idioma se habla (util si distintas personas hablan distintos
                idiomas en la misma sesion) a costa de algo de precision. --}}
                <label class="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-black/30 px-3 py-3 cursor-pointer">
                    <span class="min-w-0">
                        <span class="block text-[9px] font-black uppercase tracking-[0.2em] text-white">Detectar idioma automáticamente</span>
                        <span class="mt-1 block text-[8px] leading-relaxed text-zinc-500">Cambia el idioma solo según quién esté hablando. Puede perder algo de precisión en frases cortas.</span>
                    </span>
                    <span class="relative inline-flex h-6 w-11 shrink-0 items-center">
                        <input type="checkbox" id="master-auto-detect-lang" class="peer sr-only">
                        <span class="pointer-events-none absolute inset-0 rounded-full bg-zinc-700 transition-colors peer-checked:bg-indigo-600"></span>
                        <span class="pointer-events-none absolute left-0.5 h-5 w-5 rounded-full bg-white transition-transform peer-checked:translate-x-5"></span>
                    </span>
                </label>

                <div class="h-px bg-white/5"></div>

                {{-- Varios hablantes compartiendo el mismo microfono (panel, mesa redonda):
                esta etiqueta se guarda junto a cada frase (Transcripcion.hablante) - cambiarla
                antes de que hable cada persona. Nullable: sesiones de un solo orador (la
                mayoria) no la necesitan y quedan igual que antes. --}}
                <div class="space-y-2">
                    <p class="text-[8px] font-black uppercase tracking-[0.25em] text-zinc-500">Hablante actual</p>
                    <p class="text-[8px] leading-relaxed text-zinc-600">Si varias personas comparten el micrófono, tocá quién está hablando antes de que empiece - queda guardado junto a cada frase.</p>
                    <div class="flex flex-wrap gap-2" id="master-speaker-quick">
                        <button type="button" data-speaker="Hablante 1" class="speaker-quick-btn px-3 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-white/10 bg-zinc-900 text-zinc-400 transition-all">Hablante 1</button>
                        <button type="button" data-speaker="Hablante 2" class="speaker-quick-btn px-3 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-white/10 bg-zinc-900 text-zinc-400 transition-all">Hablante 2</button>
                        <button type="button" data-speaker="Hablante 3" class="speaker-quick-btn px-3 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-white/10 bg-zinc-900 text-zinc-400 transition-all">Hablante 3</button>
                        <button type="button" id="master-speaker-clear" class="px-3 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-white/10 bg-zinc-900 text-zinc-500 transition-all hover:text-white">Ninguno</button>
                    </div>
                    <input type="text" id="master-speaker-input" maxlength="80" placeholder="O escribí un nombre (ej. María)" class="w-full bg-zinc-950 border border-white/10 rounded-xl px-3 py-2 text-[10px] font-medium text-white placeholder:text-zinc-600">

                    {{-- Deteccion automatica: solo funciona con el motor Deepgram (distingue
                    voces reales por audio). Con el motor por defecto (reconocimiento del
                    navegador) no hay forma tecnica de detectar quien habla - ese motor solo
                    recibe texto, nunca el audio en si - asi que ahi seguis necesitando los
                    botones de arriba. --}}
                    <label class="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-black/30 px-3 py-3 cursor-pointer">
                        <span class="min-w-0">
                            <span class="block text-[9px] font-black uppercase tracking-[0.2em] text-white">Detectar hablante automáticamente</span>
                            <span class="mt-1 block text-[8px] leading-relaxed text-zinc-500">Requiere el motor Deepgram activo en esta sesión. Con el motor por defecto (navegador), seguí usando los botones de arriba.</span>
                        </span>
                        <span class="relative inline-flex h-6 w-11 shrink-0 items-center">
                            <input type="checkbox" id="master-auto-speaker" class="peer sr-only">
                            <span class="pointer-events-none absolute inset-0 rounded-full bg-zinc-700 transition-colors peer-checked:bg-indigo-600"></span>
                            <span class="pointer-events-none absolute left-0.5 h-5 w-5 rounded-full bg-white transition-transform peer-checked:translate-x-5"></span>
                        </span>
                    </label>
                </div>

                <div class="h-px bg-white/5"></div>

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
                        <button type="button" id="master-voice-provider-toggle" class="shrink-0 rounded-full border border-cyan-400/20 bg-cyan-400/10 px-3 py-2 text-[9px] font-black uppercase tracking-[0.2em] text-cyan-200 transition hover:border-cyan-400/40 hover:bg-cyan-400/20">
                            Cambiar a OpenAI
                        </button>
                    </div>
                    <p class="mt-2 text-[8px] leading-relaxed text-zinc-600">OpenAI usa la misma clave que ya tenés configurada (sin cuenta aparte). ElevenLabs permite clonar la voz del orador; OpenAI no.</p>
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

    </aside>

    <main class="min-w-0 flex-1 flex flex-col p-4 sm:p-6 lg:p-8 bg-black relative">
        <div class="relative z-20">
            @include('modules.sessions.partials.demo-banner', ['sesion' => $sesion, 'isHost' => true])
        </div>
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_50%_-20%,#1e1b4b,transparent)] opacity-50"></div>

        {{-- Logo (abre el QR directo) queda solo arriba a la izquierda. El engranaje
        (configuración avanzada) y las personas conectadas se movieron arriba a la derecha,
        pegados al reloj - ver el header de abajo. --}}
        <div class="relative z-10 mb-6 flex items-center gap-2 lg:hidden">
            <button type="button" onclick="document.getElementById('qr-modal').classList.remove('hidden')" class="flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 transition hover:border-white/30" title="Ver código QR">
                <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="h-6 w-auto object-contain" alt="Spikia">
            </button>
        </div>

        <header class="relative z-30 mb-6 flex flex-col gap-3 lg:flex-row lg:justify-between lg:items-start">
            <div>
                <h2 class="text-3xl font-extralight tracking-tighter mb-1 text-white italic">Spikia <span class="font-black not-italic text-transparent bg-clip-text bg-gradient-to-r from-white via-zinc-400 to-zinc-600">Master Control</span></h2>
                <p class="text-zinc-500 font-bold text-[11px] tracking-widest uppercase italic">{{ $sesion->titulo }}</p>
                <div class="relative mt-3 flex flex-wrap items-center gap-2">
                    {{-- Antes esto era: un badge fijo "Idioma activo" + una barra entera
                    "Estoy hablando en [selector]" siempre visible - mostraban lo mismo dos
                    veces y ocupaban espacio permanente. Ahora es un solo botón compacto que
                    ya muestra el idioma actual, y el selector para cambiarlo vive en un
                    popover que se abre solo al tocarlo (mismo patrón que el ⚙ de Subtítulos). --}}
                    <button type="button" id="master-lang-toggle" class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-3 py-1.5 transition hover:border-indigo-500/50" aria-expanded="false" aria-controls="master-lang-panel" title="Cambiar el idioma en el que estás hablando">
                        <svg class="h-3 w-3 shrink-0 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 21a9 9 0 100-18 9 9 0 000 18z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12h18M12 3c2.5 2.7 4 6.1 4 9s-1.5 6.3-4 9c-2.5-2.7-4-6.1-4-9s1.5-6.3 4-9z"/>
                        </svg>
                        <span id="selected-language-label" class="text-[9px] font-black uppercase tracking-[0.18em] text-neonBlue">Español España</span>
                        <svg id="master-lang-toggle-caret" class="h-2.5 w-2.5 shrink-0 text-zinc-500 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <span id="capture-status-badge" class="hidden inline-flex items-center gap-1.5 rounded-full border border-cyan-400/30 bg-cyan-400/10 px-3 py-1.5 text-[9px] font-black uppercase tracking-[0.12em] text-cyan-100"></span>

                    {{-- Antes era un <select> nativo adentro del popover: había que abrir el
                    popover Y DESPUÉS abrir el select para recién ver los idiomas (doble
                    despliegue). Ahora la lista de idiomas ya está a la vista apenas se abre
                    el popover, un solo toque para elegir. --}}
                    <div id="master-lang-panel" class="hidden absolute left-0 top-full z-30 mt-2 w-72 rounded-2xl border border-white/10 bg-zinc-950/95 p-4 shadow-2xl backdrop-blur-md">
                        <p class="mb-2 block text-[9px] font-black uppercase tracking-[0.3em] text-zinc-500">Estoy hablando en</p>
                        <div id="master-lang-options" class="max-h-72 space-y-1 overflow-y-auto">
                            @foreach($masterLanguages as $lang)
                                <button type="button"
                                    class="master-lang-option flex w-full items-center justify-between gap-2 rounded-xl px-4 py-2.5 text-left text-sm font-black uppercase tracking-widest text-white transition hover:bg-white/10"
                                    data-lang="{{ $lang['id'] }}"
                                    data-lang-base="{{ $lang['base'] }}"
                                    data-speech-lang="{{ $lang['speech'] }}"
                                    data-lang-name="{{ $lang['name'] }}">
                                    <span>{{ $lang['name'] }} ({{ $lang['id'] }})</span>
                                    <svg class="master-lang-option-check hidden h-4 w-4 shrink-0 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                    </svg>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            <div class="relative flex flex-col items-end gap-2">
                {{-- Botón de "Configuración avanzada" arriba del cronómetro, a pedido - ni
                muy chico ni muy grande. --}}
                <button type="button" id="master-sidebar-toggle" class="flex h-9 w-9 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-zinc-300 transition hover:border-white/30 lg:hidden" title="Configuración avanzada: fuente alternativa de audio, voz de IA, frecuencia de entrada">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </button>
                <div class="flex items-center gap-2">
                    {{-- Antes esto era una barra entera "Listeners conectados" siempre visible,
                    aunque no hubiera nadie. Ahora es un ícono de persona con la cantidad como
                    badge; al tocarlo se despliega el detalle (mismo patrón que el idioma). El
                    contenido de #listener-presence-list lo genera master.js
                    (renderListenerPresence) - no tocar su estructura de grid. --}}
                    <button type="button" id="master-listeners-toggle" class="relative flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-zinc-300 transition hover:border-white/30" aria-expanded="false" aria-controls="master-listeners-panel" title="Personas conectadas a esta sesión">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m5-5.13a4 4 0 100-8 4 4 0 000 8zm6 3a4 4 0 100-8 4 4 0 000 8z" />
                        </svg>
                        <span id="listener-presence-badge" class="absolute -top-1.5 -right-1.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-cyan-400 px-1 text-[8px] font-black text-black">0</span>
                    </button>
                    <div class="px-8 py-3 bg-zinc-950/80 backdrop-blur-xl rounded-[1.5rem] border border-white/10 shadow-2xl">
                        <span id="session-timer" class="text-3xl font-light text-zinc-400 italic">00:00:00</span>
                    </div>
                </div>

                <div id="master-listeners-panel" class="hidden absolute right-0 top-full z-30 mt-2 w-80 rounded-2xl border border-white/10 bg-zinc-950/95 p-4 shadow-2xl backdrop-blur-md">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-[9px] font-black uppercase tracking-[0.3em] text-zinc-500">Personas conectadas</p>
                        <div class="shrink-0 rounded-full border border-cyan-400/20 bg-cyan-400/10 px-3 py-1 text-[9px] font-black uppercase tracking-[0.25em] text-cyan-200">
                            <span id="listener-presence-count">0 activos</span>
                        </div>
                    </div>
                    <div id="listener-presence-list" class="mt-2 grid gap-2 max-h-72 overflow-y-auto">
                        <div class="rounded-xl border border-dashed border-white/10 bg-white/5 px-3 py-1.5 text-[10px] text-zinc-500">
                            Esperando listeners conectados...
                        </div>
                    </div>
                </div>
            </div>
        </header>

        {{-- Traduccion en vivo dentro del propio Master: el presentador ve lo que se esta
        enviando a los oyentes sin tener que abrir el link de "Traducción" en otra pestaña.
        Solo LECTURA - master.js (updateMasterTranslationPreview) la llena a partir del texto
        que cada motor ya traduce, no cambia nada del envio real a los oyentes. --}}
        <details class="group relative z-10 mb-4 rounded-[2rem] border border-white/10 bg-zinc-950/20 backdrop-blur-sm open:pb-2">
            <summary class="cursor-pointer list-none flex items-center justify-between gap-3 px-6 py-4">
                <span class="flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.3em] text-emerald-400">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M3 5a2 2 0 002 2h8a2 2 0 002-2M3 5a2 2 0 012-2h8a2 2 0 012 2m-6 4v10m0 0l-3-3m3 3l3-3M15 8l3 3m0 0l3-3m-3 3V5"/></svg>
                    Traducción en vivo
                </span>
                <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </summary>
            <div id="master-translation-panel" class="px-6 pb-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                <p data-translation-placeholder class="col-span-full text-xs text-zinc-600 italic">Va a aparecer acá apenas se traduzca la primera frase.</p>
            </div>
        </details>

        <div class="relative z-10 mb-24 flex-1 flex flex-col bg-zinc-950/20 backdrop-blur-sm border border-white/5 rounded-[3rem] p-8 overflow-hidden shadow-inner lg:mb-28">
            <div id="transcription-box" class="flex-1 overflow-y-auto px-4 space-y-4 flex flex-col pt-10">
                <p id="placeholder-text" class="text-lg font-light text-zinc-800 italic uppercase text-center">Esperando señal de voz...</p>
            </div>
        </div>

        {{-- Barra fija abajo: el botón para arrancar y "Frecuencia de entrada" al mismo
        nivel, siempre a mano sin importar cuánto se haya scrolleado la transcripción.
        Antes había un botón separado para elegir micrófono y otro para salir en vivo -
        ahora es uno solo: activa el audio (con la fuente elegida en "Fuente de audio",
        dentro de Configuración avanzada) y sale en vivo en el mismo toque. --}}
        <div class="fixed inset-x-0 bottom-0 z-40 flex items-stretch gap-3 border-t border-white/10 bg-black/90 p-4 backdrop-blur-xl lg:left-72">
            <div class="flex w-24 shrink-0 flex-col rounded-xl border border-white/5 bg-zinc-900/20 px-2 py-2 sm:w-40 sm:px-3">
                <span class="mb-1 text-center text-[6px] font-black uppercase tracking-[0.15em] text-zinc-600 sm:text-[7px] sm:tracking-[0.2em]">Frecuencia</span>
                <div id="audio-visualizer" class="flex flex-1 items-end justify-center gap-1 px-1">
                    @for ($i = 0; $i < 15; $i++)
                        <div class="w-1 bg-zinc-800 rounded-full transition-all duration-150 bar" style="height: 15%"></div>
                    @endfor
                </div>
            </div>
            <button id="master-live-btn" class="group relative flex-1 bg-zinc-900 border border-white/10 hover:border-indigo-600/50 rounded-xl transition-all duration-500 overflow-hidden shadow-2xl">
                <div class="relative z-10 flex h-full flex-col items-center justify-center gap-1 py-3">
                    <div id="status-pulse" class="flex h-3 w-3">
                        <span id="status-dot" class="relative inline-flex rounded-full h-3 w-3 bg-zinc-700"></span>
                    </div>
                    <span id="status-text" class="text-[10px] font-black text-zinc-500 uppercase tracking-[0.3em]">Activar audio</span>
                </div>
                <div id="btn-bg-active" class="absolute inset-0 bg-gradient-to-br from-indigo-600/40 via-blue-500/10 to-transparent opacity-0 transition-opacity duration-700"></div>
            </button>
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
                <button type="button" id="master-copy-url-btn" class="px-8 py-3 bg-zinc-800 text-white hover:bg-cyan-400 hover:text-black rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all">
                    Copiar enlace
                </button>
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
