import { downloadBrandedQrPng } from './qr-download';

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Enlace copiado al portapapeles');
    });
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
