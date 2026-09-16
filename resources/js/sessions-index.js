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
            logoUrl: config.logoUrl,
            title: config.brandTitle || 'SPIKIA',
            code,
            subtitle: config.brandSubtitle || (code ? 'Escanea el código o entra a' : 'Panel de sesiones'),
            url: url || config.brandUrl || '',
        },
    });
}

window.copyToClipboard = copyToClipboard;
window.downloadQrPng = downloadQrPng;
