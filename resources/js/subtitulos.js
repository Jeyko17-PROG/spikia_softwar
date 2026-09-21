import { getEcho } from './echo';

const config = window.__SPIKIA_SUBTITULOS__;

const FONT_SIZES = {
    small: { primary: 'clamp(1.1rem, 2.8vw, 2.25rem)', secondary: 'clamp(0.9rem, 2.2vw, 1.75rem)' },
    medium: { primary: 'clamp(1.5rem, 4vw, 3.25rem)', secondary: 'clamp(1.15rem, 3.1vw, 2.5rem)' },
    large: { primary: 'clamp(1.9rem, 5vw, 4.25rem)', secondary: 'clamp(1.5rem, 3.9vw, 3.25rem)' },
    xlarge: { primary: 'clamp(2.4rem, 6.2vw, 5.5rem)', secondary: 'clamp(1.9rem, 4.8vw, 4.25rem)' },
};

if (config) {
    document.addEventListener('DOMContentLoaded', () => {
        const root = document.getElementById('subs-root');
        const textEl = document.getElementById('subs-text');
        const textEl2 = document.getElementById('subs-text-2');
        const divider = document.getElementById('subs-divider');
        const settingsToggle = document.getElementById('subs-settings-toggle');
        const settingsPanel = document.getElementById('subs-settings-panel');
        const langSelect = document.getElementById('subs-lang-select');
        const langSelect2 = document.getElementById('subs-lang-select-2');
        const fontFamilySelect = document.getElementById('subs-font-family');
        const fontSizeSelect = document.getElementById('subs-font-size');
        const textColorInput = document.getElementById('subs-text-color');
        const bgColorInput = document.getElementById('subs-bg-color');
        const bgTransparentInput = document.getElementById('subs-bg-transparent');

        if (!root || !textEl) return;

        const STORAGE_KEYS = {
            lang: 'spikia_subtitles_lang',
            lang2: 'spikia_subtitles_lang_2',
            fontFamily: 'spikia_subtitles_font_family',
            fontSize: 'spikia_subtitles_font_size',
            textColor: 'spikia_subtitles_text_color',
            bgColor: 'spikia_subtitles_bg_color',
            bgTransparent: 'spikia_subtitles_bg_transparent',
        };

        const seenMessages = new Map();

        // Cada idioma mostrado en pantalla (principal y opcional secundario) es un
        // "slot" independiente: cada uno recuerda su propio idioma, su elemento de
        // texto y la ultima firma renderizada, para que ambos puedan mostrar
        // mensajes distintos al mismo tiempo sin pisarse entre si.
        function createSlot(textElement, storageKey, select, fallbackLang, linkedElement) {
            const slot = {
                lang: localStorage.getItem(storageKey) || fallbackLang || '',
                textElement,
                lastRenderedSignature: '',
            };

            if (select) {
                select.value = slot.lang;
                select.addEventListener('change', () => {
                    slot.lang = select.value;
                    localStorage.setItem(storageKey, slot.lang);
                    slot.textElement.textContent = '';
                    slot.lastRenderedSignature = '';
                    slot.textElement.classList.toggle('hidden', !slot.lang);
                    linkedElement?.classList.toggle('hidden', !slot.lang);
                });
            }

            slot.textElement.classList.toggle('hidden', !slot.lang);
            linkedElement?.classList.toggle('hidden', !slot.lang);

            return slot;
        }

        const slot1 = createSlot(textEl, STORAGE_KEYS.lang, langSelect, config.defaultLang || 'es-ES');
        const slot2 = createSlot(textEl2, STORAGE_KEYS.lang2, langSelect2, '', divider);
        const slots = [slot1, slot2];

        // --- Paleta de colores, tipografia y tamaño (por navegador/pantalla) ---
        function applyStyles() {
            const textColor = localStorage.getItem(STORAGE_KEYS.textColor) || '#ffffff';
            const bgColor = localStorage.getItem(STORAGE_KEYS.bgColor) || '#000000';
            const transparent = localStorage.getItem(STORAGE_KEYS.bgTransparent) === 'true';
            const fontFamily = localStorage.getItem(STORAGE_KEYS.fontFamily) || fontFamilySelect?.value || "'Segoe UI', Arial, sans-serif";
            const fontSizeKey = localStorage.getItem(STORAGE_KEYS.fontSize) || 'medium';
            const sizes = FONT_SIZES[fontSizeKey] || FONT_SIZES.medium;

            root.style.setProperty('--subs-color', textColor);
            root.style.setProperty('--subs-bg', transparent ? 'transparent' : bgColor);
            root.style.setProperty('--subs-font', fontFamily);
            root.style.setProperty('--subs-size', sizes.primary);
            root.style.setProperty('--subs-size-2', sizes.secondary);

            if (textColorInput) textColorInput.value = textColor;
            if (bgColorInput) bgColorInput.value = bgColor;
            if (bgTransparentInput) bgTransparentInput.checked = transparent;
            if (bgColorInput) bgColorInput.disabled = transparent;
            if (fontFamilySelect) fontFamilySelect.value = fontFamily;
            if (fontSizeSelect) fontSizeSelect.value = fontSizeKey;
        }

        textColorInput?.addEventListener('input', () => {
            localStorage.setItem(STORAGE_KEYS.textColor, textColorInput.value);
            applyStyles();
        });
        bgColorInput?.addEventListener('input', () => {
            localStorage.setItem(STORAGE_KEYS.bgColor, bgColorInput.value);
            applyStyles();
        });
        bgTransparentInput?.addEventListener('change', () => {
            localStorage.setItem(STORAGE_KEYS.bgTransparent, bgTransparentInput.checked ? 'true' : 'false');
            applyStyles();
        });
        fontFamilySelect?.addEventListener('change', () => {
            localStorage.setItem(STORAGE_KEYS.fontFamily, fontFamilySelect.value);
            applyStyles();
        });
        fontSizeSelect?.addEventListener('change', () => {
            localStorage.setItem(STORAGE_KEYS.fontSize, fontSizeSelect.value);
            applyStyles();
        });
        settingsToggle?.addEventListener('click', () => {
            settingsPanel?.classList.toggle('hidden');
        });

        applyStyles();

        // --- Recepcion de subtitulos: mismo matching de idioma que listener.js ---
        function matchesLanguage(data, lang) {
            if (!lang) return false;

            const dataLang = data.idioma || 'es';
            const variant = data.variante || '';

            if (lang === 'es-ES') {
                return dataLang === 'es' && (!variant || variant === 'es-ES');
            }
            if (lang === 'es-419') {
                return dataLang === 'es' && (variant === 'es-419' || !variant);
            }

            return lang === dataLang;
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

        function displayInSlot(slot, message) {
            // Los subtitulos van sincronizados con lo que se ve en Master, no con
            // el audio doblado: se muestran apenas llegan, sin esperar a
            // "available_at" (ese delay es para el oyente con audio, que si tiene
            // que calzar el texto con la reproduccion de la voz sintetizada).
            if (!matchesLanguage(message, slot.lang)) return;

            const signature = `${message.id}|${message.revision}`;
            if (signature === slot.lastRenderedSignature) return;
            slot.lastRenderedSignature = signature;

            slot.textElement.textContent = message.texto;
        }

        function handleIncomingMessage(message) {
            if (!message) return;

            const normalized = normalizeMessage(message);
            if (!normalized.id) return;

            const matchesAnySlot = slots.some((slot) => matchesLanguage(normalized, slot.lang));
            if (!matchesAnySlot) return;

            const previousRevision = Number(seenMessages.get(normalized.id) || 0);
            if (normalized.revision <= previousRevision) return;

            seenMessages.set(normalized.id, normalized.revision);

            slots.forEach((slot) => {
                if (matchesLanguage(normalized, slot.lang)) {
                    displayInSlot(slot, normalized);
                }
            });
        }

        let polling = false;

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
                    textEl.textContent = data.message || 'Se acabo el tiempo de esta demo. Pedile al organizador un codigo nuevo.';
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
