import { getEcho } from './echo';

const config = window.__SPIKIA_SUBTITULOS__;

if (config) {
    document.addEventListener('DOMContentLoaded', () => {
        const root = document.getElementById('subs-root');
        const textEl = document.getElementById('subs-text');
        const settingsToggle = document.getElementById('subs-settings-toggle');
        const settingsPanel = document.getElementById('subs-settings-panel');
        const langSelect = document.getElementById('subs-lang-select');
        const textColorInput = document.getElementById('subs-text-color');
        const bgColorInput = document.getElementById('subs-bg-color');
        const bgTransparentInput = document.getElementById('subs-bg-transparent');

        if (!root || !textEl) return;

        const STORAGE_KEYS = {
            lang: 'spikia_subtitles_lang',
            textColor: 'spikia_subtitles_text_color',
            bgColor: 'spikia_subtitles_bg_color',
            bgTransparent: 'spikia_subtitles_bg_transparent',
        };

        let myLang = localStorage.getItem(STORAGE_KEYS.lang) || config.defaultLang || 'es-ES';
        const seenMessages = new Map();
        const pendingTimeouts = new Set();
        let lastRenderedSignature = '';
        let polling = false;

        // --- Paleta de colores (por navegador/pantalla, no compartida entre oyentes) ---
        function applyColors() {
            const textColor = localStorage.getItem(STORAGE_KEYS.textColor) || '#ffffff';
            const bgColor = localStorage.getItem(STORAGE_KEYS.bgColor) || '#000000';
            const transparent = localStorage.getItem(STORAGE_KEYS.bgTransparent) === 'true';

            root.style.setProperty('--subs-color', textColor);
            root.style.setProperty('--subs-bg', transparent ? 'transparent' : bgColor);

            if (textColorInput) textColorInput.value = textColor;
            if (bgColorInput) bgColorInput.value = bgColor;
            if (bgTransparentInput) bgTransparentInput.checked = transparent;
            if (bgColorInput) bgColorInput.disabled = transparent;
        }

        textColorInput?.addEventListener('input', () => {
            localStorage.setItem(STORAGE_KEYS.textColor, textColorInput.value);
            applyColors();
        });
        bgColorInput?.addEventListener('input', () => {
            localStorage.setItem(STORAGE_KEYS.bgColor, bgColorInput.value);
            applyColors();
        });
        bgTransparentInput?.addEventListener('change', () => {
            localStorage.setItem(STORAGE_KEYS.bgTransparent, bgTransparentInput.checked ? 'true' : 'false');
            applyColors();
        });
        settingsToggle?.addEventListener('click', () => {
            settingsPanel?.classList.toggle('hidden');
        });

        applyColors();

        // --- Idioma ---
        if (langSelect) {
            langSelect.value = myLang;
            langSelect.addEventListener('change', () => {
                myLang = langSelect.value;
                localStorage.setItem(STORAGE_KEYS.lang, myLang);
                textEl.textContent = '';
                lastRenderedSignature = '';
            });
        }

        // --- Recepcion de subtitulos: mismo matching de idioma que listener.js ---
        function matchesLanguage(data) {
            const lang = data.idioma || 'es';
            const variant = data.variante || '';

            if (myLang === 'es-ES') {
                return lang === 'es' && (!variant || variant === 'es-ES');
            }
            if (myLang === 'es-419') {
                return lang === 'es' && (variant === 'es-419' || !variant);
            }

            return myLang === lang;
        }

        function collapseAdjacentRepeatedWords(text) {
            return String(text || '')
                .trim()
                .replace(/\s+/g, ' ')
                .replace(/\b(\w+)(?:\s+\1\b)+/gi, '$1');
        }

        function normalizeMessage(message = {}) {
            const texto = collapseAdjacentRepeatedWords(message.texto || message.traduccion || '');
            const idioma = message.idioma || 'es';
            const variante = message.variante || '';
            const publishedAt = Number(message.published_at || 0);
            const revision = Number(message.revision || 1);
            const id = message.id || `${idioma}:${variante}:${publishedAt || Date.now()}:${texto}`;

            return { ...message, id, texto, idioma, variante, revision };
        }

        function displayMessage(message) {
            const availableAtRaw = Number(message.available_at || 0);
            const availableAtMs = availableAtRaw > 0
                ? (availableAtRaw < 100000000000 ? availableAtRaw * 1000 : availableAtRaw)
                : 0;
            const wait = availableAtMs > 0 ? Math.max(0, availableAtMs - Date.now()) : 0;

            const timeoutId = window.setTimeout(() => {
                pendingTimeouts.delete(timeoutId);
                if (!matchesLanguage(message)) return;

                const signature = `${message.id}|${message.revision}`;
                if (signature === lastRenderedSignature) return;
                lastRenderedSignature = signature;

                textEl.textContent = message.texto;
            }, wait);
            pendingTimeouts.add(timeoutId);
        }

        function handleIncomingMessage(message) {
            if (!message) return;

            const normalized = normalizeMessage(message);
            if (!normalized.id || !matchesLanguage(normalized)) return;

            const previousRevision = Number(seenMessages.get(normalized.id) || 0);
            if (normalized.revision <= previousRevision) return;

            seenMessages.set(normalized.id, normalized.revision);
            displayMessage(normalized);
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
                const data = await response.json();

                if (data.demo_expired) {
                    textEl.textContent = data.message || 'Sesion vencida.';
                    return;
                }

                (Array.isArray(data.messages) ? data.messages : []).forEach(handleIncomingMessage);
            } catch (error) {
                console.error('Error consultando subtitulos:', error);
            } finally {
                polling = false;
            }
        }

        // Push real via Echo/Pusher; el polling cada 4s es la red de seguridad.
        function subscribeEcho() {
            const echo = getEcho();
            if (!echo || !config.slug) return;

            try {
                echo.channel(`transmision.${config.slug}`)
                    .listen('.TranscripcionCreada', (payload) => handleIncomingMessage(payload));
            } catch (error) {
                console.warn('No se pudo suscribir a Echo:', error);
            }
        }

        subscribeEcho();
        pollMessages();
        window.setInterval(pollMessages, 4000);
    });
}
