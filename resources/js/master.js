import { io } from 'socket.io-client';
import { downloadBrandedQrPng } from './qr-download';
import { createRealtimeTranslator, reportRealtimeBenchmark } from './realtime-translate-poc.js';
import { createRealtimeAudioTranslator } from './realtime-audio-poc.js';
import { createDedicatedTranslationTranslator, createDedicatedTranslationSegmenter } from './realtime-translate-dedicated-poc.js';

(function patchFetchForNgrok() {
    if (window.__SPIKIA_FETCH_PATCHED__) return;
    window.__SPIKIA_FETCH_PATCHED__ = true;
    const originalFetch = window.fetch.bind(window);
    const RETRY_DELAY_MS = 2000;   // reintento en bucle cada 2s ante red dinamica
    const MAX_RETRIES = 30;        // ~60s de tolerancia a una caida del tunel
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    window.fetch = async function (input, init = {}) {
        const baseHeaders = init.headers
            ? new Headers(init.headers)
            : (input instanceof Request ? new Headers(input.headers) : new Headers());
        if (!baseHeaders.has('ngrok-skip-browser-warning')) {
            baseHeaders.set('ngrok-skip-browser-warning', 'true');
        }
        const nextInit = { ...init, headers: baseHeaders };

        // Reintentar SOLO peticiones idempotentes (GET/HEAD), p.ej. el feed del listener,
        // para sobrevivir a una caida del tunel. Los POST en tiempo real (audio, interim,
        // traduccion, relay) NO se reintentan: se procesan en serie con await y reintentar
        // en bucle congelaria la captura. Cada frase nueva vuelve a intentar igualmente.
        const method = String(
            (init && init.method) || (input instanceof Request ? input.method : 'GET') || 'GET'
        ).toUpperCase();
        const retriable = (method === 'GET' || method === 'HEAD') && !init.__spikiaNoRetry;

        let attempt = 0;
        for (;;) {
            try {
                const response = await originalFetch(input, nextInit);
                if (attempt > 0) {
                    window.dispatchEvent(new CustomEvent('spikia:network-restored'));
                }
                return response;
            } catch (error) {
                attempt += 1;
                if (!retriable || attempt > MAX_RETRIES) {
                    throw error;
                }
                window.dispatchEvent(new CustomEvent('spikia:network-retry', {
                    detail: { attempt, url: String((input && input.url) || input) },
                }));
                await sleep(RETRY_DELAY_MS);
            }
        }
    };
})();

function downloadMasterQrPng() {
    const wrapper = document.getElementById('master-qr-wrap');
    const svg = wrapper ? wrapper.querySelector('svg') : null;
    const config = window.__SPIKIA_MASTER__;
    const slug = config?.slug || 'master';

    if (!svg) {
        alert('No se encontro el QR.');
        return;
    }

    const svgData = new XMLSerializer().serializeToString(svg);
    const svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
    const url = URL.createObjectURL(svgBlob);
    const img = new Image();

    img.onload = () => {
        const canvas = document.createElement('canvas');
        const size = Math.max(img.width, img.height) || 1024;
        canvas.width = size;
        canvas.height = size;

        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, size, size);
        ctx.drawImage(img, 0, 0, size, size);

        canvas.toBlob((blob) => {
            if (!blob) {
                alert('No se pudo generar el PNG.');
                URL.revokeObjectURL(url);
                return;
            }

            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `qr-master-${slug}.png`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(link.href);
            URL.revokeObjectURL(url);
        }, 'image/png');
    };

    img.onerror = () => {
        alert('No se pudo convertir el QR a PNG.');
        URL.revokeObjectURL(url);
    };

    img.src = url;
}

function downloadMasterQrPngBranded() {
    const config = window.__SPIKIA_MASTER__;

    return downloadBrandedQrPng({
        wrapperId: 'master-qr-wrap',
        filename: `qr-master-${config?.slug || 'master'}.zip`,
        branding: {
            eyebrow: 'SPIKIA LIVE',
            title: config?.brandTitle && config.brandTitle !== 'SPIKIA' ? config.brandTitle : 'ACCESO DE INVITADOS',
            code: config?.shortCode || '',
            subtitle: 'Escanea el código con la cámara de tu celular, o entra a',
            url: config?.brandUrl || '',
        },
    });
}

window.downloadMasterQrPng = downloadMasterQrPngBranded;

const getVoiceProvider = () => {
    return String(window.__SPIKIA_MASTER__?.translationSettings?.voice_provider || window.__SPIKIA_MASTER__?.voiceProviderDefault || 'elevenlabs').toLowerCase() === 'elevenlabs'
        ? 'elevenlabs'
        : 'browser';
};

const config = window.__SPIKIA_MASTER__;

if (config) {
    document.addEventListener('DOMContentLoaded', () => {
        const elements = {
            masterBtn: document.getElementById('master-live-btn'),
            transcriptionBox: document.getElementById('transcription-box'),
            timerElement: document.getElementById('session-timer'),
            selectedLanguageLabel: document.getElementById('selected-language-label'),
            keepScreenBtn: document.getElementById('keep-screen-on-btn'),
            listenerPresenceList: document.getElementById('listener-presence-list'),
            listenerPresenceCount: document.getElementById('listener-presence-count'),
            statusDot: document.getElementById('status-dot'),
            statusText: document.getElementById('status-text'),
            btnBg: document.getElementById('btn-bg-active'),
            bars: document.querySelectorAll('.bar'),
            saveMode: document.getElementById('save-mode'),
            voiceProviderToggle: document.getElementById('master-voice-provider-toggle'),
            voiceProviderLabel: document.getElementById('master-voice-provider-label'),
            iaPanelToggle: document.getElementById('ia-panel-toggle'),
            iaPanelBody: document.getElementById('ia-panel-body'),
            iaPanelCaret: document.getElementById('ia-panel-caret'),
            iaPanelSummary: document.getElementById('ia-panel-summary'),
            voiceSelect: document.getElementById('master-voice-select'),
            voiceStatus: document.getElementById('master-voice-status'),
            voiceCloneConsent: document.getElementById('voice-clone-consent'),
            voiceCloneRecordBtn: document.getElementById('voice-clone-record'),
            voiceCloneStatus: document.getElementById('voice-clone-status'),
            voiceCloneIdle: document.getElementById('voice-clone-idle'),
            voiceCloneActive: document.getElementById('voice-clone-active'),
            voiceCloneRemoveBtn: document.getElementById('voice-clone-remove'),
            micSelect: document.getElementById('master-mic-select'),
            micTest: document.getElementById('master-mic-test'),
            micLevel: document.getElementById('master-mic-level'),
            micHint: document.getElementById('master-mic-hint'),
            meetingBotStart: document.getElementById('meeting-bot-start'),
            meetingBotStop: document.getElementById('meeting-bot-stop'),
            meetingBotBadge: document.getElementById('meeting-bot-status-badge'),
            sourceMic: document.getElementById('master-source-mic'),
            sourceTab: document.getElementById('master-source-tab'),
            captureStatusBadge: document.getElementById('capture-status-badge'),
        };

        if (!elements.masterBtn || !elements.transcriptionBox || !elements.timerElement) {
            return;
        }

        // Cajon deslizable del panel de controles en celular (mismo patron que el sidebar
        // del Dashboard) - antes el <aside> tenia max-lg:hidden y quedaba inalcanzable desde
        // el telefono.
        const sidebarToggleBtn = document.getElementById('master-sidebar-toggle');
        const masterSidebar = document.getElementById('master-sidebar');
        const masterSidebarOverlay = document.getElementById('master-sidebar-overlay');
        if (sidebarToggleBtn && masterSidebar && masterSidebarOverlay) {
            const openSidebar = () => {
                masterSidebar.classList.remove('-translate-x-full');
                masterSidebarOverlay.classList.remove('hidden');
            };
            const closeSidebar = () => {
                masterSidebar.classList.add('-translate-x-full');
                masterSidebarOverlay.classList.add('hidden');
            };
            sidebarToggleBtn.addEventListener('click', () => {
                const isOpen = !masterSidebar.classList.contains('-translate-x-full');
                if (isOpen) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
            masterSidebarOverlay.addEventListener('click', closeSidebar);
        }

        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }

        let screenWakeLock = null;
        let keepScreenOn = false;

        function updateKeepScreenUI() {
            if (!elements.keepScreenBtn) return;
            const active = !!keepScreenOn;
            elements.keepScreenBtn.classList.toggle('border-emerald-400/60', active);
            elements.keepScreenBtn.classList.toggle('bg-emerald-500/10', active);
            elements.keepScreenBtn.classList.toggle('text-emerald-200', active);
            elements.keepScreenBtn.classList.toggle('shadow-[0_0_25px_rgba(16,185,129,0.18)]', active);
            elements.keepScreenBtn.classList.toggle('border-zinc-700/70', !active);
            elements.keepScreenBtn.classList.toggle('bg-zinc-950/90', !active);
            elements.keepScreenBtn.classList.toggle('text-zinc-200', !active);
            const icon = active
                ? '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17h4.5M7 9.5A5 5 0 0117 9.5v5.5a2 2 0 01-2 2H9a2 2 0 01-2-2V9.5zM9.5 4.5h5" /></svg>'
                : '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17h4.5M7 9.5A5 5 0 0117 9.5v5.5a2 2 0 01-2 2H9a2 2 0 01-2-2V9.5zM9.5 4.5h5" /></svg>';
            elements.keepScreenBtn.innerHTML = `${icon}<span>${active ? 'Pantalla prendida' : 'Pantalla apagada'}</span>`;
            elements.keepScreenBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
        }

        async function requestKeepScreenOn() {
            if (!('wakeLock' in navigator) || keepScreenOn) {
                return;
            }

            try {
                screenWakeLock = await navigator.wakeLock.request('screen');
                keepScreenOn = true;
                updateKeepScreenUI();
                screenWakeLock.addEventListener('release', () => {
                    keepScreenOn = false;
                    updateKeepScreenUI();
                });
            } catch (error) {
                keepScreenOn = false;
                updateKeepScreenUI();
            }
        }

        async function releaseKeepScreenOn() {
            if (!screenWakeLock) {
                keepScreenOn = false;
                updateKeepScreenUI();
                return;
            }

            try {
                await screenWakeLock.release();
            } catch (error) {
                // noop
            } finally {
                screenWakeLock = null;
                keepScreenOn = false;
                updateKeepScreenUI();
            }
        }

        if (elements.keepScreenBtn) {
            elements.keepScreenBtn.addEventListener('click', async () => {
                if (!keepScreenOn) {
                    await requestKeepScreenOn();
                } else {
                    await releaseKeepScreenOn();
                }
            });

            document.addEventListener('visibilitychange', async () => {
                if (document.visibilityState === 'visible' && keepScreenOn && !screenWakeLock) {
                    await requestKeepScreenOn();
                }
            });
        }

        updateKeepScreenUI();

        const targetLanguages = Array.isArray(config.targetLanguages) && config.targetLanguages.length
            ? config.targetLanguages
            : (Array.isArray(config.sessionLanguages) && config.sessionLanguages.length
                ? config.sessionLanguages
                : (config.defaultTargets || ['en', 'pt', 'it', 'fr']));
        const relayUrl = config.relayUrl;
        const transcripcionUrl = config.transcripcionUrl;
        const audioProcessUrl = config.audioProcessUrl;
        const interimUrl = config.interimUrl;
        const translationSettingsUrl = config.translationSettingsUrl;
        const liveTimerStartUrl = config.liveTimerStartUrl;
        const liveTimerStopUrl = config.liveTimerStopUrl;
        const meetingBotStartUrl = config.meetingBotStartUrl;
        const meetingBotStopUrl = config.meetingBotStopUrl;
        const meetingBotStatusUrl = config.meetingBotStatusUrl;
        const csrfToken = config.csrfToken;

        // --- PoC/benchmark: traduccion ES->EN via OpenAI Realtime (ver informe "A vs B:
        // menor latencia real"). Motor actual (Chat Completions / /traducciones/batch)
        // sigue intacto para todo lo demas. Tres brazos EXCLUYENTES via .env
        // SPIKIA_TRANSLATION_ENGINE:
        //   'realtime_experimental'                    -> texto (Web Speech API -> Realtime texto)
        //   'realtime_audio_experimental'               -> brazo A: audio -> gpt-realtime-2.1 + VAD
        //   'realtime_translate_dedicated_experimental' -> brazo B: audio -> gpt-realtime-translate
        const realtimePocLanguage = config.realtimePocLanguage || 'en';
        const realtimeUrlsReady = !!config.realtimeTokenUrl && !!config.realtimeBenchmarkUrl;
        const realtimeTextEngineActive = config.translationEngine === 'realtime_experimental' && realtimeUrlsReady;
        const realtimeAudioEngineActive = config.translationEngine === 'realtime_audio_experimental' && realtimeUrlsReady;
        const realtimeDedicatedEngineActive = config.translationEngine === 'realtime_translate_dedicated_experimental' && realtimeUrlsReady;

        const realtimeTranslator = realtimeTextEngineActive ? createRealtimeTranslator({
            realtimeTokenUrl: config.realtimeTokenUrl,
            realtimeBenchmarkUrl: config.realtimeBenchmarkUrl,
            csrfToken,
            sesionId: config.sesionId,
            realtimePocLanguage,
        }) : null;

        // Brazo A (audio+VAD, motor de produccion): estado de la frase en curso POR IDIOMA
        // (una conexion Realtime independiente por idioma destino - ver
        // realtime-audio-poc.js), correlacionado por los eventos del propio VAD del
        // servidor (speech_started/delta/final) - ver setupRealtimeAudioHandlers().
        const realtimeAudioUtterances = new Map();
        const realtimeAudioTranslator = realtimeAudioEngineActive ? createRealtimeAudioTranslator({
            realtimeTokenUrl: config.realtimeTokenUrl,
            csrfToken,
            sesionId: config.sesionId,
        }) : null;

        if (realtimeAudioTranslator) {
            setupRealtimeAudioHandlers();
        }

        /**
         * Correlaciona los eventos crudos de la sesion Realtime de audio en "frases" POR
         * IDIOMA: desde que el VAD del servidor detecta inicio de habla en la conexion de
         * ese idioma, hasta que llega response.done. El texto ORIGINAL (idioma fuente)
         * llega aparte via conversation.item.input_audio_transcription.completed y puede
         * llegar antes o despues del resultado traducido - se guarda el que llegue primero.
         */
        function setupRealtimeAudioHandlers() {
            realtimeAudioTranslator.setEventHandler((eventName, payload, ts, lang) => {
                if (eventName === 'speech_started') {
                    realtimeAudioUtterances.set(lang, { speechStartedAt: ts, firstResultAt: null, sourceText: '', timeline: [] });
                } else if (eventName === 'delta') {
                    // Solo actualiza el status de UI con el primer delta - NO se emite nada
                    // por socket aca. El final (response.done) suele llegar decenas de ms
                    // despues del primer delta (ver benchmark), asi que emitir tambien un
                    // "adelanto" no gana latencia perceptible y causaba que la voz del
                    // oyente sonara DOS VECES por frase (una vez con el parcial, otra con
                    // el texto final completo).
                    const utterance = realtimeAudioUtterances.get(lang);
                    if (utterance && !utterance.firstResultAt) {
                        utterance.firstResultAt = ts;
                        if (state.isLive) setStatus('TRADUCIENDO (REALTIME)');
                    }
                } else if (eventName === 'source_transcript') {
                    const utterance = realtimeAudioUtterances.get(lang);
                    if (utterance) utterance.sourceText = payload.text || '';
                } else if (eventName === 'final') {
                    const utterance = realtimeAudioUtterances.get(lang);
                    realtimeAudioUtterances.delete(lang);
                    if (!utterance || !payload.text) return;
                    utterance.timeline = payload.timeline || utterance.timeline || [];
                    handleRealtimeAudioFinal(utterance, payload.text, ts, lang);
                } else if (eventName === 'error') {
                    console.warn(`Realtime (audio) error [${lang}]:`, payload);
                }
            });
        }

        /**
         * Reporta una frase completa del brazo de audio (para el idioma `lang`) al mismo
         * camino de persistencia/relay/broadcast que el motor actual, con los 3 timestamps
         * clave (inicio de habla real, primer resultado, resultado final) para el benchmark.
         */
        async function handleRealtimeAudioFinal(utterance, translatedText, finishedAt, lang) {
            const rLang = lang.split('-')[0];
            const rVar = lang.includes('-') ? lang : '';
            const originalText = utterance.sourceText || '(sin transcript de origen)';

            const result = await reportRealtimeBenchmark({
                realtimeBenchmarkUrl: config.realtimeBenchmarkUrl,
                csrfToken,
            }, {
                sesion_id: config.sesionId,
                texto_original: originalText,
                texto_traducido: translatedText,
                idioma: lang,
                variante: rVar,
                arm: 'audio',
                t_speech_start: utterance.speechStartedAt,
                t_stt_final: utterance.speechStartedAt,
                t_realtime_sent: utterance.speechStartedAt,
                t_realtime_received: finishedAt,
                t_first_result: utterance.firstResultAt,
                timeline: Array.isArray(utterance.timeline) ? utterance.timeline : [],
            });

            guardarTranscripcion(translatedText, lang);
            emitSocketMessage({
                id: result.message?.id || crypto.randomUUID(),
                texto: translatedText,
                idioma: rLang,
                variante: rVar,
                genero: state.gender,
                tipo: 'traduccion',
                available_at: result.message?.available_at || Math.floor(utterance.speechStartedAt / 1000),
                published_at: result.message?.published_at || Math.floor(Date.now() / 1000),
            });
        }

        // Brazo B (gpt-realtime-translate, streaming continuo): la propia heuristica de
        // segmentacion (ver realtime-translate-dedicated-poc.js) decide cuando una "frase"
        // termino - SOLO para poder medir el benchmark, no cambia el comportamiento real.
        const realtimeDedicatedTranslator = realtimeDedicatedEngineActive ? createDedicatedTranslationTranslator({
            realtimeTokenUrl: config.realtimeTokenUrl,
            csrfToken,
            sesionId: config.sesionId,
            realtimePocLanguage,
        }) : null;
        const realtimeSegmentGapMs = Number(config.realtimeSegmentGapMs) || 900;
        const realtimeDedicatedSegmenter = realtimeDedicatedTranslator
            ? createDedicatedTranslationSegmenter(realtimeSegmentGapMs, (eventName, segment, ts) => {
                if (eventName === 'first_translation_delta') {
                    if (state.isLive) setStatus('TRADUCIENDO (REALTIME B)');
                    return;
                }
                if (eventName === 'end') {
                    handleRealtimeDedicatedFinal(segment, ts);
                }
            })
            : null;

        if (realtimeDedicatedTranslator) {
            realtimeDedicatedTranslator.setEventHandler((eventName, text, ts) => {
                if (eventName === 'transcript_delta') {
                    realtimeDedicatedSegmenter.onTranscriptDelta(text, ts);
                } else if (eventName === 'translation_delta') {
                    realtimeDedicatedSegmenter.onTranslationDelta(text, ts);
                } else if (eventName === 'error') {
                    console.warn('PoC Realtime (brazo B) error:', text);
                }
            });
        }

        /**
         * Reporta un segmento (heuristica propia) del brazo B al mismo camino de
         * persistencia/relay/broadcast que el motor actual, con los 4 timestamps pedidos:
         * inicio (proxy, primer delta observado), primer delta de transcripcion, primer
         * delta de traduccion, y fin de segmento (heuristica de gap de silencio).
         */
        async function handleRealtimeDedicatedFinal(segment, finishedAt) {
            const lang = realtimePocLanguage;
            const rLang = lang.split('-')[0];
            const rVar = lang.includes('-') ? lang : '';
            const translatedText = segment.outputText.trim();
            const originalText = segment.inputText.trim() || '(sin transcript de origen)';

            const result = await reportRealtimeBenchmark({
                realtimeBenchmarkUrl: config.realtimeBenchmarkUrl,
                csrfToken,
            }, {
                sesion_id: config.sesionId,
                texto_original: originalText,
                texto_traducido: translatedText,
                idioma: lang,
                variante: rVar,
                arm: 'translate_dedicated',
                // t_speech_start es una APROXIMACION en este brazo (primer delta
                // observado): gpt-realtime-translate no expone VAD, no hay una señal real
                // de "inicio de audio" - ver limitacion documentada en el informe.
                t_speech_start: segment.startedAt,
                t_stt_final: finishedAt,
                t_realtime_sent: segment.startedAt,
                t_realtime_received: finishedAt,
                t_first_result: segment.firstTranslationDeltaAt,
                t_first_transcript_delta: segment.firstTranscriptDeltaAt,
            });

            guardarTranscripcion(translatedText, lang);
            emitSocketMessage({
                id: result.message?.id || crypto.randomUUID(),
                texto: translatedText,
                idioma: rLang,
                variante: rVar,
                genero: state.gender,
                tipo: 'traduccion',
                available_at: result.message?.available_at || Math.floor(segment.startedAt / 1000),
                published_at: result.message?.published_at || Math.floor(Date.now() / 1000),
            });
        }

        const availableVoiceProfiles = normalizeVoiceProfiles(config.availableVoices || []);
        let currentVoice = config.translationSettings?.voice || 'marin';
        const socket = config.socketUrl ? io(config.socketUrl, {
            transports: ['websocket', 'polling'],
            reconnection: true,
            reconnectionAttempts: Infinity,
            reconnectionDelay: 2000,
            reconnectionDelayMax: 2000,
            randomizationFactor: 0,
            timeout: 8000,
        }) : null;
        const forceBrowserSpeechRecognition = true;
        const isDemoSession = Boolean(
            config.demoExpiresAt
            || String(config.slug || '').toLowerCase().includes('demo')
            || String(config.brandTitle || '').toLowerCase().includes('demo')
        );
        if (forceBrowserSpeechRecognition || isDemoSession) {
            config.useDeepgram = false;
            config.deepgramTokenUrl = null;
        }
        const backendPipelineDefault = Boolean(audioProcessUrl && config.useBackendAudioPipeline);
        let backendPipelineEnabled = backendPipelineDefault;
        let backendFailureNotified = false;

        function activateBrowserFallback(reason) {
            if (!backendPipelineEnabled) return;
            if (captureSource === 'tab') {
                // En modo pestaña no hay microfono al que caer: el navegador no transcribe
                // audio de pestaña, asi que seguimos reintentando con OpenAI por segmento.
                if (!backendFailureNotified) {
                    backendFailureNotified = true;
                    updateUIBox('OpenAI tardo en responder (' + (reason || 'sin detalle') + '). Reintentando con el audio de la pestaña.', 'Sistema');
                }
                return;
            }
            backendPipelineEnabled = false;
            try { stopMediaCapture(); } catch (e) {}
            pendingAudioChunks = [];
            if (!backendFailureNotified) {
                backendFailureNotified = true;
                updateUIBox(
                    'OpenAI no responde (' + (reason || 'sin detalle') + '). Cambiando a reconocimiento de voz del navegador.',
                    'Sistema'
                );
            }
            if (state.isLive && recognition) {
                try { recognition.stop(); } catch (e) {}
                setTimeout(() => {
                    try { recognition.lang = state.lang; recognition.start(); } catch (e) {}
                }, 200);
            }
        }

        let recognition = null;
        let mediaStream = null;
        let displayStream = null;
        // Fuente de audio: 'microphone' (voz por micro) o 'tab' (audio de pestaña/video).
        let captureSource = localStorage.getItem('spikia_master_source') === 'tab' ? 'tab' : 'microphone';
        let mediaRecorder = null;
        let segmentTimer = null;
        let recorderMime = '';
        const SEGMENT_MS = 4000;            // duracion de cada segmento de audio enviado a OpenAI
        const MAX_PENDING_AUDIO_CHUNKS = 4; // tope de backlog: descartamos lo viejo si OpenAI se atrasa
        let selectedMicDeviceId = localStorage.getItem('spikia_master_mic') || '';
        let micMeter = { ctx: null, analyser: null, source: null, raf: null, data: null };
        let micTestStream = null;
        let isProcessingChunk = false;
        let pendingAudioChunks = [];
        let lastInterimSent = '';
        let lastInterimSentAt = 0;
        let interimPublishTimer = null;
        let lastInterimCandidate = '';
        const listenerPresence = new Map();
        let intervals = { timer: null, visualizer: null, watchdog: null };
        const demoExpiresAt = config.demoExpiresAt ? Date.parse(config.demoExpiresAt) : null;
        let demoTimer = null;
        let state = {
            isLive: false,
            isRecognitionStarting: false,
            startTime: null,
            lang: 'es-ES',
            langBase: 'es',
            langName: 'Espanol Espana',
            listenerLang: null,
            externalAudioActive: false,
            gender: String(config.translationSettings?.voice_gender_profile || 'female').toLowerCase() === 'male' ? 'male' : 'female',
            saveMode: elements.saveMode ? elements.saveMode.value : 'resumen',
            lastTranscript: '',
            lastTranscriptAt: 0,
            lastInterimTranslationText: '',
            lastInterimTranslationAt: 0,
            cooldownUntil: 0,
            // PoC/benchmark Realtime: timestamp (Date.now()) del primer interim de la
            // frase en curso, usado como proxy de "inicio de habla" para medir el delay
            // completo. Se resetea despues de publicar cada frase final.
            utteranceStartedAt: 0,
        };

        // Cronometro persistente: el acumulado y el inicio del segmento actual viven en el
        // servidor (Sesion.live_accumulated_seconds / live_started_at), no solo en memoria del
        // navegador. Asi sobrevive recargas, pausas y cierres inesperados de pestaña sin
        // resetearse a 00:00:00 -- antes se perdia todo con cualquiera de esos tres casos.
        let liveAccumulatedSeconds = Number(config.liveAccumulatedSeconds || 0);
        let liveStartedAtMs = config.liveStartedAt ? Date.parse(config.liveStartedAt) : null;

        function currentLiveElapsedMs() {
            const runningMs = liveStartedAtMs ? Math.max(0, Date.now() - liveStartedAtMs) : 0;
            return liveAccumulatedSeconds * 1000 + runningMs;
        }

        function formatElapsed(ms) {
            const h = String(Math.floor(ms / 3600000)).padStart(2, '0');
            const m = String(Math.floor((ms % 3600000) / 60000)).padStart(2, '0');
            const s = String(Math.floor((ms % 60000) / 1000)).padStart(2, '0');
            return `${h}:${m}:${s}`;
        }

        function renderLiveTimer() {
            elements.timerElement.innerText = formatElapsed(currentLiveElapsedMs());
        }

        async function startLiveTimerOnServer() {
            if (!liveTimerStartUrl) return;
            try {
                const response = await fetch(liveTimerStartUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    credentials: 'same-origin',
                });
                if (!response.ok) return;
                const payload = await readJsonResponse(response, 'No se pudo iniciar el cronometro.');
                if (payload?.live_started_at) {
                    liveStartedAtMs = Date.parse(payload.live_started_at);
                }
                if (typeof payload?.live_accumulated_seconds === 'number') {
                    liveAccumulatedSeconds = payload.live_accumulated_seconds;
                }
            } catch (error) {
                console.warn('No se pudo sincronizar el inicio del cronometro con el servidor:', error);
            }
        }

        async function stopLiveTimerOnServer() {
            if (!liveTimerStopUrl) return;
            try {
                const response = await fetch(liveTimerStopUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    credentials: 'same-origin',
                });
                if (!response.ok) return;
                const payload = await readJsonResponse(response, 'No se pudo detener el cronometro.');
                if (typeof payload?.live_accumulated_seconds === 'number') {
                    liveAccumulatedSeconds = payload.live_accumulated_seconds;
                    liveStartedAtMs = null;
                    if (!isDemoSession) {
                        renderLiveTimer();
                    }
                }
            } catch (error) {
                console.warn('No se pudo sincronizar el fin del cronometro con el servidor:', error);
            }
        }

        function isDemoExpired() {
            return Boolean(demoExpiresAt && Date.now() >= demoExpiresAt);
        }

        function stopLiveSession() {
            state.isLive = false;
            state.isRecognitionStarting = false;
            if (realtimeTranslator) realtimeTranslator.close();
            if (realtimeAudioTranslator) realtimeAudioTranslator.close();
            if (realtimeDedicatedTranslator) realtimeDedicatedTranslator.close();
            sendInterimPreview('');
            if (interimPublishTimer) {
                clearTimeout(interimPublishTimer);
                interimPublishTimer = null;
            }
            if (recognition && recognition.isDeepgram) {
                try { recognition.stop(); } catch (e) {}
                stopMediaStreamTracks();
            } else if (backendPipelineEnabled) {
                stopMediaCapture();
                if (recognition) {
                    try { recognition.stop(); } catch (e) {}
                }
            } else if (recognition) {
                try { recognition.stop(); } catch (e) {}
                stopMediaStreamTracks();
            }
            stopMicMeter();
            setLiveUi(false);
        }

        function lockExpiredDemo() {
            stopLiveSession();
            elements.masterBtn.disabled = true;
            elements.masterBtn.classList.add('opacity-60', 'cursor-not-allowed', 'border-red-500/30');
            elements.statusDot.className = 'relative inline-flex rounded-full h-3 w-3 bg-red-500 shadow-[0_0_10px_#ef4444]';
            elements.statusText.innerText = 'DEMO VENCIDA';
            updateUIBox('Se acabo el tiempo del demo. Activa otra sesion para continuar.', 'Sistema');
        }

        function startDemoCountdown() {
            if (!demoExpiresAt) {
                return;
            }

            const tick = () => {
                const remaining = Math.max(0, demoExpiresAt - Date.now());
                const minutes = String(Math.floor(remaining / 60000)).padStart(2, '0');
                const seconds = String(Math.floor((remaining % 60000) / 1000)).padStart(2, '0');

                if (!state.isLive) {
                    elements.timerElement.innerText = `${minutes}:${seconds}`;
                }

                if (remaining <= 0) {
                    clearInterval(demoTimer);
                    lockExpiredDemo();
                }
            };

            tick();
            demoTimer = setInterval(tick, 1000);
        }

        function inferVoiceGender(value) {
            return ['marin', 'coral', 'shimmer'].includes(String(value || '').toLowerCase()) ? 'female' : 'male';
        }

        function normalizeVoiceProfiles(profiles) {
            return (Array.isArray(profiles) ? profiles : []).map((profile) => {
                if (typeof profile === 'string') {
                    return {
                        value: profile,
                        label: profile,
                        gender: inferVoiceGender(profile),
                    };
                }

                return {
                    value: profile?.value || '',
                    label: profile?.label || profile?.value || '',
                    gender: profile?.gender || inferVoiceGender(profile?.value || ''),
                };
            }).filter((profile) => profile.value);
        }

        function getVoicesForGender(gender) {
            const normalizedGender = gender === 'male' ? 'male' : 'female';
            const matches = availableVoiceProfiles.filter((profile) => profile.gender === normalizedGender);
            return matches.length ? matches : availableVoiceProfiles;
        }

        function pickVoiceForGender(gender, preferredVoice = currentVoice) {
            const voices = getVoicesForGender(gender);
            const preferred = voices.find((profile) => profile.value === preferredVoice);
            return (preferred || voices[0] || { value: preferredVoice || 'marin' }).value;
        }

        function renderVoiceSelect(gender, preferredVoice = currentVoice) {
            if (!elements.voiceSelect) {
                return;
            }

            const voices = getVoicesForGender(gender);
            const nextVoice = pickVoiceForGender(gender, preferredVoice);
            elements.voiceSelect.innerHTML = voices.map((profile) => `
                <option value="${profile.value}" data-gender="${profile.gender}">${String(profile.label || profile.value).toUpperCase()}</option>
            `).join('');
            elements.voiceSelect.value = nextVoice;
            currentVoice = nextVoice;
        }

        function recognitionWatchdog() {
            if (!state.isLive) return;
            if (!recognition || recognition.isDeepgram) return;
            // Si el pipeline backend lleva el control con un mic especifico, Web Speech
            // no esta corriendo: no hay nada que revivir.
            if (backendPipelineEnabled && selectedMicDeviceId) return;
            if (state.isRecognitionStarting) return;

            // Chrome a veces deja de emitir resultados sin disparar onend: si llevamos
            // demasiado tiempo sin nada, reiniciamos el reconocimiento de forma limpia.
            if ((Date.now() - (state.lastResultAt || 0)) <= 9000) return;

            state.isRecognitionStarting = true;
            try { recognition.stop(); } catch (e) {}
            setTimeout(() => {
                state.isRecognitionStarting = false;
                if (!state.isLive) return;
                try {
                    recognition.lang = state.lang;
                    recognition.start();
                    state.lastResultAt = Date.now();
                } catch (e) {}
            }, 250);
        }

        function setLiveUi(active) {
            if (active) {
                state.startTime = new Date();
                state.lastTranscript = '';
                state.lastTranscriptAt = 0;
                state.lastResultAt = Date.now();
                updateSelectedLanguageLabel(state.lang);
                intervals.watchdog = setInterval(recognitionWatchdog, 3000);

                // Arranque optimista con el reloj local para que el boton responda al instante;
                // startLiveTimerOnServer() reconcilia con el valor autoritativo del servidor
                // apenas responde (y es idempotente: si ya estaba en vivo -recarga de pagina-
                // no pisa el inicio real, solo confirma el estado).
                if (!liveStartedAtMs) {
                    liveStartedAtMs = Date.now();
                }
                renderLiveTimer();
                intervals.timer = setInterval(renderLiveTimer, 1000);
                startLiveTimerOnServer();

                elements.statusDot.className = 'relative inline-flex rounded-full h-3 w-3 bg-cyan-400 animate-pulse shadow-[0_0_10px_#22d3ee]';
                elements.statusText.innerText = 'LIVE RUNNING';
                if (elements.btnBg) elements.btnBg.style.opacity = '1';
                intervals.visualizer = setInterval(() => {
                    elements.bars.forEach((b) => (b.style.height = `${Math.random() * 60 + 20}%`));
                }, 150);
                updateCaptureStatusBadge();
                return;
            }

            clearInterval(intervals.timer);
            clearInterval(intervals.visualizer);
            clearInterval(intervals.watchdog);
            intervals.timer = null;
            intervals.visualizer = null;
            intervals.watchdog = null;

            // Congelar el cronometro en el tiempo real transcurrido -- YA NO se resetea a
            // 00:00:00. Calculo optimista local, stopLiveTimerOnServer() reconcilia despues.
            if (liveStartedAtMs) {
                liveAccumulatedSeconds += Math.max(0, Math.round((Date.now() - liveStartedAtMs) / 1000));
                liveStartedAtMs = null;
            }
            if (!isDemoSession) {
                renderLiveTimer();
            }
            stopLiveTimerOnServer();

            elements.statusDot.className = 'relative inline-flex rounded-full h-3 w-3 bg-zinc-700';
            elements.statusText.innerText = 'SYSTEM STANDBY';
            if (elements.btnBg) elements.btnBg.style.opacity = '0';
            elements.bars.forEach((b) => (b.style.height = '15%'));
            updateCaptureStatusBadge();
        }

        function collapseAdjacentRepeatedWords(text) {
            return String(text || '')
                .trim()
                .replace(/\s+/g, ' ')
                .replace(/\b(\w+)(?:\s+\1\b)+/gi, '$1');
        }

        function updateUIBox(text, langLabel) {
            const ph = document.getElementById('placeholder-text');
            if (ph) ph.remove();
            const p = document.createElement('div');
            p.className = 'p-6 mb-4 bg-zinc-900/50 border border-white/10 rounded-[2rem] animate-in fade-in slide-in-from-bottom-4 duration-700';
            p.innerHTML = `<span class="text-indigo-500 font-black text-[10px] block mb-2 uppercase tracking-[0.2em]">${langLabel}</span><p class="text-2xl font-light leading-relaxed text-white">${collapseAdjacentRepeatedWords(text)}</p>`;
            elements.transcriptionBox.appendChild(p);
            elements.transcriptionBox.scrollTo({ top: elements.transcriptionBox.scrollHeight, behavior: 'smooth' });
        }

        function setStatus(text) {
            if (elements.statusText) {
                elements.statusText.innerText = text;
            }
        }

        function upsertInterimPreview(text, langLabel) {
            const normalized = collapseAdjacentRepeatedWords(text);
            const ph = document.getElementById('placeholder-text');
            if (ph) ph.remove();

            if (!normalized) {
                const existing = document.getElementById('master-interim-box');
                if (existing) existing.remove();
                return;
            }

            let box = document.getElementById('master-interim-box');
            if (!box) {
                box = document.createElement('div');
                box.id = 'master-interim-box';
                box.className = 'p-6 mb-4 bg-indigo-600/10 border border-indigo-400/20 rounded-[2rem]';
                elements.transcriptionBox.appendChild(box);
            }

            box.innerHTML = `<span class="text-cyan-300 font-black text-[10px] block mb-2 uppercase tracking-[0.2em]">${langLabel} · EN VIVO</span><p class="text-2xl font-light leading-relaxed text-white/90">${normalized}</p>`;
            elements.transcriptionBox.scrollTo({ top: elements.transcriptionBox.scrollHeight, behavior: 'smooth' });
        }

        function stopMediaStreamTracks() {
            if (mediaStream) {
                mediaStream.getTracks().forEach((track) => {
                    try {
                        track.stop();
                    } catch (error) {
                        //
                    }
                });
                mediaStream = null;
            }

            // El stream de "audio de pestaña" guarda ademas el track de video vivo
            // para que el navegador no corte la captura: lo liberamos aqui.
            if (displayStream) {
                displayStream.getTracks().forEach((track) => {
                    try {
                        track.stop();
                    } catch (error) {
                        //
                    }
                });
                displayStream = null;
            }
        }

        function stopMediaCapture() {
            if (segmentTimer) {
                clearTimeout(segmentTimer);
                segmentTimer = null;
            }

            const recorder = mediaRecorder;
            mediaRecorder = null;

            if (recorder && recorder.state !== 'inactive') {
                try {
                    recorder.stop();
                } catch (error) {
                    //
                }
            }

            stopMediaStreamTracks();
        }

        function showRecognitionError(message) {
            console.error(message);
            updateUIBox(message, 'Sistema');
            state.isLive = false;
            state.isRecognitionStarting = false;
            sendInterimPreview('');
            stopMediaCapture();
            stopMicMeter();
            pendingAudioChunks = [];
            isProcessingChunk = false;
            setLiveUi(false);
            upsertInterimPreview('', state.langName);
        }

        async function ensureMicrophoneAccess() {
            if (!navigator.mediaDevices?.getUserMedia) {
                throw new Error('Este navegador no permite acceso al microfono desde esta pagina.');
            }

            const audioConstraints = {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
                channelCount: 1,
            };

            // Forzar el microfono que el usuario eligio en el selector.
            // El pipeline de audio por backend (OpenAI) SI respeta este deviceId,
            // a diferencia del reconocimiento de voz del navegador.
            if (selectedMicDeviceId) {
                audioConstraints.deviceId = { exact: selectedMicDeviceId };
            }

            return navigator.mediaDevices.getUserMedia({ audio: audioConstraints });
        }

        // Captura el audio de una pestaña/ventana/pantalla (p. ej. un video reproduciendose).
        // Devuelve un stream SOLO de audio (sin echoCancellation ni noiseSuppression para no
        // recortar el sonido) y mantiene vivo el track de video en displayStream para que el
        // navegador no corte la captura.
        async function getDisplayAudioStream() {
            if (!navigator.mediaDevices?.getDisplayMedia) {
                throw new Error('Tu navegador no permite capturar el audio de una pestaña. Usa Chrome o Edge en computador.');
            }

            let stream;
            try {
                stream = await navigator.mediaDevices.getDisplayMedia({
                    video: true,
                    audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false },
                });
            } catch (error) {
                throw new Error('No se inicio la captura. Acepta el permiso y elige la pestaña/ventana del video.');
            }

            const audioTracks = stream.getAudioTracks();
            if (!audioTracks.length) {
                stream.getTracks().forEach((track) => { try { track.stop(); } catch (e) {} });
                throw new Error('No marcaste "Compartir audio de la pestaña". Vuelve a iniciar y activa esa casilla.');
            }

            displayStream = stream;
            // Si el usuario detiene el compartir desde la barra del navegador, cerramos la sesion.
            audioTracks[0].addEventListener('ended', () => {
                if (state.isLive) {
                    try { stopLiveSession(); } catch (e) {}
                }
            });

            // Para grabar solo necesitamos el audio; el video sigue vivo dentro de displayStream.
            return new MediaStream(audioTracks);
        }

        function setCaptureSource(source) {
            captureSource = source === 'tab' ? 'tab' : 'microphone';
            localStorage.setItem('spikia_master_source', captureSource);

            [elements.sourceMic, elements.sourceTab].forEach((btn) => {
                if (!btn) return;
                const active = btn.dataset.source === captureSource;
                btn.classList.toggle('bg-indigo-600', active);
                btn.classList.toggle('text-white', active);
                btn.classList.toggle('border-indigo-600', active);
            });

            const isTab = captureSource === 'tab';
            if (elements.micSelect) elements.micSelect.disabled = isTab;
            if (elements.micTest) elements.micTest.disabled = isTab;
            if (elements.micHint) {
                if (isTab) {
                    elements.micHint.textContent = config.hasMeetingLink
                        ? 'Para traducir Zoom/Meet: ábrelo en otra pestaña → aquí pulsa Iniciar → en el selector del navegador elige esa pestaña y marca "Compartir audio". Solo en Chrome/Edge de escritorio.'
                        : 'Comparte el audio de una pestaña o video: pulsa Iniciar y en el selector del navegador elige esa pestaña marcando "Compartir audio". Solo en Chrome/Edge de escritorio.';
                } else {
                    elements.micHint.textContent = 'Elige tu micrófono y pulsa Probar: la barra debe moverse al hablar.';
                }
            }

            updateCaptureStatusBadge();
        }

        // Indicador persistente tipo Meet ("compartiendo esta pestaña") - antes no habia
        // ninguna señal fija en pantalla de que fuente se esta capturando mientras esta en
        // vivo, solo el medidor de nivel (que ademas no corre para audio de pestaña).
        function updateCaptureStatusBadge() {
            if (!elements.captureStatusBadge) return;

            if (!state.isLive) {
                elements.captureStatusBadge.classList.add('hidden');
                return;
            }

            elements.captureStatusBadge.classList.remove('hidden');
            elements.captureStatusBadge.textContent = captureSource === 'tab'
                ? '🖥️ Capturando audio de una pestaña compartida'
                : '🎤 Capturando audio del micrófono';
        }

        // --- Selector de microfono + medidor de nivel en vivo ---------------
        function setMicHint(text, isError = false) {
            if (!elements.micHint) return;
            elements.micHint.textContent = text;
            elements.micHint.classList.toggle('text-red-400', isError);
            elements.micHint.classList.toggle('text-zinc-600', !isError);
        }

        function startMicMeter(stream) {
            stopMicMeter();
            if (!stream || !elements.micLevel) return;

            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                const ctx = new Ctx();
                const source = ctx.createMediaStreamSource(stream);
                const analyser = ctx.createAnalyser();
                analyser.fftSize = 512;
                source.connect(analyser);

                const data = new Uint8Array(analyser.fftSize);
                micMeter = { ctx, analyser, source, raf: null, data };

                const tick = () => {
                    analyser.getByteTimeDomainData(data);
                    let sum = 0;
                    for (let i = 0; i < data.length; i++) {
                        const v = (data[i] - 128) / 128;
                        sum += v * v;
                    }
                    const rms = Math.sqrt(sum / data.length);
                    const level = Math.min(100, Math.round(rms * 240));
                    elements.micLevel.style.width = level + '%';
                    micMeter.raf = requestAnimationFrame(tick);
                };
                tick();
            } catch (error) {
                // Si Web Audio falla, el medidor simplemente no se muestra.
            }
        }

        function stopMicMeter() {
            if (micMeter.raf) cancelAnimationFrame(micMeter.raf);
            try { micMeter.source && micMeter.source.disconnect(); } catch (e) {}
            try { micMeter.ctx && micMeter.ctx.close(); } catch (e) {}
            micMeter = { ctx: null, analyser: null, source: null, raf: null, data: null };
            if (elements.micLevel) elements.micLevel.style.width = '0%';
        }

        function stopMicTest() {
            if (micTestStream) {
                micTestStream.getTracks().forEach((t) => { try { t.stop(); } catch (e) {} });
                micTestStream = null;
            }
            stopMicMeter();
            if (elements.micTest) elements.micTest.textContent = 'Probar';
        }

        async function populateMicDevices() {
            if (!elements.micSelect || !navigator.mediaDevices?.enumerateDevices) return;
            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                const inputs = devices.filter((d) => d.kind === 'audioinput');
                const previous = selectedMicDeviceId;

                elements.micSelect.innerHTML = '<option value="">Micrófono predeterminado</option>';
                inputs.forEach((device, index) => {
                    const option = document.createElement('option');
                    option.value = device.deviceId;
                    option.textContent = device.label || `Micrófono ${index + 1}`;
                    elements.micSelect.appendChild(option);
                });

                // Restaurar seleccion previa si el dispositivo sigue presente.
                if (previous && inputs.some((d) => d.deviceId === previous)) {
                    elements.micSelect.value = previous;
                } else if (previous) {
                    selectedMicDeviceId = '';
                    localStorage.removeItem('spikia_master_mic');
                }
            } catch (error) {
                // Sin permiso aun: las etiquetas llegan tras conceder el microfono.
            }
        }

        async function toggleMicTest() {
            if (micTestStream) {
                stopMicTest();
                setMicHint('Prueba detenida. Pulsa Probar para revisar de nuevo.');
                return;
            }

            try {
                if (elements.micTest) elements.micTest.textContent = 'Detener';
                setMicHint('Habla ahora: la barra debe moverse con tu voz.');
                micTestStream = await ensureMicrophoneAccess();
                // Ya con permiso, podemos mostrar las etiquetas reales de los dispositivos.
                await populateMicDevices();
                startMicMeter(micTestStream);
            } catch (error) {
                stopMicTest();
                setMicHint('No se pudo abrir el micrófono seleccionado. Elige otro dispositivo.', true);
            }
        }

        if (elements.micSelect) {
            elements.micSelect.value = selectedMicDeviceId;
            elements.micSelect.addEventListener('change', () => {
                selectedMicDeviceId = elements.micSelect.value || '';
                if (selectedMicDeviceId) {
                    localStorage.setItem('spikia_master_mic', selectedMicDeviceId);
                } else {
                    localStorage.removeItem('spikia_master_mic');
                }
                if (micTestStream) {
                    stopMicTest();
                    toggleMicTest();
                } else {
                    setMicHint('Micrófono seleccionado. Pulsa Probar para verificar el nivel.');
                }
            });
        }
        if (elements.micTest) {
            elements.micTest.addEventListener('click', toggleMicTest);
        }
        if (elements.sourceMic) {
            elements.sourceMic.addEventListener('click', () => {
                if (micTestStream) stopMicTest();
                setCaptureSource('microphone');
            });
        }
        if (elements.sourceTab) {
            elements.sourceTab.addEventListener('click', () => {
                if (micTestStream) stopMicTest();
                setCaptureSource('tab');
            });
        }
        setCaptureSource(captureSource);
        populateMicDevices();
        if (navigator.mediaDevices && typeof navigator.mediaDevices.addEventListener === 'function') {
            navigator.mediaDevices.addEventListener('devicechange', populateMicDevices);
        }

        // --- Bot de reunion: entra a Meet/Zoom como invitado y captura su audio. Es
        // independiente del flujo de microfono/pestaña de arriba: no comparte estado con
        // MediaRecorder, solo prende/apaga un proceso en el worker de /meet-bot y hace
        // polling del estado para mostrar un badge. ---
        let meetingBotPollTimer = null;
        let lastMeetingBotError = null;

        function setMeetingBotBadge(status) {
            if (!elements.meetingBotBadge) return;
            const labels = {
                idle: 'Inactivo', joining: 'Uniéndose…', active: 'En vivo', error: 'Error', stopped: 'Detenido',
            };
            elements.meetingBotBadge.textContent = labels[status] || 'Inactivo';
            elements.meetingBotBadge.classList.toggle('text-emerald-300', status === 'active');
            elements.meetingBotBadge.classList.toggle('text-amber-300', status === 'joining');
            elements.meetingBotBadge.classList.toggle('text-red-300', status === 'error');
        }

        async function pollMeetingBotStatus() {
            if (!meetingBotStatusUrl) return;
            try {
                const response = await fetch(meetingBotStatusUrl, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) return;
                const payload = await response.json();
                setMeetingBotBadge(payload?.status);
                if (payload?.status === 'error' && payload?.error && payload.error !== lastMeetingBotError) {
                    lastMeetingBotError = payload.error;
                    updateUIBox('Bot de reunión: ' + payload.error, 'Sistema');
                } else if (payload?.status !== 'error') {
                    lastMeetingBotError = null;
                }
                if (payload?.status === 'joining' || payload?.status === 'active') {
                    if (!meetingBotPollTimer) meetingBotPollTimer = setInterval(pollMeetingBotStatus, 5000);
                } else if (meetingBotPollTimer) {
                    clearInterval(meetingBotPollTimer);
                    meetingBotPollTimer = null;
                }
            } catch (error) {
                console.warn('No se pudo consultar el estado del bot de reunion:', error);
            }
        }

        if (elements.meetingBotStart) {
            elements.meetingBotStart.addEventListener('click', async () => {
                if (!meetingBotStartUrl) return;
                setMeetingBotBadge('joining');
                try {
                    const response = await fetch(meetingBotStartUrl, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                        credentials: 'same-origin',
                    });
                    const payload = await response.json().catch(() => null);
                    if (!response.ok) {
                        setMeetingBotBadge('error');
                        updateUIBox(payload?.message || 'No se pudo unir el bot a la reunión.', 'Sistema');
                        return;
                    }
                    pollMeetingBotStatus();
                } catch (error) {
                    setMeetingBotBadge('error');
                    console.warn('No se pudo iniciar el bot de reunion:', error);
                }
            });
        }
        if (elements.meetingBotStop) {
            elements.meetingBotStop.addEventListener('click', async () => {
                if (!meetingBotStopUrl) return;
                try {
                    const response = await fetch(meetingBotStopUrl, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                        credentials: 'same-origin',
                    });
                    const payload = await response.json().catch(() => null);
                    setMeetingBotBadge(payload?.status || 'stopped');
                } catch (error) {
                    console.warn('No se pudo detener el bot de reunion:', error);
                } finally {
                    if (meetingBotPollTimer) {
                        clearInterval(meetingBotPollTimer);
                        meetingBotPollTimer = null;
                    }
                }
            });
        }
        if (meetingBotStatusUrl) {
            pollMeetingBotStatus();
        }

        function isSecureOriginForMic() {
            return window.isSecureContext
                || location.hostname === 'localhost'
                || location.hostname === '127.0.0.1';
        }

        function formatLanguageLabel(langId) {
            if (!langId) return 'Espanol Espana';
            return (config.languageLabels && config.languageLabels[langId]) || String(langId).replace('-', ' ').toUpperCase();
        }

        function getLanguageBase(langId) {
            if (!langId) return '';
            return String(langId).split('-')[0].toLowerCase();
        }

        function getActiveListenerLang() {
            return state.listenerLang || localStorage.getItem('spikia_selected_listener_lang') || null;
        }

        function getRealtimeTargetLanguages() {
            const activeListenerLang = getActiveListenerLang();
            const combined = [
                ...(Array.isArray(targetLanguages) ? targetLanguages : []),
                ...(activeListenerLang ? [activeListenerLang] : []),
            ];

            return Array.from(new Set(combined.filter(Boolean)));
        }

        async function sendInterimPreview(text) {
            if (!interimUrl) {
                return;
            }

            const normalized = collapseAdjacentRepeatedWords(text || '');
            const now = Date.now();

            if (normalized === lastInterimSent && normalized !== '' && (now - lastInterimSentAt) < 120) {
                return;
            }

            lastInterimSent = normalized;
            lastInterimSentAt = now;

            try {
                await fetch(interimUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        texto: normalized,
                        idioma: state.langBase,
                        variante: state.lang,
                    }),
                });
            } catch (error) {
                console.warn('No se pudo actualizar el preview interino:', error);
            }
        }

        state.langName = formatLanguageLabel(state.lang);

        function updateVoiceProviderUI() {
            if (elements.voiceProviderLabel) {
                elements.voiceProviderLabel.textContent = 'ElevenLabs';
            }
        }

        function highlightLanguageButton(langId) {
            const select = document.getElementById('master-lang-select');
            if (select && select.value !== langId) {
                select.value = langId;
            }
        }

        function pruneInactiveListeners() {
            const now = Date.now();
            listenerPresence.forEach((listener, clientId) => {
                if ((now - (listener.lastSeenAt || 0)) > 15000) {
                    listenerPresence.delete(clientId);
                }
            });
        }

        function renderListenerPresence() {
            if (!elements.listenerPresenceList || !elements.listenerPresenceCount) {
                return;
            }

            pruneInactiveListeners();

            const listeners = Array.from(listenerPresence.values())
                .sort((a, b) => (b.lastSeenAt || 0) - (a.lastSeenAt || 0));

            elements.listenerPresenceCount.textContent = `${listeners.length} activos`;

            if (!listeners.length) {
                elements.listenerPresenceList.innerHTML = `
                    <div class="rounded-2xl border border-dashed border-white/10 bg-white/5 px-4 py-4 text-sm text-zinc-500">
                        Esperando listeners conectados...
                    </div>
                `;
                return;
            }

            elements.listenerPresenceList.innerHTML = listeners.map((listener) => {
                const language = listener.listenerLang || listener.lang || 'es-ES';
                const prettyLang = formatLanguageLabel(language);
                const audioBadge = listener.audioActive
                    ? '<span class="inline-flex items-center rounded-full border border-emerald-400/20 bg-emerald-400/10 px-2 py-1 text-[9px] font-black uppercase tracking-[0.25em] text-emerald-300">Audio ON</span>'
                    : '<span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-2 py-1 text-[9px] font-black uppercase tracking-[0.25em] text-zinc-400">Audio OFF</span>';

                return `
                    <article class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500">${listener.listenerLabel || 'Listener'}</p>
                                <p class="mt-2 text-sm font-black uppercase tracking-[0.18em] text-cyan-200">${prettyLang}</p>
                            </div>
                            ${audioBadge}
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-3">
                            <span class="text-[10px] font-black uppercase tracking-[0.25em] text-zinc-500">${language.toUpperCase()}</span>
                            <span class="text-[10px] font-black uppercase tracking-[0.25em] text-zinc-600">Hace ${Math.max(0, Math.floor((Date.now() - (listener.lastSeenAt || Date.now())) / 1000))}s</span>
                        </div>
                    </article>
                `;
            }).join('');
        }

        function upsertListenerPresence(payload = {}) {
            const clientId = String(payload.clientId || '').trim();
            if (!clientId) {
                return;
            }

            const existing = listenerPresence.get(clientId) || {};
            listenerPresence.set(clientId, {
                ...existing,
                clientId,
                listenerLabel: payload.listenerLabel || existing.listenerLabel || `Listener ${clientId.slice(0, 4).toUpperCase()}`,
                lang: payload.lang || existing.lang || 'es-ES',
                listenerLang: payload.listenerLang || payload.lang || existing.listenerLang || existing.lang || 'es-ES',
                audioActive: typeof payload.audioActive === 'boolean' ? payload.audioActive : (typeof payload.active === 'boolean' ? payload.active : (existing.audioActive || false)),
                lastSeenAt: Date.now(),
            });

            renderListenerPresence();
        }

        function hasAnyListenerAudioActive() {
            return Array.from(listenerPresence.values()).some((listener) => !!listener.audioActive);
        }

        function toListenerLang(masterLangId) {
            if (!masterLangId) return 'es-ES';
            if (masterLangId === 'es-419' || masterLangId === 'es-ES') return masterLangId;
            return String(masterLangId).split('-')[0];
        }

        function updateSelectedLanguageLabel(langId) {
            if (elements.selectedLanguageLabel) {
                elements.selectedLanguageLabel.textContent = `${formatLanguageLabel(langId)} · ${(langId || 'es-ES').toUpperCase()}`;
            }
        }

        function pauseOrResumeCapture(paused) {
            if (!mediaRecorder) {
                return;
            }

            if (paused && mediaRecorder.state === 'recording') {
                try {
                    mediaRecorder.pause();
                } catch (error) {
                    //
                }
                return;
            }

            if (!paused && state.isLive && mediaRecorder.state === 'paused') {
                try {
                    mediaRecorder.resume();
                } catch (error) {
                    //
                }
            }
        }

        highlightLanguageButton(state.lang);
        updateSelectedLanguageLabel(state.lang);

        // Continuidad del cronometro al cargar/recargar la pagina: si el servidor todavia
        // tenia el segmento "en vivo" abierto (recarga sin click en Detener, crash del
        // navegador, etc), seguimos el conteo en tiempo real sin resetear nada. Si no, se
        // muestra el acumulado congelado de la ultima vez en vez del 00:00:00 fijo de antes.
        if (liveStartedAtMs) {
            renderLiveTimer();
            intervals.timer = setInterval(renderLiveTimer, 1000);
        } else if (!isDemoSession && liveAccumulatedSeconds > 0) {
            renderLiveTimer();
        }

        function emitLanguageSelection(payload) {
            if (!socket) return;

            socket.emit('active-language-changed', {
                origin: 'master',
                ...payload,
            });
        }

        function emitSocketMessage(payload) {
            if (!socket) return;
            socket.emit('mensaje-congreso', payload);
        }

        async function readJsonResponse(response, fallbackMessage) {
            const contentType = response.headers.get('content-type') || '';
            const bodyText = await response.text();
            const cleanedText = bodyText
                .replace(/^\uFEFF+/, '')
                .replace(/[\uFEFF\u200B\u200C\u200D]/g, '')
                .trim();

            if (contentType.includes('application/json') || cleanedText.startsWith('{') || cleanedText.startsWith('[')) {
                try {
                    return JSON.parse(cleanedText);
                } catch (error) {
                    const jsonStart = cleanedText.search(/[\[{]/);
                    const jsonEnd = Math.max(cleanedText.lastIndexOf('}'), cleanedText.lastIndexOf(']'));

                    if (jsonStart !== -1 && jsonEnd !== -1 && jsonEnd > jsonStart) {
                        const sliced = cleanedText.slice(jsonStart, jsonEnd + 1);
                        try {
                            return JSON.parse(sliced);
                        } catch (innerError) {
                            //
                        }
                    }

                    throw new Error(fallbackMessage || 'La respuesta del servidor no es JSON valida.');
                }
            }

            const stripped = cleanedText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            throw new Error(stripped || fallbackMessage || 'La respuesta del servidor no es JSON valida.');
        }

        async function publicarMensaje(texto, idioma, variante = '', tipo = 'texto', id = '') {
            try {
                const response = await fetch(relayUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        texto,
                        idioma,
                        variante: variante || '',
                        genero: state.gender,
                        tipo,
                        id,
                    }),
                });

                if (!response.ok) {
                    throw new Error(`No se pudo publicar en la transmision (${response.status}). Inicia sesion de nuevo en localhost.`);
                }

                if (tipo === 'traduccion') {
                    setStatus('TRADUCCION ENVIADA');
                }
            } catch (e) {
                console.error('Relay error:', e);
                updateUIBox(String(e.message || 'No se pudo publicar en la transmision.'), 'Sistema');
            }
        }

        async function guardarTranscripcion(texto, idioma) {
            try {
                const response = await fetch(transcripcionUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        sesion_id: config.sesionId,
                        slug: config.slug,
                        texto,
                        idioma,
                        modo: state.saveMode,
                    }),
                });

                if (!response.ok) {
                    throw new Error(`No se pudo guardar la transcripcion (${response.status}).`);
                }
            } catch (e) {
                console.warn('Save error:', e);
            }
        }

        async function enviarTraduccionesFallback(texto, availableAt, speechStartedAt) {
            const idiomaOriginal = state.langBase;
            const varianteOriginal = state.lang;
            const originalId = crypto.randomUUID();
            let targetList = getRealtimeTargetLanguages()
                .filter((lang) => String(lang).toLowerCase() !== String(state.lang).toLowerCase());

            publicarMensaje(texto, idiomaOriginal, varianteOriginal, 'original', originalId);
            guardarTranscripcion(texto, state.lang);
            emitSocketMessage({
                id: originalId,
                texto,
                idioma: idiomaOriginal,
                variante: varianteOriginal,
                genero: state.gender,
                tipo: 'original',
                available_at: availableAt,
                published_at: Math.floor(Date.now() / 1000),
            });

            // PoC/benchmark Realtime: si esta activo y sano, el idioma del PoC se traduce
            // por Realtime en paralelo y se saca del lote normal (para no traducirlo dos
            // veces). Si Realtime falla para esta frase puntual, cae automaticamente al
            // camino actual solo para ese idioma - requisito: fallback sin romper nada.
            if (realtimeTranslator && realtimeTranslator.isHealthy() && targetList.includes(realtimePocLanguage)) {
                targetList = targetList.filter((lang) => lang !== realtimePocLanguage);
                handleRealtimeTranslation(texto, realtimePocLanguage, speechStartedAt, availableAt)
                    .catch((error) => {
                        console.warn('PoC Realtime fallo para esta frase, usando el motor actual como fallback:', error);
                        translateSingleViaBatchFallback(texto, realtimePocLanguage, availableAt);
                    });
            }

            // Brazo A (audio+VAD): los idiomas con conexion Realtime sana ya se traducen en
            // paralelo por su propia via (ver setupRealtimeAudioHandlers, disparado por el
            // VAD del servidor sobre el audio crudo, no por este texto). Se sacan del lote
            // clasico para no traducir dos veces la misma frase. Los que no tienen conexion
            // sana (aun conectando, o fallo) quedan en el lote clasico como fallback.
            if (realtimeAudioTranslator) {
                targetList = targetList.filter((lang) => !realtimeAudioTranslator.isHealthy(lang));
            }

            if (!targetList.length) {
                return;
            }

            // Un solo POST para TODOS los idiomas destino: las llamadas a OpenAI corren
            // concurrentes dentro del mismo request en el servidor (translateBatch), en vez
            // de que el navegador dispare N fetch() independientes que se serializan si el
            // servidor no tiene concurrencia real. Esto es lo que baja el delay de ~30s a
            // el tiempo de la llamada mas lenta del lote (no la suma de todas).
            try {
                setStatus('TRADUCIENDO');
                const res = await fetch('/traducciones/batch', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        sesion_id: config.sesionId,
                        texto,
                        idiomas: targetList,
                    }),
                });

                if (!res.ok) {
                    const errorPayload = await readJsonResponse(res, 'No se pudo leer el error de traduccion.').catch(() => ({}));
                    const message = errorPayload.message || errorPayload.error || `No se pudo traducir (${res.status}).`;
                    console.error('Batch translation fail: ' + message);
                    updateUIBox(message, 'Sistema');
                    return;
                }

                const data = await readJsonResponse(res, 'No se pudo leer la traduccion.');
                const translations = data.translations || {};

                Object.keys(translations).forEach((targetLang) => {
                    const entry = translations[targetLang];
                    if (!entry || entry.success === false || !entry.traduccion) {
                        console.error('Translation fail for ' + targetLang);
                        return;
                    }

                    const rLang = targetLang.split('-')[0];
                    const rVar = targetLang.includes('-') ? targetLang : '';
                    const translationId = entry.message?.id || crypto.randomUUID();
                    if (!entry.message) {
                        publicarMensaje(entry.traduccion, rLang, rVar, 'traduccion', translationId);
                    }
                    guardarTranscripcion(entry.traduccion, targetLang);
                    emitSocketMessage({
                        id: translationId,
                        texto: entry.traduccion,
                        idioma: rLang,
                        variante: rVar,
                        genero: state.gender,
                        tipo: 'traduccion',
                        available_at: entry.message?.available_at || availableAt,
                        published_at: entry.message?.published_at || Math.floor(Date.now() / 1000),
                    });
                });
            } catch (e) {
                console.error('Fetch error:', e);
                updateUIBox(String(e.message || 'No se pudo traducir el texto.'), 'Sistema');
            }
        }

        /**
         * PoC/benchmark Realtime: traduce UNA frase por la conexion Realtime ya abierta,
         * reporta el resultado + timestamps de cada etapa al mismo camino de
         * persistencia/relay/broadcast que usa el motor actual, y publica por Socket.IO
         * igual que la rama normal (ver informe "reduccion de delay a 1-2s").
         */
        async function handleRealtimeTranslation(texto, lang, speechStartedAt, sttFinalAt) {
            const tRealtimeSent = Date.now();
            const translated = await realtimeTranslator.translate(texto);
            const tRealtimeReceived = Date.now();

            const rLang = lang.split('-')[0];
            const rVar = lang.includes('-') ? lang : '';
            const translationId = crypto.randomUUID();

            const result = await reportRealtimeBenchmark({
                realtimeBenchmarkUrl: config.realtimeBenchmarkUrl,
                csrfToken,
            }, {
                sesion_id: config.sesionId,
                texto_original: texto,
                texto_traducido: translated,
                idioma: lang,
                variante: rVar,
                t_speech_start: speechStartedAt,
                t_stt_final: sttFinalAt,
                t_realtime_sent: tRealtimeSent,
                t_realtime_received: tRealtimeReceived,
            });

            guardarTranscripcion(translated, lang);
            emitSocketMessage({
                id: result.message?.id || translationId,
                texto: translated,
                idioma: rLang,
                variante: rVar,
                genero: state.gender,
                tipo: 'traduccion',
                available_at: result.message?.available_at || sttFinalAt,
                published_at: result.message?.published_at || Math.floor(Date.now() / 1000),
            });
        }

        /**
         * Red de seguridad si Realtime fallo/tardo demasiado para una frase puntual:
         * pide esa UNA traduccion por el camino /traducciones/batch de siempre, para el
         * idioma del PoC solamente (requisito: caer al motor actual sin romper nada).
         */
        function translateSingleViaBatchFallback(texto, lang, availableAt) {
            fetch('/traducciones/batch', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ sesion_id: config.sesionId, texto, idiomas: [lang] }),
            })
                .then((res) => res.json())
                .then((data) => {
                    const entry = (data.translations || {})[lang];
                    if (!entry || !entry.traduccion) return;
                    const rLang = lang.split('-')[0];
                    const rVar = lang.includes('-') ? lang : '';
                    guardarTranscripcion(entry.traduccion, lang);
                    emitSocketMessage({
                        id: entry.message?.id || crypto.randomUUID(),
                        texto: entry.traduccion,
                        idioma: rLang,
                        variante: rVar,
                        genero: state.gender,
                        tipo: 'traduccion',
                        available_at: entry.message?.available_at || availableAt,
                        published_at: entry.message?.published_at || Math.floor(Date.now() / 1000),
                    });
                })
                .catch((error) => console.error('Fallback batch tambien fallo:', error));
        }

        function shouldPublishInterimTranscript(text, now) {
            const words = text.split(/\s+/).filter(Boolean);
            if (words.length < 2 || text.length < 4) {
                return false;
            }

            if (text === state.lastInterimTranslationText && (now - state.lastInterimTranslationAt) < 2000) {
                return false;
            }

            if ((now - state.lastInterimTranslationAt) < 400) {
                return false;
            }

            return true;
        }

        function publishRecognizedTranscript(rawText, source = 'final') {
            const normalizedTranscript = collapseAdjacentRepeatedWords(rawText);
            const now = Date.now();

            if (normalizedTranscript.length <= 1) {
                return;
            }

            if (source === 'interim') {
                // PoC/benchmark Realtime: primer interim de esta frase = proxy de "inicio
                // de habla" para medir el delay completo (ver informe).
                if (!state.utteranceStartedAt) {
                    state.utteranceStartedAt = now;
                }
                if (state.isLive) {
                    setStatus('VOZ DETECTADA');
                    upsertInterimPreview(normalizedTranscript, state.langName);
                }
                sendInterimPreview(normalizedTranscript);
                return;
            }

            if (normalizedTranscript === state.lastTranscript) {
                return;
            }

            state.lastTranscript = normalizedTranscript;
            state.lastTranscriptAt = now;
            state.cooldownUntil = now + 250;
            const availableAt = now;
            const speechStartedAt = state.utteranceStartedAt || now;
            state.utteranceStartedAt = 0;

            upsertInterimPreview('', state.langName);
            sendInterimPreview('');
            if (state.isLive) {
                setStatus('FRASE DETECTADA');
                updateUIBox(normalizedTranscript, state.langName);
            }
            enviarTraduccionesFallback(normalizedTranscript, availableAt, speechStartedAt);
        }

        function scheduleInterimTranslation(rawText) {
            const normalized = collapseAdjacentRepeatedWords(rawText);
            if (!normalized || normalized === lastInterimCandidate) {
                return;
            }

            lastInterimCandidate = normalized;

            if (interimPublishTimer) {
                clearTimeout(interimPublishTimer);
            }

            interimPublishTimer = setTimeout(() => {
                interimPublishTimer = null;
                if (!state.isLive || backendPipelineEnabled) {
                    return;
                }

                publishRecognizedTranscript(lastInterimCandidate, 'interim');
            }, 650);
        }

        function getPreferredRecorderMimeType() {
            if (!window.MediaRecorder?.isTypeSupported) {
                return '';
            }

            const preferred = [
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/ogg;codecs=opus',
                'audio/mp4',
            ];

            return preferred.find((mimeType) => window.MediaRecorder.isTypeSupported(mimeType)) || '';
        }

        async function processAudioChunk(blob) {
            if (!blob || blob.size < 2048) {
                return;
            }

            const mimeType = blob.type || mediaRecorder?.mimeType || 'audio/webm';
            const extension = mimeType.includes('ogg')
                ? 'ogg'
                : mimeType.includes('mp4')
                    ? 'm4a'
                    : mimeType.includes('mpeg')
                        ? 'mp3'
                        : mimeType.includes('wav')
                            ? 'wav'
                            : 'webm';

            const formData = new FormData();
            formData.append('audio', blob, `master-${Date.now()}.${extension}`);
            formData.append('lang', state.lang);
            formData.append('lang_base', state.langBase);
            formData.append('gender', state.gender);
            formData.append('save_mode', state.saveMode);

            const response = await fetch(audioProcessUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: formData,
            });

            const payload = await readJsonResponse(response, 'No se pudo procesar el audio de la sesion.');

            if (!response.ok || payload.success === false) {
                throw new Error(payload.message || 'No se pudo procesar el audio de la sesion.');
            }

            if (!payload.original_transcript) {
                return;
            }

            const normalizedTranscript = collapseAdjacentRepeatedWords(payload.original_transcript);
            sendInterimPreview('');
            if (normalizedTranscript.length > 1 && normalizedTranscript !== state.lastTranscript) {
                state.lastTranscript = normalizedTranscript;
                state.lastTranscriptAt = Date.now();
                updateUIBox(normalizedTranscript, state.langName);
            }

            (payload.messages || []).forEach((message) => emitSocketMessage(message));
        }

        let lastDropWarningAt = 0;

        async function flushPendingAudioChunks() {
            if (isProcessingChunk) {
                return;
            }

            isProcessingChunk = true;

            while (pendingAudioChunks.length) {
                if (state.isLive && !state.externalAudioActive) {
                    const backlog = pendingAudioChunks.length - 1; // sin contar el que se esta por procesar
                    upsertInterimPreview(
                        backlog > 0
                            ? `Traduciendo con OpenAI... (${backlog} segmento${backlog === 1 ? '' : 's'} en espera)`
                            : 'Traduciendo con OpenAI...',
                        state.langName
                    );
                }
                const blob = pendingAudioChunks.shift();
                try {
                    await processAudioChunk(blob);
                } catch (error) {
                    console.error('Audio pipeline error:', error);
                    const message = String(error.message || '');
                    const looksLikeBackendOutage = /openai|transcribir|rate limit|502|503|429|quota/i.test(message);
                    if (looksLikeBackendOutage) {
                        activateBrowserFallback(message.slice(0, 80));
                        break;
                    }
                    updateUIBox(message || 'No se pudo procesar el audio en vivo.', 'Sistema');
                }
            }

            isProcessingChunk = false;

            if (state.isLive && !state.externalAudioActive) {
                upsertInterimPreview('Escuchando con OpenAI...', state.langName);
            }
        }

        function enqueueAudioChunk(blob) {
            pendingAudioChunks.push(blob);
            // Si OpenAI se atrasa, no dejamos crecer el backlog sin limite (evita
            // congelamiento progresivo y memoria): conservamos solo los mas recientes. Antes
            // esto pasaba en silencio - el presentador no tenia forma de saber que se perdio
            // audio. Ahora se avisa (con un throttle de 15s para no spamear el cuadro de
            // texto si la racha de atraso dura un rato).
            let dropped = 0;
            while (pendingAudioChunks.length > MAX_PENDING_AUDIO_CHUNKS) {
                pendingAudioChunks.shift();
                dropped++;
            }
            if (dropped > 0 && state.isLive) {
                const now = Date.now();
                if (now - lastDropWarningAt > 15000) {
                    lastDropWarningAt = now;
                    updateUIBox(
                        'OpenAI no da abasto: se descartó audio sin traducir. Si sigue pasando, usa el modo Micrófono en vez de Pestaña/Video.',
                        'Sistema'
                    );
                }
            }
            flushPendingAudioChunks();
        }

        function startBackendAudioPipeline(stream) {
            mediaStream = stream;
            pendingAudioChunks = [];
            isProcessingChunk = false;
            recorderMime = getPreferredRecorderMimeType();
            startNextAudioSegment();
            upsertInterimPreview('Escuchando con OpenAI...', state.langName);
        }

        // Graba en segmentos INDEPENDIENTES: cada uno se detiene y se reabre, de modo que
        // cada blob enviado a OpenAI es un archivo COMPLETO y valido (con cabecera del
        // contenedor). Antes se usaba start(timeslice), que produce trozos sin cabecera
        // que OpenAI rechaza -> de ahi el "No se pudo transcribir / OpenAI no responde".
        function startNextAudioSegment() {
            if (!state.isLive || !backendPipelineEnabled || !mediaStream) {
                return;
            }

            const parts = [];
            let recorder;
            try {
                recorder = recorderMime
                    ? new MediaRecorder(mediaStream, { mimeType: recorderMime })
                    : new MediaRecorder(mediaStream);
            } catch (error) {
                showRecognitionError('No se pudo iniciar la grabacion del microfono en esta sesion.');
                return;
            }

            mediaRecorder = recorder;

            recorder.ondataavailable = (event) => {
                if (event.data && event.data.size) {
                    parts.push(event.data);
                }
            };

            recorder.onstop = () => {
                if (parts.length) {
                    const blob = new Blob(parts, { type: recorderMime || 'audio/webm' });
                    if (blob.size > 2048) {
                        enqueueAudioChunk(blob);
                    }
                }
                // Encadenar el siguiente segmento solo si seguimos en vivo con este pipeline.
                if (state.isLive && backendPipelineEnabled) {
                    startNextAudioSegment();
                }
            };

            recorder.onerror = () => {
                showRecognitionError('No se pudo capturar el audio del microfono en esta sesion.');
            };

            try {
                recorder.start();
            } catch (error) {
                showRecognitionError('No se pudo capturar el audio del microfono en esta sesion.');
                return;
            }

            segmentTimer = setTimeout(() => {
                if (recorder.state !== 'inactive') {
                    try { recorder.stop(); } catch (e) {}
                }
            }, SEGMENT_MS);
        }

        function applyLanguageSelection(payload, shouldEmit = true) {
            const langId = payload.lang || state.lang;
            const langBase = payload.base || state.langBase;
            const langName = payload.name || state.langName;
            const speechLang = payload.speech || langId;

            state.lang = langId;
            state.langBase = langBase;
            state.langName = langName;
            highlightLanguageButton(langId);
            updateSelectedLanguageLabel(langId);

            if (recognition) {
                recognition.lang = speechLang;

                if (state.isLive && !backendPipelineEnabled) {
                    try { recognition.stop(); } catch (e) {}
                    setTimeout(() => {
                        try { recognition.lang = speechLang; recognition.start(); } catch (e) {}
                    }, 200);
                }
            }

            if (shouldEmit) {
                emitLanguageSelection({
                    lang: langId,
                    listenerLang: toListenerLang(langId),
                    base: langBase,
                    name: langName,
                    speech: speechLang,
                });
            }
        }

        function createDeepgramRecognizer() {
            let ws = null;
            let dgStream = null;
            let audioCtx = null;
            let audioSource = null;
            let audioProcessor = null;
            let currentLang = 'es';
            const listeners = { onresult: null, onerror: null, onend: null };
            let keepAliveTimer = null;
            const TARGET_SAMPLE_RATE = 16000;

            function mapLang(lang) {
                const v = String(lang || 'es').toLowerCase();
                if (v === 'es-419' || v === 'es-mx' || v === 'es-co' || v === 'es-ar') return 'es-419';
                if (v.startsWith('es')) return 'es';
                if (v.startsWith('en')) return 'en-US';
                if (v.startsWith('pt')) return 'pt-BR';
                if (v.startsWith('it')) return 'it';
                if (v.startsWith('fr')) return 'fr';
                return v.split('-')[0];
            }

            function downsampleAndConvert(float32Array, srcRate) {
                const ratio = srcRate / TARGET_SAMPLE_RATE;
                const newLen = Math.floor(float32Array.length / ratio);
                const out = new Int16Array(newLen);
                for (let i = 0; i < newLen; i++) {
                    const sample = float32Array[Math.floor(i * ratio)] || 0;
                    const clamped = Math.max(-1, Math.min(1, sample));
                    out[i] = clamped < 0 ? clamped * 0x8000 : clamped * 0x7FFF;
                }
                return out;
            }

            async function start(streamArg) {
                if (!config.deepgramTokenUrl) throw new Error('Deepgram no esta configurado.');
                if (!streamArg) throw new Error('Se requiere stream de audio.');

                const csrfTokenValue = csrfToken
                    || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                const tokenRes = await fetch(config.deepgramTokenUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfTokenValue,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ slug: config.slug, ttl: 1800 }),
                });
                if (!tokenRes.ok) {
                    throw new Error('No se pudo obtener token de Deepgram (' + tokenRes.status + ').');
                }
                const tokenData = await tokenRes.json();
                if (!tokenData.success || !tokenData.key) {
                    throw new Error(tokenData.message || 'Deepgram no devolvio token.');
                }

                dgStream = streamArg;

                const params = new URLSearchParams({
                    model: 'nova-2',
                    language: mapLang(currentLang),
                    interim_results: 'true',
                    smart_format: 'true',
                    endpointing: '350',
                    utterance_end_ms: '700',
                    vad_events: 'true',
                    punctuate: 'true',
                    numerals: 'true',
                    encoding: 'linear16',
                    sample_rate: String(TARGET_SAMPLE_RATE),
                    channels: '1',
                });

                const glossaryTerms = Array.isArray(config.glossaryTerms) ? config.glossaryTerms : [];
                const dgKeywordParts = [];
                glossaryTerms.slice(0, 100).forEach((term) => {
                    const clean = String(term || '').trim();
                    if (clean.length >= 2 && clean.length <= 60) {
                        dgKeywordParts.push(`keywords=${encodeURIComponent(clean + ':2')}`);
                    }
                });
                const keywordsSuffix = dgKeywordParts.length ? '&' + dgKeywordParts.join('&') : '';

                const wsUrl = 'wss://api.deepgram.com/v1/listen?' + params.toString() + keywordsSuffix;
                ws = new WebSocket(wsUrl, ['token', tokenData.key]);
                ws.binaryType = 'arraybuffer';

                ws.onopen = () => {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    audioCtx = new Ctx();
                    audioSource = audioCtx.createMediaStreamSource(dgStream);
                    audioProcessor = audioCtx.createScriptProcessor(2048, 1, 1);
                    audioProcessor.onaudioprocess = (e) => {
                        if (!ws || ws.readyState !== WebSocket.OPEN) return;
                        const inputData = e.inputBuffer.getChannelData(0);
                        const pcm = downsampleAndConvert(inputData, audioCtx.sampleRate);
                        ws.send(pcm.buffer);
                    };
                    audioSource.connect(audioProcessor);
                    audioProcessor.connect(audioCtx.destination);

                    keepAliveTimer = setInterval(() => {
                        if (ws && ws.readyState === WebSocket.OPEN) {
                            ws.send(JSON.stringify({ type: 'KeepAlive' }));
                        }
                    }, 8000);
                };

                ws.onmessage = (event) => {
                    try {
                        const data = JSON.parse(event.data);
                        if (data.type !== 'Results') return;
                        const alt = data.channel && data.channel.alternatives && data.channel.alternatives[0];
                        if (!alt) return;
                        const transcript = (alt.transcript || '').trim();
                        if (!transcript) return;
                        if (listeners.onresult) {
                            listeners.onresult({
                                text: transcript,
                                isFinal: Boolean(data.is_final),
                                speechFinal: Boolean(data.speech_final),
                            });
                        }
                    } catch (e) {
                        // ignore
                    }
                };

                ws.onerror = () => {
                    if (listeners.onerror) listeners.onerror({ error: 'deepgram-ws-error' });
                };

                ws.onclose = () => {
                    if (keepAliveTimer) { clearInterval(keepAliveTimer); keepAliveTimer = null; }
                    if (listeners.onend) listeners.onend();
                };
            }

            function stop() {
                try { audioProcessor && audioProcessor.disconnect(); } catch (e) {}
                try { audioSource && audioSource.disconnect(); } catch (e) {}
                try { audioCtx && audioCtx.close(); } catch (e) {}
                try {
                    if (ws && ws.readyState === WebSocket.OPEN) {
                        ws.send(JSON.stringify({ type: 'CloseStream' }));
                    }
                } catch (e) {}
                try { ws && ws.close(); } catch (e) {}
                if (keepAliveTimer) { clearInterval(keepAliveTimer); keepAliveTimer = null; }
                audioProcessor = null;
                audioSource = null;
                audioCtx = null;
                ws = null;
            }

            return {
                isDeepgram: true,
                get lang() { return currentLang; },
                set lang(v) { currentLang = v; },
                start,
                stop,
                set onresult(cb) { listeners.onresult = cb; },
                set onerror(cb) { listeners.onerror = cb; },
                set onend(cb) { listeners.onend = cb; },
            };
        }

        if (config.useDeepgram && config.deepgramTokenUrl) {
            recognition = createDeepgramRecognizer();
            recognition.lang = state.lang;

            recognition.onresult = (result) => {
                if (!state.isLive) return;
                setStatus('ESCUCHANDO VOZ');
                if (result.isFinal) {
                    if (interimPublishTimer) {
                        clearTimeout(interimPublishTimer);
                        interimPublishTimer = null;
                    }
                    publishRecognizedTranscript(result.text, 'final');
                } else {
                    upsertInterimPreview(result.text, state.langName);
                    sendInterimPreview(result.text);
                }
            };

            recognition.onerror = () => {
                if (state.isLive) {
                    showRecognitionError('Error de conexion con Deepgram. Revisa tu internet y vuelve a iniciar.');
                }
            };

            recognition.onend = () => {
                if (state.isLive) {
                    setStatus('CONEXION CERRADA');
                }
            };
        } else if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            recognition = new SpeechRecognition();
            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.lang = state.lang;

            recognition.onresult = (event) => {
                let finalTranscript = '';
                let interimTranscript = '';
                for (let i = event.resultIndex; i < event.results.length; ++i) {
                    if (event.results[i].isFinal) {
                        finalTranscript += event.results[i][0].transcript;
                    } else {
                        interimTranscript += event.results[i][0].transcript;
                    }
                }

                if (interimTranscript || finalTranscript) {
                    state.lastResultAt = Date.now();   // alimenta el watchdog
                }

                if (state.isLive) {
                    if (interimTranscript || finalTranscript) {
                        setStatus('ESCUCHANDO VOZ');
                    }
                    upsertInterimPreview(interimTranscript, state.langName);
                    sendInterimPreview(interimTranscript);
                }

                if (backendPipelineEnabled) {
                    return;
                }

                if (finalTranscript) {
                    if (interimPublishTimer) {
                        clearTimeout(interimPublishTimer);
                        interimPublishTimer = null;
                    }
                    publishRecognizedTranscript(finalTranscript, 'final');
                    return;
                }

                scheduleInterimTranslation(interimTranscript);
            };

            recognition.onend = () => {
                state.isRecognitionStarting = false;
                if (!state.isLive) {
                    return;
                }

                if (!backendPipelineEnabled && Date.now() < state.cooldownUntil) {
                    setTimeout(() => {
                        try { recognition.start(); } catch (e) {}
                    }, Math.max(300, state.cooldownUntil - Date.now()));
                    return;
                }

                const elapsed = Date.now() - state.lastTranscriptAt;
                if (!backendPipelineEnabled && elapsed < 350) {
                    setTimeout(() => {
                        try { recognition.start(); } catch (e) {}
                    }, 350);
                    return;
                }

                try { recognition.start(); } catch (e) {}
            };

            recognition.onerror = (e) => {
                state.isRecognitionStarting = false;
                if (e.error === 'no-speech' || e.error === 'aborted') {
                    return;
                }

                console.error('Recognition error:', e.error);
                if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
                    const secureHint = isSecureOriginForMic()
                        ? 'Permite el uso del microfono en el navegador y vuelve a intentar.'
                        : 'Abre esta pagina por HTTPS o por localhost. El navegador bloquea el microfono en esta URL.';
                    showRecognitionError(`El navegador bloqueo el microfono. ${secureHint}`);
                    return;
                }

                if (state.isLive) {
                    const delay = Date.now() < state.cooldownUntil ? Math.max(300, state.cooldownUntil - Date.now()) : 200;
                    setTimeout(() => { try { recognition.start(); } catch (ex) {} }, delay);
                }
            };
        }

        elements.masterBtn.addEventListener('click', async () => {
            if (isDemoExpired()) {
                lockExpiredDemo();
                return;
            }

            if (state.isLive) {
                stopLiveSession();
                return;
            }

            if (!isSecureOriginForMic()) {
                showRecognitionError('Esta pagina debe abrirse por HTTPS o por localhost para usar el microfono.');
                return;
            }

            const useDisplay = captureSource === 'tab';

            if (useDisplay && !audioProcessUrl) {
                showRecognitionError('La captura de pestaña necesita el procesamiento por OpenAI, que no esta disponible en esta sesion.');
                return;
            }
            if (!useDisplay && !backendPipelineEnabled && !recognition) {
                showRecognitionError('SpeechRecognition no esta disponible en este navegador.');
                return;
            }

            try {
                stopMicTest();   // liberar el dispositivo si habia una prueba activa
                state.isRecognitionStarting = true;

                // En modo pestaña forzamos el pipeline de OpenAI: el reconocimiento del
                // navegador solo escucha el microfono fisico, nunca el audio de la pestaña.
                backendPipelineEnabled = useDisplay ? Boolean(audioProcessUrl) : backendPipelineDefault;
                backendFailureNotified = false;

                const stream = useDisplay ? await getDisplayAudioStream() : await ensureMicrophoneAccess();
                state.isLive = true;
                state.isRecognitionStarting = false;

                // PoC/benchmark Realtime: UNA sola conexion para toda la sesion, se abre
                // aqui en paralelo (no bloquea el arranque del mic) - ver requisito de no
                // abrir/cerrar una conexion por frase. Si falla, isHealthy() sigue en
                // false y enviarTraduccionesFallback() cae al motor actual sin avisar.
                if (realtimeTranslator) {
                    realtimeTranslator.connect().catch((error) => {
                        console.warn('PoC Realtime: no se pudo conectar, se usara el motor actual como fallback.', error);
                    });
                }
                // Brazo A (audio+VAD): reusa el MISMO stream del microfono, sin pedir un
                // segundo permiso. Abre una conexion por CADA idioma destino de la sesion.
                // Limitado a modo microfono (no captura de pestaña).
                if (realtimeAudioTranslator && !useDisplay) {
                    realtimeAudioTranslator.connect(stream, targetLanguages).catch((error) => {
                        console.warn('Realtime (audio): no se pudo conectar, se usara el motor actual como fallback.', error);
                    });
                }
                // Brazo B: misma logica, mismo stream reusado, solo modo microfono.
                if (realtimeDedicatedTranslator && !useDisplay) {
                    realtimeDedicatedTranslator.connect(stream).catch((error) => {
                        console.warn('PoC Realtime (brazo B): no se pudo conectar, se usara el motor actual como fallback.', error);
                    });
                }
                setStatus(useDisplay ? 'AUDIO DE PESTAÑA ACTIVO' : 'MICROFONO ACTIVO');
                startMicMeter(stream);   // medidor en vivo de la fuente elegida

                if (useDisplay) {
                    // Solo OpenAI: no arrancamos Web Speech (escucharia el microfono fisico).
                    startBackendAudioPipeline(stream);
                } else if (recognition && recognition.isDeepgram) {
                    mediaStream = stream;
                    recognition.lang = state.lang;
                    await recognition.start(stream);
                } else if (backendPipelineEnabled) {
                    startBackendAudioPipeline(stream);
                    // Web Speech SOLO si usamos el microfono PREDETERMINADO. Con un mic
                    // especifico, Web Speech usaria el predeterminado de Windows (otro
                    // dispositivo) y mostraria texto provisional erroneo.
                    if (recognition && !selectedMicDeviceId) {
                        recognition.lang = state.lang;
                        try { recognition.start(); } catch (e) {}
                    }
                } else {
                    // Web Speech abre su propio microfono internamente; mantenemos este
                    // stream solo para alimentar el medidor de nivel en vivo.
                    mediaStream = stream;
                    recognition.lang = state.lang;
                    recognition.start();
                }

                setLiveUi(true);
            } catch (error) {
                console.error('Master live start error:', error);
                showRecognitionError(String(error.message || 'No se pudo iniciar la transmision de voz.'));
            }
        });

        startDemoCountdown();

        document.getElementById('master-lang-select')?.addEventListener('change', function () {
            const option = this.options[this.selectedIndex];
            applyLanguageSelection({
                lang: this.value,
                base: option.getAttribute('data-lang-base'),
                name: option.getAttribute('data-lang-name'),
                speech: option.getAttribute('data-speech-lang'),
            });
        });

        document.querySelectorAll('.voice-gender-btn').forEach((btn) => {
            btn.addEventListener('click', function () {
                const nextGender = this.getAttribute('data-gender') === 'male' ? 'male' : 'female';
                if (nextGender === state.gender) {
                    return;
                }

                const previousGender = state.gender;
                const previousVoice = currentVoice;
                state.gender = nextGender;
                currentVoice = pickVoiceForGender(state.gender, currentVoice);
                renderVoiceSelect(state.gender, currentVoice);
                highlightGenderButton(state.gender);
                updateIaPanelSummary();

                if (elements.voiceStatus) {
                    elements.voiceStatus.textContent = 'Guardando...';
                    elements.voiceStatus.classList.remove('text-red-400');
                }

                persistTranslationSettings({
                    voice_provider: 'elevenlabs',
                    voice_gender_profile: state.gender,
                    voice: currentVoice,
                }).then((ok) => {
                    if (ok) {
                        if (elements.voiceStatus) {
                            elements.voiceStatus.textContent = `Perfil activo: ${state.gender}`;
                        }
                        return;
                    }

                    state.gender = previousGender;
                    currentVoice = previousVoice;
                    renderVoiceSelect(state.gender, currentVoice);
                    highlightGenderButton(state.gender);
                    updateIaPanelSummary();
                    if (elements.voiceStatus) {
                        elements.voiceStatus.textContent = 'No se pudo guardar el perfil.';
                        elements.voiceStatus.classList.add('text-red-400');
                    }
                });
            });
        });

        updateVoiceProviderUI();
        renderListenerPresence();

        function updateIaPanelSummary() {
            const provider = getVoiceProvider() === 'elevenlabs' ? 'ElevenLabs' : 'Navegador';
            const genderLabel = state.gender === 'male' ? 'Male' : 'Female';
            if (!elements.iaPanelSummary) return;
            elements.iaPanelSummary.textContent = `${provider} - ${genderLabel} - ${currentVoice}`;
            return;
            elements.iaPanelSummary.textContent = `${provider} Â· ${genderLabel} Â· ${currentVoice}`;
            return;
            const mode = (config.translationSettings?.translation_mode || 'voice_to_voice') === 'voice_to_voice' ? 'Voz' : 'Texto';
            elements.iaPanelSummary.textContent = `${mode} · ${currentVoice}`;
        }

        function setIaPanelOpen(open) {
            if (!elements.iaPanelBody || !elements.iaPanelToggle) return;
            elements.iaPanelBody.classList.toggle('hidden', !open);
            elements.iaPanelToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (elements.iaPanelCaret) {
                elements.iaPanelCaret.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
            }
        }

        function highlightGenderButton(gender) {
            document.querySelectorAll('.voice-gender-btn').forEach((btn) => {
                const active = btn.getAttribute('data-gender') === gender;
                btn.classList.toggle('bg-indigo-600', active);
                btn.classList.toggle('text-white', active);
                btn.classList.toggle('text-zinc-400', !active);
            });
        }

        async function persistTranslationSettings(patch) {
            if (!translationSettingsUrl) return false;
            try {
                const response = await fetch(translationSettingsUrl, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(patch),
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const payload = await readJsonResponse(response, 'No se pudo actualizar la configuracion.');
                if (payload?.translation_settings) {
                    config.translationSettings = payload.translation_settings;
                    currentVoice = payload.translation_settings.voice || currentVoice;
                    state.gender = payload.translation_settings.voice_gender_profile || state.gender;
                }
                return true;
            } catch (error) {
                console.warn('No se pudo persistir la configuracion de traduccion:', error);
                return false;
            }
        }

        // --- Clonacion de voz del orador (ElevenLabs Instant Voice Cloning) -------------
        // Flujo: consentimiento explicito -> grabar muestra -> POST unico al backend.
        // No toca el pipeline de audio en vivo (mediaStream separado) ni corre en el
        // camino critico de latencia: es una operacion unica al configurar la sesion.
        (function setupVoiceCloning() {
            const voiceCloneUrl = config.voiceCloneUrl;
            if (!voiceCloneUrl || !elements.voiceCloneRecordBtn) {
                return;
            }

            const MIN_SAMPLE_SECONDS = 8;
            let cloneStream = null;
            let cloneRecorder = null;
            let cloneChunks = [];
            let cloneTimerId = null;
            let cloneStartedAt = 0;
            let isRecording = false;
            let isUploading = false;

            function setCloneStatus(text) {
                if (elements.voiceCloneStatus) {
                    elements.voiceCloneStatus.textContent = text;
                }
            }

            function setClonedState(cloned) {
                if (elements.voiceCloneIdle) elements.voiceCloneIdle.classList.toggle('hidden', cloned);
                if (elements.voiceCloneActive) elements.voiceCloneActive.classList.toggle('hidden', !cloned);
            }

            function stopCloneStream() {
                if (cloneStream) {
                    cloneStream.getTracks().forEach((track) => { try { track.stop(); } catch (e) {} });
                    cloneStream = null;
                }
                if (cloneTimerId) {
                    clearInterval(cloneTimerId);
                    cloneTimerId = null;
                }
            }

            function updateRecordButton() {
                if (!elements.voiceCloneRecordBtn) return;
                elements.voiceCloneRecordBtn.disabled = isUploading || !(elements.voiceCloneConsent?.checked);
                elements.voiceCloneRecordBtn.textContent = isRecording ? 'Detener y enviar' : 'Grabar muestra de voz';
            }

            if (elements.voiceCloneConsent) {
                elements.voiceCloneConsent.addEventListener('change', updateRecordButton);
            }
            updateRecordButton();

            async function startCloneRecording() {
                try {
                    cloneStream = await navigator.mediaDevices.getUserMedia({
                        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                    });
                } catch (error) {
                    setCloneStatus('No se pudo acceder al microfono para grabar la muestra.');
                    return;
                }

                const mimeType = getPreferredRecorderMimeType();
                try {
                    cloneRecorder = mimeType
                        ? new MediaRecorder(cloneStream, { mimeType })
                        : new MediaRecorder(cloneStream);
                } catch (error) {
                    setCloneStatus('Este navegador no puede grabar audio para clonar la voz.');
                    stopCloneStream();
                    return;
                }

                cloneChunks = [];
                cloneRecorder.ondataavailable = (event) => {
                    if (event.data && event.data.size) cloneChunks.push(event.data);
                };
                cloneRecorder.onstop = () => uploadCloneSample(mimeType);

                cloneRecorder.start();
                isRecording = true;
                cloneStartedAt = Date.now();
                updateRecordButton();

                cloneTimerId = setInterval(() => {
                    const elapsed = Math.floor((Date.now() - cloneStartedAt) / 1000);
                    setCloneStatus(`Grabando... ${elapsed}s (minimo recomendado ${MIN_SAMPLE_SECONDS}s, ideal 30-60s). Pulsa "Detener y enviar" cuando termines.`);
                }, 500);
            }

            function stopCloneRecording() {
                if (cloneRecorder && cloneRecorder.state !== 'inactive') {
                    try { cloneRecorder.stop(); } catch (e) {}
                }
                isRecording = false;
                stopCloneStream();
                updateRecordButton();
            }

            async function uploadCloneSample(mimeType) {
                const elapsedSeconds = (Date.now() - cloneStartedAt) / 1000;
                const blob = new Blob(cloneChunks, { type: mimeType || 'audio/webm' });
                cloneChunks = [];

                if (elapsedSeconds < MIN_SAMPLE_SECONDS || blob.size < 4096) {
                    setCloneStatus(`Muestra muy corta (${Math.round(elapsedSeconds)}s). Graba al menos ${MIN_SAMPLE_SECONDS}s de voz continua.`);
                    return;
                }

                isUploading = true;
                updateRecordButton();
                setCloneStatus('Enviando y clonando voz con ElevenLabs...');

                const extension = mimeType && mimeType.includes('ogg') ? 'ogg' : 'webm';
                const formData = new FormData();
                formData.append('consent', elements.voiceCloneConsent?.checked ? '1' : '0');
                formData.append('sample', blob, `voice-sample-${Date.now()}.${extension}`);

                try {
                    const response = await fetch(voiceCloneUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        credentials: 'same-origin',
                        body: formData,
                    });

                    const payload = await readJsonResponse(response, 'No se pudo clonar la voz.');

                    if (!response.ok || payload.success === false) {
                        throw new Error(payload.message || 'No se pudo clonar la voz.');
                    }

                    setCloneStatus('Voz del orador activa. Se usara en toda la sesion.');
                    setClonedState(true);
                } catch (error) {
                    setCloneStatus(String(error.message || 'No se pudo clonar la voz.'));
                } finally {
                    isUploading = false;
                    updateRecordButton();
                }
            }

            if (elements.voiceCloneRecordBtn) {
                elements.voiceCloneRecordBtn.addEventListener('click', () => {
                    if (isUploading) return;
                    if (isRecording) {
                        stopCloneRecording();
                    } else {
                        startCloneRecording();
                    }
                });
            }

            if (elements.voiceCloneRemoveBtn) {
                elements.voiceCloneRemoveBtn.addEventListener('click', async () => {
                    elements.voiceCloneRemoveBtn.disabled = true;
                    try {
                        const response = await fetch(voiceCloneUrl, {
                            method: 'DELETE',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            credentials: 'same-origin',
                        });
                        if (!response.ok) throw new Error('No se pudo quitar la voz clonada.');
                        setClonedState(false);
                        setCloneStatus('Marca el consentimiento para habilitar la grabación. Ideal: 30-60s de voz clara y continua.');
                        if (elements.voiceCloneConsent) elements.voiceCloneConsent.checked = false;
                        updateRecordButton();
                    } catch (error) {
                        console.warn('No se pudo quitar la voz clonada:', error);
                    } finally {
                        elements.voiceCloneRemoveBtn.disabled = false;
                    }
                });
            }
        })();

        if (elements.iaPanelToggle) {
            elements.iaPanelToggle.addEventListener('click', () => {
                const isOpen = !elements.iaPanelBody?.classList.contains('hidden');
                setIaPanelOpen(!isOpen);
            });
        }

        if (elements.voiceSelect) {
            currentVoice = pickVoiceForGender(state.gender, currentVoice);
            renderVoiceSelect(state.gender, currentVoice);
            elements.voiceSelect.addEventListener('change', async (event) => {
                const next = String(event.target.value || '').trim();
                if (!next || next === currentVoice) return;
                const previous = currentVoice;
                currentVoice = next;
                updateIaPanelSummary();
                if (elements.voiceStatus) {
                    elements.voiceStatus.textContent = 'Guardando...';
                    elements.voiceStatus.classList.remove('text-red-400');
                }
                const ok = await persistTranslationSettings({
                    voice_provider: 'elevenlabs',
                    voice_gender_profile: state.gender,
                    voice: next,
                });
                if (!ok) {
                    currentVoice = previous;
                    renderVoiceSelect(state.gender, previous);
                    updateIaPanelSummary();
                    if (elements.voiceStatus) {
                        elements.voiceStatus.textContent = 'No se pudo guardar la voz.';
                        elements.voiceStatus.classList.add('text-red-400');
                    }
                    return;
                }
                if (elements.voiceStatus) {
                    elements.voiceStatus.textContent = `Voz activa: ${next}`;
                    elements.voiceStatus.classList.remove('text-red-400');
                }
            });
        }

        highlightGenderButton(state.gender);
        renderVoiceSelect(state.gender, currentVoice);
        updateIaPanelSummary();

        if (socket) {
            socket.on('active-language-changed', (payload) => {
                if (!payload || payload.origin === 'master') return;
                upsertListenerPresence(payload);
                state.listenerLang = payload.listenerLang || payload.lang || state.listenerLang;
            });

            socket.on('listener-audio-state', (payload) => {
                upsertListenerPresence(payload);
                state.externalAudioActive = hasAnyListenerAudioActive();
            });
        }

        window.setInterval(renderListenerPresence, 3000);

        // Feedback no bloqueante de reconexion ante red dinamica / tunel caido.
        let networkRetryActive = false;
        window.addEventListener('spikia:network-retry', () => {
            networkRetryActive = true;
            if (elements.statusDot) {
                elements.statusDot.className = 'relative inline-flex rounded-full h-3 w-3 bg-amber-400 animate-pulse shadow-[0_0_10px_#fbbf24]';
            }
            setStatus('RED INESTABLE · RECONECTANDO...');
        });
        window.addEventListener('spikia:network-restored', () => {
            if (!networkRetryActive) return;
            networkRetryActive = false;
            if (state.isLive) {
                if (elements.statusDot) {
                    elements.statusDot.className = 'relative inline-flex rounded-full h-3 w-3 bg-cyan-400 animate-pulse shadow-[0_0_10px_#22d3ee]';
                }
                setStatus('LIVE RUNNING');
            } else {
                setStatus('CONEXION RESTABLECIDA');
            }
        });

        window.addEventListener('beforeunload', () => {
            stopMicTest();
            stopMediaCapture();
            if (recognition) {
                try { recognition.stop(); } catch (error) {}
            }
        });
    });
}
