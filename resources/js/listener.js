import { io } from 'socket.io-client';
import { getEcho } from './echo';

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

        // Reintentar SOLO peticiones idempotentes (GET/HEAD), como el feed de mensajes,
        // para sobrevivir a una caida del tunel sin bloquear los POST en tiempo real.
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

const config = window.__SPIKIA_LISTENER__;

if (config) {
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('subtitles-container');
        const timelineList = document.getElementById('timeline-list');
        const timelineMeta = document.getElementById('timeline-meta');
        const pendingCount = document.getElementById('pending-count');
        const selectedLanguageLabel = document.getElementById('selected-language-label');
        const langSelect = document.getElementById('lang-select');
        const statusDot = document.getElementById('status-dot');
        const statusText = document.getElementById('status-text');
        const audioBtn = document.getElementById('toggle-audio-btn');
        const audioBtnBg = document.getElementById('audio-btn-bg');
        const iconAudioOn = document.getElementById('icon-audio-on');
        const iconAudioOff = document.getElementById('icon-audio-off');
        const audioBtnLabel = document.getElementById('audio-btn-label');
        const keepScreenBtn = document.getElementById('keep-screen-on-btn');
        const subtitleSizeButtons = document.querySelectorAll('[data-subtitle-size]');
        const listenerClientIdStorageKey = 'spikia_listener_client_id';
        const seenMessages = new Map();
        const seenMessageKeys = new Set();
        const pendingDisplayTimeouts = new Set();
        const languageLabels = config.languageLabels || {};
        const pageLoadedAt = Date.now();
        let minAcceptedPublishedAt = pageLoadedAt;
        let initialSyncDone = false;
        let lastRenderedSignature = '';
        let lastRenderedAt = 0;
        let languageSelectionVersion = 0;
        const languageMap = {
            en: { lang: 'en', masterLang: 'en-US', base: 'en', speech: 'en-US', name: 'English' },
            'es-ES': { lang: 'es-ES', base: 'es', speech: 'es-ES', name: 'EspaÃ±ol EspaÃ±a' },
            'es-419': { lang: 'es-419', masterLang: 'es-419', base: 'es', speech: 'es-MX', name: 'EspaÃ±ol LatAm' },
            pt: { lang: 'pt', masterLang: 'pt-BR', base: 'pt', speech: 'pt-BR', name: 'PortuguÃªs' },
            it: { lang: 'it', masterLang: 'it-IT', base: 'it', speech: 'it-IT', name: 'Italiano' },
            fr: { lang: 'fr', masterLang: 'fr-FR', base: 'fr', speech: 'fr-FR', name: 'FrancÃ©s' },
        };

        // Antes exigia tambien statusDot/statusText - esos dos ya no existen en el HTML de
        // varias vistas (ej. transmision.blade.php) despues del rediseño, asi que ese
        // guard cortaba TODA la inicializacion en silencio (sin ningun error en consola):
        // ni el tamaño de subtitulos, ni "pantalla prendida/apagada", ni el audio
        // funcionaban, porque el codigo que los conecta a los botones ni siquiera llegaba
        // a correr. container y audioBtn si son imprescindibles (la pagina no tiene sentido
        // sin ellos); statusDot/statusText son opcionales, ver setOnline()/setOffline().
        if (!container || !audioBtn) {
            return;
        }

        let myLang = localStorage.getItem('spikia_mobile_lang') || config.defaultLang || 'es-ES';
        let audioEnabled = false;
        let audioUnlocked = false;
        let screenWakeLock = null;
        let keepScreenOn = false;
        let pendingSpeechMessage = null;
        // Cada frase nueva borra y recrea el <p class="subtitle-text"> desde cero
        // (actualizarSubtitulos/renderInterimPreview hacen container.innerHTML = '' por
        // cada mensaje) - sin esto, el tamaño elegido solo se aplicaba al nodo que existia
        // EN ESE MOMENTO del click, y se perdia apenas llegaba la siguiente frase. Se
        // guarda aca para que cualquier nodo nuevo lo pueda aplicar apenas se crea.
        let currentSubtitleSize = localStorage.getItem('spikia_subtitle_size') || 'medium';
        const listenerClientId = (() => {
            const existing = localStorage.getItem(listenerClientIdStorageKey);
            if (existing) return existing;
            const created = window.crypto?.randomUUID ? window.crypto.randomUUID() : `listener-${Date.now()}`;
            localStorage.setItem(listenerClientIdStorageKey, created);
            return created;
        })();
        let polling = false;
        let currentVoiceAudio = null;
        let latestInterimSignature = '';
        const socket = config.socketUrl ? io(config.socketUrl, {
            transports: ['websocket', 'polling'],
            reconnection: true,
            reconnectionAttempts: Infinity,
            reconnectionDelay: 2000,
            reconnectionDelayMax: 2000,
            randomizationFactor: 0,
            timeout: 8000,
        }) : null;

        function clearVisibleContent() {
            pendingDisplayTimeouts.forEach((timeoutId) => window.clearTimeout(timeoutId));
            pendingDisplayTimeouts.clear();
            latestInterimSignature = '';
            container.innerHTML = '<p id="placeholder" class="text-zinc-600 font-light italic text-lg animate-pulse tracking-wide">Esperando a que el presentador empiece a hablar...</p>';
            if (timelineList) {
                timelineList.innerHTML = `
                    <div class="rounded-2xl border border-dashed border-white/10 bg-white/5 px-4 py-3 text-sm text-zinc-500">
                        Los mensajes traducidos aparecera aqui casi en tiempo real.
                    </div>
                `;
            }
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

        function normalizeMessage(message = {}) {
            const texto = collapseAdjacentRepeatedWords(message.texto || message.traduccion || '');
            const idioma = message.idioma || 'es';
            const variante = message.variante || '';
            const publishedAt = Number(message.published_at || 0);
            const availableAt = Number(message.available_at || 0);
            const revision = Number(message.revision || 1);
            const id = message.id || `${idioma}:${variante}:${publishedAt || Date.now()}:${texto}`;
            const dedupeKey = `${idioma}|${variante}|${availableAt || publishedAt || 0}|${texto}`;

            return {
                ...message,
                id,
                dedupeKey,
                texto,
                idioma,
                variante,
                revision,
                genero: message.genero || message.gender || '',
            };
        }

        function collapseAdjacentRepeatedWords(text) {
            return String(text || '')
                .trim()
                .replace(/\s+/g, ' ')
                .replace(/\b(\w+)(?:\s+\1\b)+/gi, '$1');
        }

        // Aplica el tamaño actual a UN nodo de subtitulo puntual - se llama tanto al
        // cambiar de tamaño (sobre el nodo que ya esta en pantalla) como apenas se crea un
        // nodo nuevo (para que nunca "vuelva" al tamaño por defecto en la siguiente frase).
        function applySubtitleSizeToNode(node) {
            if (!node) return;
            const sizeMap = { small: '1.25rem', medium: '1.875rem', large: '2.75rem' };
            node.style.fontSize = sizeMap[currentSubtitleSize] || sizeMap.medium;
            node.style.lineHeight = currentSubtitleSize === 'large' ? '1.3' : currentSubtitleSize === 'small' ? '1.5' : '1.4';
        }

        function setSubtitleSize(size) {
            const selectedSize = ['small', 'medium', 'large'].includes(size) ? size : 'medium';
            currentSubtitleSize = selectedSize;
            container.classList.remove('subtitle-size-small', 'subtitle-size-medium', 'subtitle-size-large');
            container.classList.add(`subtitle-size-${selectedSize}`);

            applySubtitleSizeToNode(container.querySelector('.subtitle-text'));

            subtitleSizeButtons.forEach((button) => {
                const active = button.dataset.subtitleSize === selectedSize;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            localStorage.setItem('spikia_subtitle_size', selectedSize);
        }

        function updateAudioUI() {
            if (iconAudioOn) iconAudioOn.classList.toggle('hidden', !audioEnabled);
            if (iconAudioOff) iconAudioOff.classList.toggle('hidden', audioEnabled);
            if (audioBtnBg) audioBtnBg.classList.toggle('opacity-100', audioEnabled);
            if (audioBtnLabel) audioBtnLabel.textContent = audioEnabled ? 'Audio activado' : 'Activar audio';
            if (audioBtn) audioBtn.setAttribute('aria-label', audioEnabled ? 'Desactivar audio' : 'Activar audio');
            renderAudioUnlockNotice();
        }

        function updateKeepScreenUI() {
            if (!keepScreenBtn) return;
            const active = !!keepScreenOn;
            keepScreenBtn.classList.toggle('border-emerald-400/60', active);
            keepScreenBtn.classList.toggle('bg-emerald-500/10', active);
            keepScreenBtn.classList.toggle('text-emerald-200', active);
            keepScreenBtn.classList.toggle('shadow-[0_0_25px_rgba(16,185,129,0.18)]', active);
            keepScreenBtn.classList.toggle('border-zinc-700/70', !active);
            keepScreenBtn.classList.toggle('bg-zinc-950/90', !active);
            keepScreenBtn.classList.toggle('text-zinc-200', !active);
            const icon = '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17h4.5M7 9.5A5 5 0 0117 9.5v5.5a2 2 0 01-2 2H9a2 2 0 01-2-2V9.5zM9.5 4.5h5" /></svg>';
            keepScreenBtn.innerHTML = `${icon}<span>${active ? 'Pantalla prendida' : 'Pantalla apagada'}</span>`;
            keepScreenBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
        }

        async function requestKeepScreenOn() {
            if (keepScreenOn) {
                return;
            }

            if (!('wakeLock' in navigator)) {
                keepScreenOn = true;
                updateKeepScreenUI();
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

        function primeAudioPlayback() {
            if (!('speechSynthesis' in window) || !audioEnabled) {
                return;
            }

            try {
                const silent = new SpeechSynthesisUtterance('');
                silent.volume = 0;
                window.speechSynthesis.cancel();
                window.speechSynthesis.speak(silent);
            } catch (error) {
                //
            }
        }

        function ensurePersistentAudioElement() {
            let el = document.getElementById('spikia-persistent-audio');
            if (!el) {
                el = document.createElement('audio');
                el.id = 'spikia-persistent-audio';
                el.preload = 'auto';
                el.playsInline = true;
                el.setAttribute('playsinline', 'playsinline');
                el.setAttribute('webkit-playsinline', 'webkit-playsinline');
                el.crossOrigin = 'anonymous';
                el.style.position = 'fixed';
                el.style.bottom = '-100px';
                el.style.opacity = '0';
                el.style.pointerEvents = 'none';
                document.body.appendChild(el);
            }
            return el;
        }

        async function primeMediaElementPlayback() {
            if (!audioEnabled) {
                return;
            }

            try {
                const el = ensurePersistentAudioElement();
                el.muted = true;
                el.src = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=';
                await el.play();
                el.pause();
                el.currentTime = 0;
                el.muted = false;
                el.src = '';
            } catch (error) {
                //
            }
        }

        function ensureAudioUnlockNotice() {
            let notice = document.getElementById('audio-unlock-notice');
            if (notice) {
                return notice;
            }

            notice = document.createElement('button');
            notice.id = 'audio-unlock-notice';
            notice.type = 'button';
            notice.className = 'pointer-events-none hidden fixed inset-0 z-[70] flex items-center justify-center bg-black/25 px-6';
            notice.innerHTML = '<span class="block rounded-2xl border border-cyan-400/30 bg-zinc-950/95 px-6 py-5 text-center text-sm font-black uppercase tracking-[0.18em] text-cyan-100 shadow-[0_0_30px_rgba(34,211,238,0.18)] backdrop-blur">Activá el audio para escuchar la traducción con voz<span class="mt-2 block text-[9px] font-medium normal-case tracking-[0.08em] text-zinc-400">Presioná el botón "Activar audio" para comenzar</span></span>';
            const handleUnlockTap = (e) => {
                if (e) {
                    try { e.preventDefault(); } catch (err) {}
                    try { e.stopPropagation(); } catch (err) {}
                }
                unlockAudio();
            };
            notice.addEventListener('click', handleUnlockTap);
            notice.addEventListener('touchend', handleUnlockTap, { passive: false });
            notice.addEventListener('pointerup', handleUnlockTap);
            document.body.appendChild(notice);

            return notice;
        }

        // A pedido: el aviso "Activá el audio..." se muestra como mucho 2 segundos y
        // despues se oculta solo, aunque el oyente todavia no haya tocado "Activar audio" -
        // no debe quedar tapando la pantalla indefinidamente. (Antes habia un intento de
        // esto mismo, pero el calculo de tiempo estaba mal - comparaba "ahora" contra "ahora
        // menos si mismo", asi que nunca llegaba a ocultarse.)
        const AUDIO_UNLOCK_NOTICE_DURATION_MS = 2000;
        let audioUnlockNoticeTimer = null;
        let audioUnlockNoticeExpired = false;

        function renderAudioUnlockNotice() {
            const notice = ensureAudioUnlockNotice();

            if (audioEnabled) {
                // El audio ya esta activo: se resetea todo para la proxima vez que haga
                // falta mostrar el aviso (ej. si el oyente lo desactiva mas tarde).
                if (audioUnlockNoticeTimer) {
                    clearTimeout(audioUnlockNoticeTimer);
                    audioUnlockNoticeTimer = null;
                }
                audioUnlockNoticeExpired = false;
                notice.classList.toggle('hidden', audioUnlocked);
                return;
            }

            if (audioUnlockNoticeExpired) {
                notice.classList.add('hidden');
                return;
            }

            notice.classList.remove('hidden');

            if (!audioUnlockNoticeTimer) {
                audioUnlockNoticeTimer = window.setTimeout(() => {
                    audioUnlockNoticeTimer = null;
                    audioUnlockNoticeExpired = true;
                    renderAudioUnlockNotice();
                }, AUDIO_UNLOCK_NOTICE_DURATION_MS);
            }
        }

        function queuePendingSpeech(texto, lang, variante, audioUrl = '') {
            pendingSpeechMessage = {
                texto,
                lang,
                variante,
                audioUrl,
            };
            renderAudioUnlockNotice();
        }

        async function flushPendingSpeech() {
            if (!audioUnlocked || !audioEnabled || !pendingSpeechMessage) {
                return;
            }

            const nextMessage = pendingSpeechMessage;
            pendingSpeechMessage = null;
            renderAudioUnlockNotice();
            await hablarTexto(nextMessage.texto, nextMessage.lang, nextMessage.variante, nextMessage.audioUrl);
        }

        async function unlockAudio() {
            if (!audioEnabled) {
                return;
            }

            primeAudioPlayback();
            await primeMediaElementPlayback();
            audioUnlocked = true;
            renderAudioUnlockNotice();
            await flushPendingSpeech();
        }

        function activarBoton(lang) {
            if (langSelect && langSelect.value !== lang) {
                langSelect.value = lang;
            }
        }

        function emitLanguageSelection(payload) {
            if (!socket) return;

            socket.emit('active-language-changed', {
                origin: 'listener',
                clientId: listenerClientId,
                listenerLabel: `Listener ${listenerClientId.slice(0, 4).toUpperCase()}`,
                audioActive: !!currentVoiceAudio,
                ...payload,
            });
        }

        // Media Session API: le dice al SO (Android/iOS/desktop) que esto es "reproduccion
        // de medios" real, igual que una cancion o un podcast - eso es lo que hace que el
        // audio pueda seguir sonando con la pantalla apagada/bloqueada en vez de que el
        // navegador lo pause al mandarlo a segundo plano. Sin esto, el audio de
        // <audio>/Audio() se trata como "ruido de una pestaña en background" y muchos
        // navegadores moviles lo cortan apenas se apaga la pantalla.
        let mediaSessionInitialized = false;
        function ensureMediaSession() {
            if (mediaSessionInitialized || !('mediaSession' in navigator)) return;
            mediaSessionInitialized = true;

            navigator.mediaSession.metadata = new MediaMetadata({
                title: 'Traducción en vivo',
                artist: 'Spikia',
                album: config.slug || 'Sesión en vivo',
            });

            // Handlers vacios (no-op) a proposito: no hay play/pause/seek real que ofrecerle
            // al SO desde este lado, pero REGISTRARLOS es lo que hace que algunos
            // navegadores (sobre todo Android/Chrome) acepten tratar este audio como sesion
            // de medios controlable y no lo suspendan en segundo plano.
            const noop = () => {};
            ['play', 'pause', 'stop', 'seekbackward', 'seekforward'].forEach((action) => {
                try {
                    navigator.mediaSession.setActionHandler(action, noop);
                } catch (error) {
                    // Accion no soportada en este navegador - se ignora, no es critico.
                }
            });
        }

        function emitAudioState(active) {
            if ('mediaSession' in navigator) {
                ensureMediaSession();
                navigator.mediaSession.playbackState = active ? 'playing' : 'paused';
            }

            if (!socket) return;

            socket.emit('listener-audio-state', {
                origin: 'listener',
                clientId: listenerClientId,
                listenerLabel: `Listener ${listenerClientId.slice(0, 4).toUpperCase()}`,
                active: !!active,
                lang: myLang,
            });
        }

        function getVoiceProvider() {
            // 'elevenlabs' y 'openai' van por el MISMO endpoint del backend (/voz/elevenlabs*):
            // el servidor decide con cual de los dos sintetizar segun translation_settings de
            // la sesion (ver SesionController::elevenlabs/elevenlabsStream) - aca solo hace
            // falta distinguir "hay un proveedor real configurado" vs "usar la voz del
            // navegador" (speechSynthesis, ultimo recurso sin proveedor).
            const provider = String(config.translationSettings?.voice_provider || config.voiceProvider || 'elevenlabs').toLowerCase();
            return provider === 'elevenlabs' || provider === 'openai' ? provider : 'browser';
        }

        function stopCurrentVoiceAudio() {
            if (!currentVoiceAudio) {
                return;
            }

            const previous = currentVoiceAudio;
            currentVoiceAudio = null;

            try {
                previous.pause();
                previous.currentTime = 0;
            } catch (error) {
                //
            }

            if (previous._spikiaObjectUrl) {
                const urlToRevoke = previous._spikiaObjectUrl;
                previous._spikiaObjectUrl = null;
                setTimeout(() => {
                    try { URL.revokeObjectURL(urlToRevoke); } catch (e) {}
                }, 5000);
            }
        }

        async function speakWithElevenLabs(texto, lang, variante) {
            // El nombre de la funcion quedo de cuando ElevenLabs era el unico proveedor -
            // hoy tambien cubre 'openai' (mismo endpoint, el backend decide con cual
            // sintetizar). Solo se sale si el proveedor es 'browser' (sin proveedor real
            // configurado, usar la voz del navegador en su lugar).
            if (getVoiceProvider() === 'browser') {
                return false;
            }

            const streamEndpoint = config.voiceStreamEndpoint;
            if (!streamEndpoint) {
                return false;
            }

            try {
                const params = new URLSearchParams({
                    text: texto,
                    slug: config.slug || '',
                    voice: config.translationSettings?.voice || '',
                    lang: lang || '',
                    variante: variante || '',
                    gender: window.__SPIKIA_LAST_GENDER__ || '',
                });
                const streamUrl = `${streamEndpoint}?${params.toString()}`;

                stopCurrentVoiceAudio();

                const audio = new Audio();
                audio.preload = 'auto';
                audio.src = streamUrl;
                currentVoiceAudio = audio;

                audio.onended = () => {
                    if (currentVoiceAudio === audio) {
                        stopCurrentVoiceAudio();
                        emitAudioState(false);
                    }
                };

                audio.onerror = () => {
                    if (currentVoiceAudio === audio) {
                        stopCurrentVoiceAudio();
                        emitAudioState(false);
                    }
                };

                emitAudioState(true);
                await audio.play();

                return true;
            } catch (error) {
                stopCurrentVoiceAudio();
                emitAudioState(false);
                return false;
            }
        }

        async function playProvidedAudio(audioUrl) {
            if (!audioUrl) {
                return false;
            }

            try {
                stopCurrentVoiceAudio();
                const audio = new Audio(audioUrl);
                currentVoiceAudio = audio;

                audio.onended = () => {
                    if (currentVoiceAudio === audio) {
                        stopCurrentVoiceAudio();
                        emitAudioState(false);
                    }
                };

                audio.onerror = () => {
                    if (currentVoiceAudio === audio) {
                        stopCurrentVoiceAudio();
                        emitAudioState(false);
                    }
                };

                emitAudioState(true);
                await audio.play();
                return true;
            } catch (error) {
                stopCurrentVoiceAudio();
                emitAudioState(false);
                return false;
            }
        }

        function speakWithBrowser(texto, lang, variante) {
            if (!('speechSynthesis' in window)) {
                return false;
            }

            window.speechSynthesis.cancel();
            const ut = new SpeechSynthesisUtterance(texto);
            const voiceMap = {
                en: 'en-US',
                es: variante === 'es-419' ? 'es-MX' : 'es-ES',
                'es-ES': 'es-ES',
                'es-419': 'es-MX',
                pt: 'pt-BR',
                it: 'it-IT',
                fr: 'fr-FR',
            };
            ut.lang = voiceMap[variante || lang] || 'es-ES';
            ut.rate = 1.12;
            const gender = String(window.__SPIKIA_LAST_GENDER__ || '').toLowerCase();
            ut.pitch = gender === 'male' ? 1.0 : 1.14;

            const voices = window.speechSynthesis.getVoices();
            const matchingVoices = voices.filter((v) => v.lang && v.lang.includes(ut.lang));
            const preferredVoice = matchingVoices.find((voice) => {
                const name = String(voice.name || '').toLowerCase();
                if (gender === 'male') {
                    return /male|man|hombre|mascul/i.test(name);
                }
                if (gender === 'female') {
                    return /female|woman|mujer|feminin/i.test(name);
                }
                return false;
            });
            const voice = preferredVoice || matchingVoices[0];
            if (voice) ut.voice = voice;
            ut.onend = () => emitAudioState(false);
            ut.onerror = () => emitAudioState(false);
            emitAudioState(true);
            window.speechSynthesis.speak(ut);
            return true;
        }

        function applyLanguageSelection(lang, shouldEmit = true) {
            const details = languageMap[lang] || {
                lang,
                masterLang: lang,
                base: lang.split('-')[0] || lang,
                speech: lang,
                name: lang.toUpperCase(),
            };
            const prettyName = languageLabels[details.lang] || details.name || details.lang.toUpperCase();

            myLang = details.lang;
            languageSelectionVersion += 1;
            minAcceptedPublishedAt = Date.now();
            localStorage.setItem('spikia_mobile_lang', details.lang);
            localStorage.setItem('spikia_selected_listener_lang', details.lang);
            activarBoton(myLang);
            if (selectedLanguageLabel) {
                selectedLanguageLabel.textContent = `${prettyName} · ${details.lang.toUpperCase()}`;
            }
            clearVisibleContent();

            if (shouldEmit) {
                emitLanguageSelection({ ...details, name: prettyName });
            }

            primeAudioPlayback();
            pollMessages();
        }

        const audioPlaybackQueue = [];
        let audioQueueRunning = false;
        let audioPlaybackGeneration = 0;
        const MAX_AUDIO_QUEUE = 6;

        async function hablarTexto(texto, lang, variante, audioUrl = '') {
            if (!audioEnabled) {
                return;
            }

            if (!audioUnlocked) {
                queuePendingSpeech(texto, lang, variante, audioUrl);
                return;
            }

            const sessionVoiceMode = (config.translationSettings?.translation_mode || 'voice_to_voice') === 'voice_to_voice';
            if (!sessionVoiceMode) {
                return;
            }

            audioPlaybackQueue.push({ texto, lang, variante, audioUrl, queuedAt: Date.now() });
            while (audioPlaybackQueue.length > MAX_AUDIO_QUEUE) {
                audioPlaybackQueue.shift();
            }
            runAudioQueue();
        }

        async function runAudioQueue() {
            if (audioQueueRunning) return;
            audioQueueRunning = true;
            const generation = audioPlaybackGeneration;

            while (audioPlaybackQueue.length > 0) {
                if (!audioEnabled || generation !== audioPlaybackGeneration) {
                    audioPlaybackQueue.length = 0;
                    break;
                }

                const item = audioPlaybackQueue.shift();
                try {
                    await playAudioItem(item);
                } catch (e) {
                    console.warn('Audio playback failed:', e);
                }
            }

            audioQueueRunning = false;
        }

        async function playAudioItem({ texto, lang, variante, audioUrl }) {
            if (!audioEnabled) return;

            const deliveryMode = String(
                config.translationSettings?.audio_delivery_mode
                || config.audioDeliveryMode
                || 'ultra_fast'
            ).toLowerCase();

            if (deliveryMode === 'ultra_fast') {
                if (!audioEnabled) return;
                const browserOk = await speakWithBrowserAwait(texto, lang, variante);
                if (browserOk) return;
            }

            const elevenLabsEnabled = getVoiceProvider() !== 'browser' && config.voiceEndpoint;
            if (elevenLabsEnabled) {
                if (!audioEnabled) return;
                const ok = await speakWithElevenLabsAwait(texto, lang, variante);
                if (ok) return;
            }

            if (audioUrl) {
                if (!audioEnabled) return;
                const ok = await playAndAwait(audioUrl);
                if (ok) return;
            }

            if (!audioEnabled) return;
            await speakWithBrowserAwait(texto, lang, variante);
        }

        function playUrlOnPersistentElement(url) {
            return new Promise((resolve) => {
                try {
                    const el = ensurePersistentAudioElement();
                    el.muted = false;
                    el.onended = null;
                    el.onerror = null;
                    el.pause();
                    el.currentTime = 0;
                    el.src = url;
                    currentVoiceAudio = el;
                    el.onended = () => { emitAudioState(false); resolve(true); };
                    el.onerror = () => { emitAudioState(false); resolve(false); };
                    emitAudioState(true);
                    const playPromise = el.play();
                    if (playPromise && typeof playPromise.catch === 'function') {
                        playPromise.catch(() => { emitAudioState(false); resolve(false); });
                    }
                } catch (e) {
                    emitAudioState(false);
                    resolve(false);
                }
            });
        }

        async function playAndAwait(audioUrl) {
            return playUrlOnPersistentElement(audioUrl);
        }

        async function speakWithElevenLabsAwait(texto, lang, variante) {
            if (getVoiceProvider() === 'browser') return false;
            const streamEndpoint = config.voiceStreamEndpoint;
            if (!streamEndpoint) return false;

            const params = new URLSearchParams({
                text: texto,
                slug: config.slug || '',
                voice: config.translationSettings?.voice || '',
                lang: lang || '',
                variante: variante || '',
                gender: window.__SPIKIA_LAST_GENDER__ || '',
            });
            const streamUrl = `${streamEndpoint}?${params.toString()}`;
            return playUrlOnPersistentElement(streamUrl);
        }

        async function speakWithBrowserAwait(texto, lang, variante) {
            return new Promise((resolve) => {
                if (!('speechSynthesis' in window)) { resolve(false); return; }
                try {
                    window.speechSynthesis.cancel();
                    const ut = new SpeechSynthesisUtterance(texto);
                    const voiceMap = {
                        en: 'en-US',
                        es: variante === 'es-419' ? 'es-MX' : 'es-ES',
                        'es-ES': 'es-ES',
                        'es-419': 'es-MX',
                        pt: 'pt-BR',
                        it: 'it-IT',
                        fr: 'fr-FR',
                    };
                    ut.lang = voiceMap[variante || lang] || 'es-ES';
                    ut.rate = 1.12;
                    const gender = String(window.__SPIKIA_LAST_GENDER__ || '').toLowerCase();
                    ut.pitch = gender === 'male' ? 1.0 : 1.14;
                    ut.onend = () => { emitAudioState(false); resolve(true); };
                    ut.onerror = () => { emitAudioState(false); resolve(false); };
                    emitAudioState(true);
                    window.speechSynthesis.speak(ut);
                } catch (e) {
                    resolve(false);
                }
            });
        }

        function actualizarSubtitulos(texto) {
            const placeholder = document.getElementById('placeholder');
            if (placeholder) placeholder.remove();

            container.innerHTML = '';

            const p = document.createElement('p');
            p.className = 'subtitle-text max-w-[85vw] rounded-xl border border-white/10 bg-black/35 px-4 py-2 text-center font-medium tracking-[0.08em] text-white/95 shadow-[0_0_24px_rgba(0,0,0,0.4)] animate-subtitle-in';
            p.innerText = collapseAdjacentRepeatedWords(texto);
            applySubtitleSizeToNode(p);
            container.appendChild(p);
        }

        function showStatusMessage(texto) {
            const placeholder = document.getElementById('placeholder');
            if (placeholder) placeholder.remove();

            container.innerHTML = '';

            const p = document.createElement('p');
            p.className = 'rounded-2xl border border-red-500/30 bg-red-500/10 px-5 py-4 text-base font-bold text-red-100';
            p.innerText = texto;
            container.appendChild(p);
        }

        function renderInterimPreview(interim) {
            if (!interim || !matchesLanguage(interim)) {
                return;
            }

            const normalizedText = collapseAdjacentRepeatedWords(interim.texto || '');
            const signature = `${interim.idioma || ''}|${interim.variante || ''}|${normalizedText}`;

            if (!normalizedText || signature === latestInterimSignature) {
                return;
            }

            latestInterimSignature = signature;

            const placeholder = document.getElementById('placeholder');
            if (placeholder) placeholder.remove();

            container.innerHTML = '';

            const wrapper = document.createElement('div');
            wrapper.className = 'space-y-3';
            wrapper.innerHTML = `
                <div class="inline-flex items-center rounded-full border border-amber-400/30 bg-amber-400/10 px-3 py-1 text-[9px] font-black uppercase tracking-[0.25em] text-amber-200">
                    Texto provisional
                </div>
                <p class="subtitle-text max-w-[85vw] rounded-xl border border-white/10 bg-black/35 px-4 py-2 text-center font-medium tracking-[0.08em] text-white/95 shadow-[0_0_24px_rgba(0,0,0,0.4)] animate-subtitle-in">${normalizedText}</p>
            `;
            applySubtitleSizeToNode(wrapper.querySelector('.subtitle-text'));
            container.appendChild(wrapper);
        }

        function renderTimelineItem(message) {
            if (!timelineList) return;

            const normalizedText = collapseAdjacentRepeatedWords(message.texto || '');
            const signature = `${message.idioma || ''}|${message.variante || ''}|${normalizedText}`;
            if ((message.revision || 1) <= 1 && signature === lastRenderedSignature && (Date.now() - lastRenderedAt) < 8000) {
                return;
            }

            lastRenderedSignature = signature;
            lastRenderedAt = Date.now();

            const existingItem = timelineList.querySelector(`[data-message-id="${message.id}"]`);
            const item = existingItem || document.createElement('article');
            item.className = 'rounded-2xl border border-white/10 bg-white/5 px-4 py-3 flex items-start justify-between gap-4';
            item.dataset.messageId = message.id;

            const langLabel = (message.variante || message.idioma || 'es').toString().toUpperCase();
            const stamp = message.published_at ? new Date(message.published_at * 1000) : new Date();
            const revisionBadge = Number(message.revision || 1) > 1
                ? '<span class="text-[10px] font-black uppercase tracking-[0.25em] text-amber-300">Corregido</span>'
                : '<span class="text-[10px] font-black uppercase tracking-[0.25em] text-zinc-500">En vivo</span>';

            item.innerHTML = `
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-3 mb-2">
                        <span class="inline-flex items-center rounded-full border border-neonBlue/30 bg-neonBlue/10 px-2 py-1 text-[9px] font-black uppercase tracking-[0.3em] text-neonBlue">${langLabel}</span>
                        ${revisionBadge}
                    </div>
                    <p class="text-sm text-white/90 leading-6 break-words">${normalizedText}</p>
                </div>
                <div class="text-right shrink-0">
                    <p class="text-[10px] font-black uppercase tracking-[0.25em] text-zinc-500">${stamp.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</p>
                </div>
            `;

            if (!existingItem) {
                timelineList.prepend(item);
            }
            while (timelineList.children.length > 6) {
                timelineList.removeChild(timelineList.lastElementChild);
            }
        }

        function queueMessageDisplay(message) {
            if ((message.revision || 1) <= 1 && message.dedupeKey && seenMessageKeys.has(message.dedupeKey)) return;
            
            if (message.dedupeKey) seenMessageKeys.add(message.dedupeKey);
            window.__SPIKIA_LAST_GENDER__ = message.genero || window.__SPIKIA_LAST_GENDER__ || '';
            const selectionVersionAtQueue = languageSelectionVersion;

            const displaySignature = `${message.id || ''}|${message.revision || 1}|${message.idioma || ''}|${message.variante || ''}|${collapseAdjacentRepeatedWords(message.texto || '')}`;
            if ((message.revision || 1) <= 1 && displaySignature === lastRenderedSignature && (Date.now() - lastRenderedAt) < 8000) {
                return;
            }

            const rawAvailableAt = Number(message.available_at || 0);
            const availableAtMs = rawAvailableAt > 0
                ? (rawAvailableAt < 100000000000 ? rawAvailableAt * 1000 : rawAvailableAt)
                : 0;
            const wait = availableAtMs > 0
                ? Math.max(0, Math.min(availableAtMs - Date.now(), 750))
                : 0;

            const timeoutId = window.setTimeout(() => {
                pendingDisplayTimeouts.delete(timeoutId);
                if (selectionVersionAtQueue !== languageSelectionVersion) return;
                if (!matchesLanguage(message)) return;

                lastRenderedSignature = displaySignature;
                latestInterimSignature = '';
                lastRenderedAt = Date.now();
                actualizarSubtitulos(message.texto);
                renderTimelineItem(message);

                if (audioEnabled) {
                    hablarTexto(collapseAdjacentRepeatedWords(message.texto), message.idioma, message.variante, message.audio_url || '');
                }
            }, wait);
            pendingDisplayTimeouts.add(timeoutId);
        }

        function handleIncomingMessage(message, force = false) {
            if (!message) return;

            const normalized = normalizeMessage(message);
            const publishedAtMs = Number(normalized.published_at || 0) * 1000;
            if (!force && publishedAtMs && publishedAtMs < minAcceptedPublishedAt) return;
            if (!normalized.id) return;
            if (!matchesLanguage(normalized)) return;

            const previousRevision = Number(seenMessages.get(normalized.id) || 0);
            const incomingRevision = Number(normalized.revision || 1);
            if (incomingRevision <= previousRevision) return;

            seenMessages.set(normalized.id, incomingRevision);
            queueMessageDisplay(normalized);
        }

        function isRecentMessage(message) {
            const publishedAtMs = Number(message?.published_at || 0) * 1000;
            if (!publishedAtMs) return false;
            return (Date.now() - publishedAtMs) <= 30000;
        }

        function syncLatestVisibleMessage(messages = []) {
            if (initialSyncDone) return;

            const visibleMessages = Array.isArray(messages)
                ? messages
                    .filter((message) => message)
                    .filter((message) => matchesLanguage(normalizeMessage(message)))
                    .filter((message) => isRecentMessage(message))
                : [];

            if (!visibleMessages.length) {
                initialSyncDone = true;
                return;
            }

            const latest = visibleMessages.sort((a, b) => {
                const aTime = Number(a.available_at || a.published_at || 0);
                const bTime = Number(b.available_at || b.published_at || 0);
                return aTime - bTime;
            }).pop();

            if (latest) {
                handleIncomingMessage(latest, true);
            }

            initialSyncDone = true;
        }

        function setLanguage(lang) {
            applyLanguageSelection(lang, true);
        }

        function setOnline() {
            // statusDot/statusText: indicador de conexion opcional - varias vistas que usan
            // este mismo listener.js (ej. transmision.blade.php) ya no lo tienen en el HTML
            // despues del rediseño. No deben ser obligatorios para que el resto de la
            // pagina (tamaño de subtitulos, pantalla activa, audio) funcione.
            if (statusDot) statusDot.className = 'w-2 h-2 rounded-full bg-cyan-400 shadow-[0_0_10px_#22d3ee]';
            if (statusText) statusText.innerText = 'EN LINEA';
        }

        function setOffline() {
            if (statusDot) statusDot.className = 'w-2 h-2 rounded-full bg-red-500 shadow-[0_0_10px_red]';
            if (statusText) statusText.innerText = 'DESCONECTADO';
        }

        function matchesLanguage(data) {
            const lang = data.idioma || 'es';
            const variant = data.variante || '';
            const type = data.tipo || 'texto';

            if (type === 'original') {
                if (myLang === 'es-ES') {
                    return lang === 'es' && (!variant || variant === 'es-ES');
                }

                if (myLang === 'es-419') {
                    return lang === 'es' && (variant === 'es-419' || !variant);
                }

                const selectedBase = String(myLang).split('-')[0];
                return lang === selectedBase;
            }

            if (myLang === 'es-ES') {
                return lang === 'es' && (!variant || variant === 'es-ES');
            }

            if (myLang === 'es-419') {
                return lang === 'es' && (variant === 'es-419' || !variant);
            }

            return myLang === lang;
        }

        async function pollMessages() {
            if (polling) return;
            polling = true;

            try {
                const feedUrl = new URL(config.feedUrl, window.location.origin);
                feedUrl.searchParams.set('_ts', String(Date.now()));
                const response = await fetch(feedUrl.toString(), {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                const data = await readJsonResponse(response, 'No se pudieron leer los mensajes.');

                if (!response.ok) {
                    throw new Error(data.message || 'No se pudieron leer los mensajes.');
                }

                setOnline();
                if (data.demo_expired) {
                    if (timelineMeta) timelineMeta.textContent = data.message || 'Demo vencida.';
                    showStatusMessage(data.message || 'Se acabo el tiempo de esta demo. Pedile al organizador un codigo nuevo.');
                    return;
                }

                if (pendingCount) pendingCount.textContent = `${data.pending_count ?? 0} pendientes`;
                if (timelineMeta) {
                    timelineMeta.textContent = data.next_available_in_seconds !== null
                        ? `El siguiente mensaje se libera en ${data.next_available_in_seconds}s.`
                        : 'Todo al dia. No hay mensajes en espera.';
                }

                const allMessages = Array.isArray(data.messages) ? data.messages : [];
                if (data.interim) {
                    renderInterimPreview(data.interim);
                }
                allMessages.forEach((message) => handleIncomingMessage(message));
                syncLatestVisibleMessage(allMessages);

            } catch (error) {
                console.error('Error consultando la transmision:', error);
                setOffline();
            } finally {
                polling = false;
            }
        }

        function subscribeRealtime() {
            if (!socket) return;

            socket.on('connect', () => {
                emitLanguageSelection({
                    ...((languageMap[myLang] || {}).lang ? languageMap[myLang] : {
                        lang: myLang,
                        masterLang: myLang,
                        base: myLang.split('-')[0] || myLang,
                        speech: myLang,
                        name: myLang.toUpperCase(),
                    }),
                    name: languageLabels[myLang] || myLang.toUpperCase(),
                });
                schedulePolling();
            });
            socket.on('disconnect', () => schedulePolling());
            socket.on('mensaje-congreso', (event) => handleIncomingMessage(event));
            socket.on('active-language-changed', (payload) => {
                if (!payload || payload.origin !== 'listener') return;
            });
        }

        // Push real via Laravel Echo/Pusher: el master emite TranscripcionCreada (texto
        // original y traducciones) en el canal transmision.{slug}. El polling de abajo pasa
        // a ser una red de seguridad de baja frecuencia en vez de la unica via de entrega.
        let echoConnected = false;

        function subscribeEcho() {
            const echo = getEcho();
            if (!echo || !config.slug) {
                return false;
            }

            try {
                echo.channel(`transmision.${config.slug}`)
                    .listen('.TranscripcionCreada', (payload) => handleIncomingMessage(payload));

                const pusherConnection = echo.connector?.pusher?.connection;
                if (pusherConnection) {
                    pusherConnection.bind('state_change', (states) => {
                        echoConnected = states.current === 'connected';
                        if (echoConnected) setOnline();
                        schedulePolling();
                    });
                }
            } catch (error) {
                console.warn('No se pudo suscribir a Echo:', error);
                return false;
            }

            return true;
        }

        let pollIntervalId = null;
        let pollIntervalMs = 0;

        function pollIntervalForState() {
            // La transmision debe verse casi en tiempo real como el master; con push activo
            // seguimos haciendo polling corto para re-sync y para no perder mensajes si la
            // conexion de Pusher/Echo flaquea o se reinicia. 4s es demasiado lag para texto.
            if (echoConnected || socket?.connected) return 250;
            return 250;
        }

        function schedulePolling() {
            const next = pollIntervalForState();
            if (next === pollIntervalMs && pollIntervalId) return;
            if (pollIntervalId) {
                window.clearInterval(pollIntervalId);
            }
            pollIntervalMs = next;
            pollIntervalId = window.setInterval(pollMessages, pollIntervalMs);
        }

        audioBtn.addEventListener('click', () => {
            audioEnabled = !audioEnabled;
            localStorage.setItem('spikia_audio_enabled', audioEnabled);
            updateAudioUI();
            if (audioEnabled) {
                unlockAudio();
            } else {
                audioPlaybackGeneration += 1;
                audioPlaybackQueue.length = 0;
                pendingSpeechMessage = null;
                audioUnlocked = false;
                stopCurrentVoiceAudio();
                window.speechSynthesis.cancel();
                emitAudioState(false);
            }
        });

        if (keepScreenBtn) {
            keepScreenBtn.addEventListener('click', async () => {
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

        updateAudioUI();
        updateKeepScreenUI();
        activarBoton(myLang);
        setLanguage(myLang);
        subscribeRealtime();
        subscribeEcho();
        pollMessages();
        renderAudioUnlockNotice();

        langSelect?.addEventListener('change', () => setLanguage(langSelect.value));

        subtitleSizeButtons.forEach((button) => {
            button.addEventListener('click', () => setSubtitleSize(button.dataset.subtitleSize));
        });
        setSubtitleSize(localStorage.getItem('spikia_subtitle_size') || 'medium');

        window.setInterval(() => {
            if (!socket?.connected) return;
            const details = languageMap[myLang] || {
                lang: myLang,
                masterLang: myLang,
                base: myLang.split('-')[0] || myLang,
                speech: myLang,
                name: myLang.toUpperCase(),
            };
            emitLanguageSelection({
                ...details,
                name: languageLabels[myLang] || details.name || myLang.toUpperCase(),
            });
        }, 8000);
        schedulePolling();
    });
}
