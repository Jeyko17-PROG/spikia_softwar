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

function setLetterSpacing(ctx, px) {
    try {
        ctx.letterSpacing = `${px}px`;
    } catch (error) {
        // Navegadores viejos sin soporte para letterSpacing en canvas: se ignora,
        // el texto se dibuja sin tracking pero sigue siendo legible.
    }
}

function wrapText(ctx, text, maxWidth) {
    const words = text.split(' ');
    const lines = [];
    let current = '';

    words.forEach((word) => {
        const test = current ? `${current} ${word}` : word;
        if (ctx.measureText(test).width > maxWidth && current) {
            lines.push(current);
            current = word;
        } else {
            current = test;
        }
    });

    if (current) {
        lines.push(current);
    }

    return lines;
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

    const stem = filename.replace(/\.zip$/i, '').replace(/\.png$/i, '');

    const svgData = new XMLSerializer().serializeToString(svg);
    const svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
    const url = URL.createObjectURL(svgBlob);
    const img = new Image();

    img.onload = async () => {
        try {
            const qrSize = Math.max(img.width, img.height) || 1024;
            const cardPad = Math.round(qrSize * 0.09);
            const eyebrow = (branding.eyebrow || 'SPIKIA LIVE').toUpperCase();
            const title = (branding.title || 'ACCESO DE INVITADOS').toUpperCase();
            const subtitle = branding.subtitle
                || 'Escanea el código con la cámara de tu celular, o entra a';

            const eyebrowSize = Math.round(qrSize * 0.026);
            const titleSize = Math.round(qrSize * 0.06);
            const codeSize = Math.round(qrSize * 0.045);
            const subtitleSize = Math.round(qrSize * 0.02);
            const urlSize = Math.round(qrSize * 0.017);

            const contentWidth = qrSize;
            const qrCardSize = qrSize;
            const qrPad = Math.round(qrSize * 0.07);

            // Medimos el titulo con un canvas temporal para saber cuantas lineas
            // necesita antes de fijar la altura real del canvas final.
            const measure = document.createElement('canvas').getContext('2d');
            measure.font = `900 ${titleSize}px "Segoe UI", Arial, sans-serif`;
            const titleLines = wrapText(measure, title, contentWidth - cardPad);
            measure.font = `600 ${subtitleSize}px "Segoe UI", Arial, sans-serif`;
            const subtitleLines = wrapText(measure, subtitle, contentWidth - cardPad * 1.4);

            const topBlock = Math.round(eyebrowSize * 2.6)
                + (titleLines.length * Math.round(titleSize * 1.15))
                + Math.round(qrSize * 0.05);
            const bottomBlock = Math.round(codeSize * 1.8)
                + (subtitleLines.length * Math.round(subtitleSize * 1.5))
                + (branding.url ? Math.round(urlSize * 2.2) : 0)
                + Math.round(qrSize * 0.06);

            const canvas = document.createElement('canvas');
            canvas.width = qrSize + cardPad * 2;
            canvas.height = topBlock + qrCardSize + bottomBlock + cardPad * 2;

            const ctx = canvas.getContext('2d');
            const cx = canvas.width / 2;
            const cardRadius = Math.round(qrSize * 0.07);

            // Tarjeta de fondo oscura con el mismo glow morado/neon que usa el
            // resto de la app (auth, soporte) en vez del gris plano anterior.
            drawRoundedRect(ctx, 0, 0, canvas.width, canvas.height, cardRadius);
            ctx.fillStyle = '#0b0b14';
            ctx.fill();

            ctx.save();
            drawRoundedRect(ctx, 0, 0, canvas.width, canvas.height, cardRadius);
            ctx.clip();
            const glowTop = ctx.createRadialGradient(
                canvas.width * 0.15, canvas.height * 0.08, 0,
                canvas.width * 0.15, canvas.height * 0.08, canvas.width * 0.6,
            );
            glowTop.addColorStop(0, 'rgba(124, 58, 237, 0.28)');
            glowTop.addColorStop(1, 'rgba(124, 58, 237, 0)');
            ctx.fillStyle = glowTop;
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            const glowBottom = ctx.createRadialGradient(
                canvas.width * 0.9, canvas.height * 0.95, 0,
                canvas.width * 0.9, canvas.height * 0.95, canvas.width * 0.6,
            );
            glowBottom.addColorStop(0, 'rgba(0, 210, 255, 0.16)');
            glowBottom.addColorStop(1, 'rgba(0, 210, 255, 0)');
            ctx.fillStyle = glowBottom;
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.restore();

            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            let y = cardPad + eyebrowSize;
            ctx.fillStyle = '#b9a6ff';
            ctx.font = `800 ${eyebrowSize}px "Segoe UI", Arial, sans-serif`;
            setLetterSpacing(ctx, Math.round(eyebrowSize * 0.35));
            ctx.fillText(eyebrow, cx, y);
            setLetterSpacing(ctx, 0);

            y += Math.round(eyebrowSize * 1.8);
            ctx.fillStyle = '#ffffff';
            ctx.font = `italic 900 ${titleSize}px "Segoe UI", Arial, sans-serif`;
            titleLines.forEach((line, index) => {
                ctx.fillText(line, cx, y + (index * Math.round(titleSize * 1.15)));
            });
            y += (titleLines.length * Math.round(titleSize * 1.15));

            const qrCardY = y + Math.round(qrSize * 0.05) - Math.round(titleSize * 0.3);
            const qrCardX = cardPad;

            ctx.save();
            ctx.shadowColor = 'rgba(0, 0, 0, 0.4)';
            ctx.shadowBlur = 30;
            ctx.shadowOffsetY = 14;
            drawRoundedRect(ctx, qrCardX, qrCardY, qrCardSize, qrCardSize, Math.round(qrSize * 0.08));
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.restore();

            const qrX = qrCardX + qrPad;
            const qrY = qrCardY + qrPad;
            const qrDrawSize = qrCardSize - (qrPad * 2);

            ctx.save();
            drawRoundedRect(ctx, qrX, qrY, qrDrawSize, qrDrawSize, Math.round(qrSize * 0.045));
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.clip();
            ctx.drawImage(img, qrX, qrY, qrDrawSize, qrDrawSize);
            ctx.restore();

            let by = qrCardY + qrCardSize + Math.round(codeSize * 1.1);

            if (branding.code) {
                ctx.fillStyle = '#ffffff';
                ctx.font = `800 ${codeSize}px "Segoe UI", Arial, sans-serif`;
                setLetterSpacing(ctx, Math.round(codeSize * 0.12));
                ctx.fillText(branding.code, cx, by);
                setLetterSpacing(ctx, 0);
                by += Math.round(codeSize * 1.3);
            }

            ctx.fillStyle = '#9a9ab0';
            ctx.font = `600 ${subtitleSize}px "Segoe UI", Arial, sans-serif`;
            subtitleLines.forEach((line, index) => {
                ctx.fillText(line, cx, by + (index * Math.round(subtitleSize * 1.5)));
            });
            by += (subtitleLines.length * Math.round(subtitleSize * 1.5));

            if (branding.url) {
                ctx.fillStyle = '#8f7bff';
                ctx.font = `700 ${urlSize}px "Consolas", "Courier New", monospace`;
                ctx.fillText(branding.url, cx, by + Math.round(urlSize * 0.9));
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
