import { downloadBrandedQrPng } from './qr-download';
import { spikiaAlert } from './spikia-notify';

function legacyCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch (error) {
        ok = false;
    }
    textarea.remove();
    return ok;
}

async function copyToClipboard(text) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            spikiaAlert('Enlace copiado al portapapeles');
            return;
        }
    } catch (error) {
        // sigue al fallback de abajo en vez de fallar en silencio
    }

    if (legacyCopy(text)) {
        spikiaAlert('Enlace copiado al portapapeles');
    } else {
        spikiaAlert('No se pudo copiar automáticamente. Copialo a mano: ' + text, { tone: 'error', duration: 4500 });
    }
}

async function downloadQrPng(slug, url = '', code = '') {
    const config = window.__SPIKIA_SESSIONS_INDEX__ || {};

    await downloadBrandedQrPng({
        wrapperId: `qr-wrap-${slug}`,
        filename: `qr-${slug}.zip`,
        branding: {
            eyebrow: config.brandEyebrow || 'SPIKIA LIVE',
            title: config.brandTitle || 'ACCESO DE INVITADOS',
            code,
            subtitle: config.brandSubtitle || 'Escanea el código con la cámara de tu celular, o entra a',
            url: url || config.brandUrl || '',
        },
    });
}

window.copyToClipboard = copyToClipboard;
window.downloadQrPng = downloadQrPng;
