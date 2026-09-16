/**
 * PoC/benchmark - brazo B (ver informe "A vs B: menor latencia real"): sesion de
 * traduccion DEDICADA de OpenAI (gpt-realtime-translate). A diferencia del brazo A
 * (gpt-realtime-2.1 con turnos), esta sesion transmite CONTINUO: no hay response.create,
 * no hay turnos, no hay evento de "frase terminada". El modelo va mandando deltas de
 * transcripcion (idioma original) y de traduccion sin parar mientras llega audio.
 *
 * Verificado contra la documentacion/cookbook oficial de OpenAI:
 * - Audio de entrada: PCM16 24kHz, { type: "input_audio_buffer.append", audio: base64 }.
 * - Eventos: session.input_transcript.delta (texto original, campo "delta"),
 *   session.output_transcript.delta (texto traducido, campo "delta"),
 *   session.output_audio.delta (audio traducido - se IGNORA, este PoC no reemplaza TTS).
 * - Esta sesion NO soporta instructions/prompt personalizado ni turn_detection/VAD - la
 *   configuracion (idioma de salida, transcripcion) se fija al emitir el token
 *   (ver OpenAiRealtimeTokenService::mintClientSecretForDedicatedTranslation).
 *
 * HEURISTICA DE SEGMENTACION (SOLO para este benchmark, pedido explicitamente): como no
 * hay una señal real de "fin de frase" ni de "inicio de audio" (sin VAD), se usa un timer
 * de silencio - si no llega NINGUN delta nuevo (transcripcion o traduccion) durante
 * segmentGapMs, se considera el segmento "terminado" para efectos de medicion. El
 * "inicio" del segmento tambien es una aproximacion: el momento del PRIMER delta
 * observado tras el gap, no el audio real (no hay forma de saberlo sin VAD en este brazo -
 * ver limitacion documentada en el informe). Esto NO cambia el comportamiento real del
 * modelo, solo como Spikia decide cortar la medicion.
 */

const AUDIO_SAMPLE_RATE = 24000;

export function createDedicatedTranslationSegmenter(segmentGapMs, onSegmentEvent) {
    let segment = null;
    let gapTimer = null;

    function resetGapTimer() {
        if (gapTimer) window.clearTimeout(gapTimer);
        gapTimer = window.setTimeout(finishSegment, segmentGapMs);
    }

    function ensureSegment(now) {
        if (!segment) {
            segment = {
                startedAt: now,
                firstTranscriptDeltaAt: null,
                firstTranslationDeltaAt: null,
                inputText: '',
                outputText: '',
            };
        }
        resetGapTimer();
    }

    function onTranscriptDelta(text, now) {
        ensureSegment(now);
        if (!segment.firstTranscriptDeltaAt) segment.firstTranscriptDeltaAt = now;
        segment.inputText += text || '';
    }

    function onTranslationDelta(text, now) {
        ensureSegment(now);
        if (!segment.firstTranslationDeltaAt) {
            segment.firstTranslationDeltaAt = now;
            onSegmentEvent('first_translation_delta', segment, now);
        }
        segment.outputText += text || '';
    }

    function finishSegment() {
        gapTimer = null;
        if (!segment || !segment.outputText.trim()) {
            segment = null;
            return;
        }
        const finished = segment;
        segment = null;
        onSegmentEvent('end', finished, Date.now());
    }

    return { onTranscriptDelta, onTranslationDelta };
}

export function createDedicatedTranslationTranslator(config) {
    let ws = null;
    let audioCtx = null;
    let audioSource = null;
    let audioProcessor = null;
    let healthy = false;
    let sessionReady = false;
    let onEvent = () => {};

    async function mintToken() {
        const response = await fetch(config.realtimeTokenUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken,
            },
            body: JSON.stringify({
                sesion_id: config.sesionId,
                idioma: config.realtimePocLanguage || 'en',
                mode: 'translate_dedicated',
            }),
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || `No se pudo obtener token Realtime (brazo B) (${response.status}).`);
        }
        return data;
    }

    function downsampleAndConvert(float32Array, srcRate) {
        const ratio = srcRate / AUDIO_SAMPLE_RATE;
        const newLen = Math.floor(float32Array.length / ratio);
        const out = new Int16Array(newLen);
        for (let i = 0; i < newLen; i++) {
            const sample = float32Array[Math.floor(i * ratio)] || 0;
            const clamped = Math.max(-1, Math.min(1, sample));
            out[i] = clamped < 0 ? clamped * 0x8000 : clamped * 0x7FFF;
        }
        return out;
    }

    function int16ToBase64(int16) {
        const bytes = new Uint8Array(int16.buffer);
        let binary = '';
        const chunkSize = 0x8000;
        for (let i = 0; i < bytes.length; i += chunkSize) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunkSize));
        }
        return window.btoa(binary);
    }

    function startStreamingAudio(mediaStream) {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        audioCtx = new Ctx();
        audioSource = audioCtx.createMediaStreamSource(mediaStream);
        audioProcessor = audioCtx.createScriptProcessor(2048, 1, 1);
        audioProcessor.onaudioprocess = (e) => {
            if (!ws || ws.readyState !== WebSocket.OPEN) return;
            const inputData = e.inputBuffer.getChannelData(0);
            const pcm = downsampleAndConvert(inputData, audioCtx.sampleRate);
            ws.send(JSON.stringify({
                type: 'session.input_audio_buffer.append',
                audio: int16ToBase64(pcm),
            }));
        };
        audioSource.connect(audioProcessor);
        audioProcessor.connect(audioCtx.destination);
    }

    function stopStreamingAudio() {
        try { audioProcessor && audioProcessor.disconnect(); } catch (e) { /* noop */ }
        try { audioSource && audioSource.disconnect(); } catch (e) { /* noop */ }
        try { audioCtx && audioCtx.close(); } catch (e) { /* noop */ }
        audioProcessor = null;
        audioSource = null;
        audioCtx = null;
    }

    function handleMessage(event) {
        let payload;
        try {
            payload = JSON.parse(event.data);
        } catch (e) {
            return;
        }
        const now = Date.now();

        if (payload.type === 'session.input_transcript.delta') {
            onEvent('transcript_delta', payload.delta || '', now);
        } else if (payload.type === 'session.output_transcript.delta') {
            onEvent('translation_delta', payload.delta || '', now);
        } else if (payload.type === 'error') {
            onEvent('error', payload, now);
        }
        // session.output_audio.delta se ignora a proposito: este PoC no reemplaza TTS.
    }

    function setEventHandler(fn) {
        onEvent = fn;
    }

    async function connect(mediaStream) {
        const token = await mintToken();
        const model = token.model || 'gpt-realtime-translate';
        const url = `wss://api.openai.com/v1/realtime?model=${encodeURIComponent(model)}`;

        await new Promise((resolve, reject) => {
            let settled = false;
            const socket = new WebSocket(url, [
                'realtime',
                'openai-insecure-api-key.' + token.value,
            ]);

            socket.onopen = () => {
                ws = socket;
                healthy = true;
                sessionReady = true;
                settled = true;
                startStreamingAudio(mediaStream);
                resolve();
            };

            socket.onmessage = handleMessage;

            socket.onerror = () => {
                healthy = false;
                if (!settled) {
                    settled = true;
                    reject(new Error('No se pudo abrir la conexion Realtime (brazo B).'));
                }
            };

            socket.onclose = () => {
                healthy = false;
                sessionReady = false;
                if (ws === socket) ws = null;
                stopStreamingAudio();
                if (!settled) {
                    settled = true;
                    reject(new Error('Conexion Realtime (brazo B) cerrada antes de confirmar sesion.'));
                }
            };
        });
    }

    function isHealthy() {
        return healthy && sessionReady && !!ws;
    }

    function close() {
        healthy = false;
        sessionReady = false;
        stopStreamingAudio();
        if (ws) {
            try { ws.close(); } catch (e) { /* noop */ }
        }
        ws = null;
    }

    return { connect, setEventHandler, isHealthy, close };
}
