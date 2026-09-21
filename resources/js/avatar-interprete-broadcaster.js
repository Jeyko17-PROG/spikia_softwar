// Modo 'human_live' — lado del INTERPRETE real (pagina /sesiones/{slug}/interprete).
//
// Captura la camara del interprete, corre la segmentacion de persona/fondo en el navegador
// (MediaPipe Selfie Segmentation, 100% local, assets self-hosted en /vendor/mediapipe/ en vez
// de un CDN externo), compone un canvas de salida con el interprete recortado sobre fondo
// croma, y publica ese canvas (+ el audio del microfono) a una sala de LiveKit para que los
// oyentes (avatar-interprete-viewer.js) lo reciban en /transmision.
//
// Si LIVEKIT_URL/API_KEY/API_SECRET no estan configurados en el servidor, la vista sigue
// siendo util como preview local (el interprete se ve a si mismo) pero nadie mas recibe la
// señal - ver el mensaje de estado que setStatus() muestra en ese caso.

import { SelfieSegmentation } from '@mediapipe/selfie_segmentation';
import { Room, LocalVideoTrack, LocalAudioTrack } from 'livekit-client';

const CHROMA_COLOR = { r: 0, g: 177, b: 64 }; // Verde croma estandar

function init() {
    const config = window.__SPIKIA_INTERPRETE__;
    const sourceVideo = document.getElementById('interprete-source-video');
    const outputCanvas = document.getElementById('interprete-output-canvas');
    const statusEl = document.getElementById('interprete-status');

    if (!config || !config.slug || !sourceVideo || !outputCanvas) {
        return;
    }

    const ctx = outputCanvas.getContext('2d');
    let running = false;
    let micStream = null;
    let room = null;

    function setStatus(text) {
        if (statusEl) {
            statusEl.textContent = text;
        }
    }

    function resizeCanvas() {
        outputCanvas.width = sourceVideo.videoWidth || 640;
        outputCanvas.height = sourceVideo.videoHeight || 480;
    }

    function onSegmentationResults(results) {
        if (!running) {
            return;
        }

        resizeCanvas();
        const { width, height } = outputCanvas;

        ctx.save();
        ctx.clearRect(0, 0, width, height);

        // Fondo croma solido.
        ctx.fillStyle = `rgb(${CHROMA_COLOR.r}, ${CHROMA_COLOR.g}, ${CHROMA_COLOR.b})`;
        ctx.fillRect(0, 0, width, height);

        // La mascara de segmentacion (results.segmentationMask) marca donde esta la persona:
        // se usa como "recorte" para pintar el video original solo en esa zona.
        ctx.drawImage(results.segmentationMask, 0, 0, width, height);
        ctx.globalCompositeOperation = 'source-in';
        ctx.drawImage(results.image, 0, 0, width, height);
        ctx.globalCompositeOperation = 'source-over';

        ctx.restore();
    }

    async function startCapture() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { width: 1280, height: 720, facingMode: 'user' },
                audio: true,
            });
            micStream = stream;
            sourceVideo.srcObject = stream;
            await sourceVideo.play();
        } catch (error) {
            setStatus('No se pudo acceder a la camara/microfono: ' + error.message);
            console.error('avatar-interprete: getUserMedia fallo:', error);
            return false;
        }
        return true;
    }

    function startSegmentationLoop() {
        const selfieSegmentation = new SelfieSegmentation({
            // Self-hosted a proposito (ver comentario de cabecera): sin esto, MediaPipe
            // intenta bajar los binarios desde un CDN publico en cada carga.
            locateFile: (file) => `/vendor/mediapipe/selfie_segmentation/${file}`,
        });
        selfieSegmentation.setOptions({ modelSelection: 1 });
        selfieSegmentation.onResults(onSegmentationResults);

        running = true;

        async function frameLoop() {
            if (!running) {
                return;
            }
            if (sourceVideo.readyState >= 2) {
                await selfieSegmentation.send({ image: sourceVideo });
            }
            requestAnimationFrame(frameLoop);
        }

        requestAnimationFrame(frameLoop);
    }

    // outputCanvas.captureStream(30) da un MediaStream real del video ya procesado (croma
    // incluido); se publica ese track de video + el track de audio del microfono original a
    // una sala de LiveKit nombrada como el slug de la sesion, para que avatar-interprete-viewer.js
    // (que se conecta a la MISMA sala del lado del oyente) los reciba.
    async function connectToViewers() {
        if (!config.livekitTokenUrl) {
            setStatus('Vista previa local activa. Falta configurar LiveKit en el servidor para transmitir en vivo.');
            return;
        }

        let tokenData;
        try {
            const res = await fetch(config.livekitTokenUrl, { headers: { Accept: 'application/json' } });
            const body = await res.json().catch(() => ({}));
            if (!res.ok) {
                setStatus('No se pudo obtener el token de video: ' + (body.message || res.status));
                return;
            }
            tokenData = body;
        } catch (error) {
            setStatus('No se pudo contactar al servidor para el token de video: ' + error.message);
            return;
        }

        try {
            room = new Room();
            await room.connect(tokenData.url, tokenData.token);

            const canvasStream = outputCanvas.captureStream(30);
            const videoTrack = new LocalVideoTrack(canvasStream.getVideoTracks()[0]);
            await room.localParticipant.publishTrack(videoTrack, { name: 'interprete-video' });

            const audioTrackNative = micStream?.getAudioTracks()[0];
            if (audioTrackNative) {
                const audioTrack = new LocalAudioTrack(audioTrackNative);
                await room.localParticipant.publishTrack(audioTrack, { name: 'interprete-audio' });
            }

            setStatus('En vivo: los oyentes ya reciben esta señal.');
        } catch (error) {
            console.error('LiveKit publish error:', error);
            setStatus('No se pudo conectar al servicio de video en vivo: ' + error.message);
        }
    }

    window.addEventListener('beforeunload', () => {
        try { room?.disconnect(); } catch (e) {}
    });

    (async () => {
        setStatus('Solicitando permiso de camara...');
        const ok = await startCapture();
        if (!ok) {
            return;
        }
        setStatus('Procesando video (segmentacion de fondo)...');
        startSegmentationLoop();
        await connectToViewers();
    })();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
