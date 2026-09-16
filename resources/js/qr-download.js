function loadImage(src) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.crossOrigin = 'anonymous';
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error(`No se pudo cargar la imagen: ${src}`));
        image.src = src;
    });
}

function drawRoundedRect(ctx, x, y, width, height, radius) {
    const r = Math.min(radius, width / 2, height / 2);

    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + width, y, x + width, y + height, r);
    ctx.arcTo(x + width, y + height, x, y + height, r);
    ctx.arcTo(x, y + height, x, y, r);
    ctx.arcTo(x, y, x + width, y, r);
    ctx.closePath();
}

function crc32(bytes) {
    let crc = 0xffffffff;

    for (let i = 0; i < bytes.length; i += 1) {
        crc ^= bytes[i];
        for (let j = 0; j < 8; j += 1) {
            crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
        }
    }

    return (crc ^ 0xffffffff) >>> 0;
}

function utf8Bytes(text) {
    return new TextEncoder().encode(text);
}

function createZipBlob(files) {
    const encoder = new TextEncoder();
    const localParts = [];
    const centralParts = [];
    let offset = 0;

    files.forEach((file) => {
        const nameBytes = encoder.encode(file.name);
        const data = file.data instanceof Uint8Array ? file.data : new Uint8Array(file.data);
        const crc = crc32(data);
        const size = data.length;

        const local = new ArrayBuffer(30 + nameBytes.length);
        const localView = new DataView(local);
        localView.setUint32(0, 0x04034b50, true);
        localView.setUint16(4, 20, true);
        localView.setUint16(6, 0, true);
        localView.setUint16(8, 0, true);
        localView.setUint16(10, 0, true);
        localView.setUint16(12, 0, true);
        localView.setUint32(14, crc, true);
        localView.setUint32(18, size, true);
        localView.setUint32(22, size, true);
        localView.setUint16(26, nameBytes.length, true);
        localView.setUint16(28, 0, true);
        new Uint8Array(local, 30).set(nameBytes);

        localParts.push(new Uint8Array(local), data);

        const central = new ArrayBuffer(46 + nameBytes.length);
        const centralView = new DataView(central);
        centralView.setUint32(0, 0x02014b50, true);
        centralView.setUint16(4, 20, true);
        centralView.setUint16(6, 20, true);
        centralView.setUint16(8, 0, true);
        centralView.setUint16(10, 0, true);
        centralView.setUint16(12, 0, true);
        centralView.setUint16(14, 0, true);
        centralView.setUint32(16, crc, true);
        centralView.setUint32(20, size, true);
        centralView.setUint32(24, size, true);
        centralView.setUint16(28, nameBytes.length, true);
        centralView.setUint16(30, 0, true);
        centralView.setUint16(32, 0, true);
        centralView.setUint16(34, 0, true);
        centralView.setUint16(36, 0, true);
        centralView.setUint32(38, 0, true);
        centralView.setUint32(42, offset, true);
        new Uint8Array(central, 46).set(nameBytes);

        centralParts.push(new Uint8Array(central));
        offset += local.byteLength + size;
    });

    const centralSize = centralParts.reduce((sum, part) => sum + part.length, 0);
    const localSize = localParts.reduce((sum, part) => sum + part.length, 0);
    const end = new ArrayBuffer(22);
    const endView = new DataView(end);
    endView.setUint32(0, 0x06054b50, true);
    endView.setUint16(4, 0, true);
    endView.setUint16(6, 0, true);
    endView.setUint16(8, files.length, true);
    endView.setUint16(10, files.length, true);
    endView.setUint32(12, centralSize, true);
    endView.setUint32(16, localSize, true);
    endView.setUint16(20, 0, true);

    return new Blob([...localParts, ...centralParts, new Uint8Array(end)], {
        type: 'application/zip',
    });
}

async function blobToUint8Array(blob) {
    const buffer = await blob.arrayBuffer();
    return new Uint8Array(buffer);
}

async function canvasToBlob(canvas, type = 'image/png') {
    return new Promise((resolve) => {
        canvas.toBlob((blob) => resolve(blob), type);
    });
}

function drawLogoBadge(ctx, image, x, y, size) {
    const radius = Math.round(size * 0.22);

    ctx.save();
    drawRoundedRect(ctx, x, y, size, size, radius);
    ctx.clip();
    ctx.fillStyle = '#08101d';
    ctx.fillRect(x, y, size, size);
    ctx.drawImage(image, x, y, size, size);
    ctx.restore();
}

export async function downloadBrandedQrPng({
    wrapperId,
    filename,
    branding = {},
}) {
    const wrapper = document.getElementById(wrapperId);
    const svg = wrapper ? wrapper.querySelector('svg') : null;

    if (!svg) {
        alert('No se encontró el QR.');
        return;
    }

    const logoUrl = branding.logoUrl || '/storage/media/images/spikia-25.png';
    const stem = filename.replace(/\.zip$/i, '').replace(/\.png$/i, '');

    const svgData = new XMLSerializer().serializeToString(svg);
    const svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
    const url = URL.createObjectURL(svgBlob);
    const img = new Image();

    img.onload = async () => {
        try {
            const logo = await loadImage(logoUrl);
            const qrSize = Math.max(img.width, img.height) || 1024;
            const topSpace = Math.round(qrSize * 0.18);
            const bottomSpace = Math.round(qrSize * (branding.code ? 0.2 : 0.14));
            const canvas = document.createElement('canvas');
            canvas.width = qrSize;
            canvas.height = qrSize + topSpace + bottomSpace;

            const ctx = canvas.getContext('2d');
            const background = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
            background.addColorStop(0, '#050816');
            background.addColorStop(0.45, '#10153a');
            background.addColorStop(1, '#03040a');
            ctx.fillStyle = background;
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            const headerLogoSize = Math.round(qrSize * 0.09);
            const headerLogoX = Math.round((canvas.width - headerLogoSize) / 2);
            const headerLogoY = Math.round(qrSize * 0.04);
            drawLogoBadge(ctx, logo, headerLogoX, headerLogoY, headerLogoSize);

            ctx.fillStyle = '#ffffff';
            ctx.font = `800 ${Math.round(qrSize * 0.028)}px "Segoe UI", Arial, sans-serif`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText((branding.title || 'SPIKIA').toUpperCase(), canvas.width / 2, headerLogoY + headerLogoSize + Math.round(qrSize * 0.028));

            const qrCardX = Math.round(qrSize * 0.06);
            const qrCardY = topSpace - Math.round(qrSize * 0.005);
            const qrCardSize = Math.round(qrSize * 0.88);
            const qrPad = Math.round(qrSize * 0.06);

            ctx.save();
            ctx.shadowColor = 'rgba(0, 0, 0, 0.35)';
            ctx.shadowBlur = 28;
            ctx.shadowOffsetY = 12;
            drawRoundedRect(ctx, qrCardX, qrCardY, qrCardSize, qrCardSize, Math.round(qrSize * 0.07));
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.restore();

            const qrX = qrCardX + qrPad;
            const qrY = qrCardY + qrPad;
            const qrCanvasSize = qrCardSize - (qrPad * 2);

            ctx.save();
            drawRoundedRect(ctx, qrX, qrY, qrCanvasSize, qrCanvasSize, Math.round(qrSize * 0.05));
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.clip();
            ctx.drawImage(img, qrX, qrY, qrCanvasSize, qrCanvasSize);
            ctx.restore();

            const centerSize = Math.round(qrCanvasSize * 0.24);
            const centerX = qrX + Math.round((qrCanvasSize - centerSize) / 2);
            const centerY = qrY + Math.round((qrCanvasSize - centerSize) / 2);

            ctx.save();
            ctx.shadowColor = 'rgba(0, 0, 0, 0.2)';
            ctx.shadowBlur = 12;
            ctx.shadowOffsetY = 4;
            drawRoundedRect(ctx, centerX, centerY, centerSize, centerSize, Math.round(centerSize * 0.24));
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.restore();
            drawLogoBadge(ctx, logo, centerX + 6, centerY + 6, centerSize - 12);

            if (branding.code) {
                ctx.fillStyle = '#ffffff';
                ctx.font = `800 ${Math.round(qrSize * 0.032)}px "Consolas", "Courier New", monospace`;
                ctx.fillText(branding.code, canvas.width / 2, canvas.height - Math.round(bottomSpace * 0.74));
            }

            ctx.fillStyle = '#dbe6f3';
            ctx.font = `600 ${Math.round(qrSize * 0.017)}px "Segoe UI", Arial, sans-serif`;
            ctx.fillText(branding.subtitle || 'Panel de sesiones', canvas.width / 2, canvas.height - Math.round(bottomSpace * (branding.code ? 0.42 : 0.56)));

            if (branding.url) {
                ctx.fillStyle = '#63f5ff';
                ctx.font = `700 ${Math.round(qrSize * 0.0135)}px "Consolas", "Courier New", monospace`;
                ctx.fillText(branding.url, canvas.width / 2, canvas.height - Math.round(bottomSpace * 0.14));
            }

            const pngBlob = await canvasToBlob(canvas, 'image/png');
            if (!pngBlob) {
                alert('No se pudo generar el PNG.');
                URL.revokeObjectURL(url);
                return;
            }

            const zipBlob = createZipBlob([
                {
                    name: `${stem}.png`,
                    data: await blobToUint8Array(pngBlob),
                },
            ]);

            const link = document.createElement('a');
            link.href = URL.createObjectURL(zipBlob);
            link.download = filename.endsWith('.zip') ? filename : `${stem}.zip`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(link.href);
            URL.revokeObjectURL(url);
        } catch (error) {
            alert('No se pudo generar el QR con branding.');
            URL.revokeObjectURL(url);
        }
    };

    img.onerror = () => {
        alert('No se pudo convertir el QR a PNG.');
        URL.revokeObjectURL(url);
    };

    img.src = url;
}
