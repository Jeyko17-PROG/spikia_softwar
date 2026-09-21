// Logica del "bot de reunion": entra a un Google Meet como invitado, espera a que el
// anfitrion lo admita, y captura el audio de la pestaña en segmentos que se suben a Laravel.
//
// IMPORTANTE - fragilidad conocida: Google Meet no tiene una API oficial para esto. Todo lo
// de aca abajo depende de la estructura HTML/aria-label actual de Meet, que Google cambia sin
// aviso. Si el bot deja de poder unirse, lo primero a revisar son los selectores de
// findVisibleByText()/NAME_INPUT_SELECTORS/JOIN_BUTTON_TEXTS/IN_CALL_SELECTORS de abajo,
// probando manualmente en un Meet real que texto/aria-label tienen esos elementos hoy.

// meet-bot/bot.js - Integración optimizada para Google Meet y Zoom Web Client

const fs = require('fs');
const path = require('path');
const axios = require('axios');
const FormData = require('form-data');
const { launch, getStream } = require('puppeteer-stream');

function resolveChromePath() {
    const candidates = [
        process.env.PUPPETEER_EXECUTABLE_PATH,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
    ].filter(Boolean);

    const found = candidates.find((candidate) => {
        try {
            return fs.existsSync(candidate);
        } catch (error) {
            return false;
        }
    });

    if (!found) {
        throw new Error(
            'No se encontró Chrome instalado. Instala Google Chrome o define PUPPETEER_EXECUTABLE_PATH.'
        );
    }
    return found;
}

const CHROME_PATH = resolveChromePath();

// Ajustado a 1500ms para acelerar la transcripción y traducción en tiempo real
const SEGMENT_MS = 1500; 
const JOIN_TIMEOUT_MS = 5 * 60 * 1000;
const BOT_DISPLAY_NAME = 'Spikia (traduciendo en vivo)';
const FAKE_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

const JOIN_BUTTON_TEXTS = [
    'pedir unirse', 'solicitar unirse', 'unirse ahora', 'unirse a la reunión', 'join',
    'ask to join', 'join now', 'join meeting'
];
const IN_CALL_TEXTS = [
    'salir de la llamada', 'abandonar llamada', 'finalizar reunión',
    'leave call', 'leave meeting', 'end meeting'
];
const MUTE_MIC_TEXTS = ['desactivar micr', 'turn off microphone', 'mute', 'silenciar'];
const MUTE_CAM_TEXTS = ['desactivar cámara', 'turn off camera', 'stop video'];
const NOT_JOINABLE_TEXTS = ['no puedes unirte a esta videollamada', "you can't join this video call"];

const sessions = new Map();

function log(slug, ...args) {
    console.log(`[meet-bot:${slug}]`, ...args);
}

async function debugSnapshot(page, slug, label) {
    try {
        const dir = path.join(__dirname, 'debug');
        if (!fs.existsSync(dir)) fs.mkdirSync(dir);
        const stamp = Date.now();
        const base = path.join(dir, `${slug}-${label}-${stamp}`);

        await page.screenshot({ path: `${base}.png` });

        const buttons = await page.evaluate(() => {
            const nodes = Array.from(document.querySelectorAll('button, [role="button"], input'));
            return nodes
                .filter((el) => el.offsetParent !== null)
                .map((el) => ({
                    tag: el.tagName,
                    aria: el.getAttribute('aria-label'),
                    text: (el.textContent || '').trim().slice(0, 60),
                    type: el.getAttribute('type'),
                }))
                .slice(0, 60);
        });
        fs.writeFileSync(`${base}.json`, JSON.stringify(buttons, null, 2));
        log(slug, `Debug guardado: ${base}.png / .json`);
    } catch (error) {
        log(slug, 'No se pudo guardar el debug:', error.message);
    }
}

async function findVisibleByText(page, candidates) {
    const handle = await page.evaluateHandle((candidates) => {
        const nodes = Array.from(document.querySelectorAll('button, [role="button"], a, input[type="button"]'));
        const norm = (s) => (s || '').toLowerCase();
        return nodes.find((el) => {
            const label = norm(el.getAttribute('aria-label')) + ' ' + norm(el.textContent) + ' ' + norm(el.value);
            const visible = el.offsetParent !== null;
            return visible && candidates.some((c) => label.includes(c));
        }) || null;
    }, candidates);

    const element = handle.asElement();
    if (!element) {
        await handle.dispose();
        return null;
    }
    return element;
}

async function waitForVisibleByText(page, candidates, timeoutMs = 30000, intervalMs = 1000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        const found = await findVisibleByText(page, candidates);
        if (found) return found;
        await new Promise((resolve) => setTimeout(resolve, intervalMs));
    }
    return null;
}

async function waitForNameInput(page, timeoutMs = 30000, intervalMs = 1000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        const handle = await page.evaluateHandle(() => {
            const inputs = Array.from(document.querySelectorAll('input[type="text"], input#inputname, input[name="name"]'));
            return inputs.find((el) => el.offsetParent !== null) || null;
        });
        const element = handle.asElement();
        if (element) return element;
        await handle.dispose();
        await new Promise((resolve) => setTimeout(resolve, intervalMs));
    }
    return null;
}

async function fillDisplayName(page) {
    const element = await waitForNameInput(page, 15000);
    if (!element) return;
    await element.click({ clickCount: 3 });
    await element.type(BOT_DISPLAY_NAME, { delay: 20 });
}

async function waitForAdmission(page, slug) {
    const deadline = Date.now() + JOIN_TIMEOUT_MS;
    while (Date.now() < deadline) {
        const inCall = await findVisibleByText(page, IN_CALL_TEXTS);
        if (inCall) return true;
        await new Promise((resolve) => setTimeout(resolve, 2000));
    }
    log(slug, 'Timeout esperando admisión del anfitrión.');
    return false;
}

async function bestEffortMute(page) {
    for (const texts of [MUTE_MIC_TEXTS, MUTE_CAM_TEXTS]) {
        try {
            const btn = await findVisibleByText(page, texts);
            if (btn) await btn.click();
        } catch (error) {
            // Silencioso
        }
    }
}

async function uploadChunk(session, buffer) {
    if (!buffer || buffer.length === 0) return;
    const form = new FormData();
    form.append('audio', buffer, { filename: 'segment.webm', contentType: 'audio/webm' });

    try {
        await axios.post(session.ingestUrl, form, {
            headers: {
                ...form.getHeaders(),
                'X-Spikia-Bot-Token': session.ingestToken,
            },
            maxBodyLength: 25 * 1024 * 1024,
            timeout: 10000,
        });
    } catch (error) {
        log(session.slug, 'Fallo al subir segmento de audio a Laravel:', error.message);
    }
}

async function captureLoop(session) {
    while (session.status === 'active' && !session.stopRequested) {
        let stream;
        try {
            stream = await getStream(session.page, { audio: true, video: false, mimeType: 'audio/webm' });
        } catch (error) {
            log(session.slug, 'No se pudo iniciar la captura de audio:', error.message);
            break;
        }

        const chunks = [];
        stream.on('data', (chunk) => chunks.push(chunk));

        await new Promise((resolve) => setTimeout(resolve, SEGMENT_MS));

        try {
            await stream.destroy();
        } catch (error) {
            log(session.slug, 'Aviso cerrando el stream de audio del segmento:', error.message);
        }

        await uploadChunk(session, Buffer.concat(chunks));
    }
}

// Adapta enlaces de Zoom para forzar el cliente web sin requerir la App
function prepareMeetingUrl(targetUrl) {
    if (targetUrl.includes('zoom.us/j/')) {
        return targetUrl.replace('/j/', '/wc/join/');
    }
    return targetUrl;
}

async function join({ slug, meetUrl, ingestUrl, ingestToken }) {
    const existing = sessions.get(slug);
    if (existing && ['joining', 'active'].includes(existing.status)) {
        return { status: existing.status };
    }

    const session = { slug, ingestUrl, ingestToken, status: 'joining', stopRequested: false };
    sessions.set(slug, session);

    (async () => {
        try {
            const finalUrl = prepareMeetingUrl(meetUrl);
            // OJO: --incognito y --guest se probaron aca y rompian el lanzamiento del
            // navegador (Chrome terminaba en un contexto distinto al que Puppeteer sigue via
            // CDP, y fallaba luego con "Target.createTarget: Failed to open a new tab"). Son
            // redundantes ademas: Puppeteer ya lanza con su propio --user-data-dir temporal y
            // vacio en cada join(), sin cookies ni sesion de Google, que es el mismo efecto
            // que se buscaba con esos flags.
            const browser = await launch({
                headless: 'new',
                executablePath: CHROME_PATH,
                args: [
                    '--use-fake-ui-for-media-stream',
                    '--autoplay-policy=no-user-gesture-required',
                    '--no-sandbox',
                    '--disable-blink-features=AutomationControlled',
                ],
            });
            session.browser = browser;

            const page = await browser.newPage();
            session.page = page;

            await page.evaluateOnNewDocument(() => {
                Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
            });
            await page.setUserAgent(FAKE_USER_AGENT);
            await page.setViewport({ width: 1280, height: 720 });

            log(slug, 'Abriendo', finalUrl);
            await page.goto(finalUrl, { waitUntil: 'networkidle2', timeout: 60000 });
            await debugSnapshot(page, slug, 'recien-cargada');

            await fillDisplayName(page);
            await bestEffortMute(page);

            const joinBtn = await waitForVisibleByText(page, JOIN_BUTTON_TEXTS, 30000);
            if (!joinBtn) {
                await debugSnapshot(page, slug, 'sin-boton-join');

                const notJoinable = await page.evaluate((texts) => {
                    const body = document.body.innerText.toLowerCase();
                    return texts.some((t) => body.includes(t));
                }, NOT_JOINABLE_TEXTS).catch(() => false);

                if (notJoinable) {
                    throw new Error('La reunión no está activa o el enlace es inválido.');
                }
                throw new Error('No se encontró el botón para unirse a la reunión.');
            }
            await joinBtn.click();

            const admitted = await waitForAdmission(page, slug);
            if (!admitted || session.stopRequested) {
                throw new Error('El anfitrión no admitió al bot a tiempo.');
            }

            session.status = 'active';
            log(slug, 'Admitido con éxito. Iniciando captura de audio...');
            await captureLoop(session);
        } catch (error) {
            log(slug, 'Error uniéndose a la reunión:', error.message);
            session.status = 'error';
            session.lastError = error.message;
            try {
                if (session.browser) await session.browser.close();
            } catch (closeError) {
                log(slug, 'Aviso cerrando el navegador tras error:', closeError.message);
            }
        }
    })();

    return { status: 'joining' };
}

async function leave({ slug }) {
    const session = sessions.get(slug);
    if (!session) {
        return { status: 'idle' };
    }

    session.stopRequested = true;
    session.status = 'stopped';

    try {
        if (session.browser) await session.browser.close();
    } catch (error) {
        log(slug, 'Aviso cerrando el navegador:', error.message);
    }

    sessions.delete(slug);
    return { status: 'stopped' };
}

function statusOf(slug) {
    const session = sessions.get(slug);
    if (!session) return { status: 'idle' };
    return { status: session.status, error: session.lastError || null };
}

module.exports = { join, leave, statusOf };
