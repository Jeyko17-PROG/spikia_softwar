/**
 * Motor de produccion "Audio+VAD" (ver informe "menor latencia posible"): AUDIO directo a
 * OpenAI Realtime, sin pasar por Web Speech API. Usa el modelo Realtime GENERAL (no la
 * sesion dedicada de traduccion "gpt-realtime-translate": esa es audio-only pero NO expone
 * VAD configurable, y aca necesitamos poder tunear el turn detection - ver informe).
 *
 * Cubre TODOS los idiomas destino de la sesion: abre UNA conexion WebSocket por idioma
 * (todas al inicio, mantenidas vivas toda la sesion - NO una por frase), reusando el MISMO
 * MediaStream del microfono para las N conexiones (un solo permiso, un solo AudioContext,
 * el mismo audio PCM se retransmite a cada socket abierto).
 *
 * Requisitos de diseño:
 * - Turn detection (VAD) configurado en el servidor al emitir el token
 *   (ver OpenAiRealtimeTokenService::mintClientSecretForAudio), no aqui.
 * - Cada idioma corre su propio VAD server-side de forma independiente (misma fuente de
 *   audio, N sesiones Realtime separadas) - no hay coordinacion entre idiomas, cada uno
 *   detecta y comitea su propio turno.
 * - Reporta 3 timestamps por frase y por idioma: inicio real de habla (evento del VAD del
 *   servidor, NO un proxy del cliente), primer resultado parcial, y resultado final.
 *
 * Verificado contra documentacion oficial de OpenAI (guias "realtime-conversations",
 * "realtime-vad"):
 * - Formato de audio: session.audio.input.format = { type: "audio/pcm", rate: 24000 }.
 * - Envio: { type: "input_audio_buffer.append", audio: "<base64 PCM16>" }.
 * - Limite VAD: input_audio_buffer.speech_started / speech_stopped marcan los bordes que
 *   el propio VAD del servidor detecto (no hace falta que el cliente adivine el inicio).
 * - Streaming de texto: response.output_text.delta (parcial) -> response.done (final).
 * - Transcript del audio de ENTRADA (idioma original):
 *   conversation.item.input_audio_transcription.completed.
 */

const AUDIO_SAMPLE_RATE = 24000; // debe coincidir con config('spikia.realtime_translation.audio_sample_rate')

export function createRealtimeAudioTranslator(config) {
    const connections = new Map(); // lang -> { ws, healthy, sessionReady, timeline, firstAudioSent }
    let audioCtx = null;
    let audioSource = null;
    let audioProcessor = null;
    let onEvent = () => {};
    let pendingMediaStream = null;

    // Arranca el pipeline de audio (AudioContext + ScriptProcessorNode, trabajo en el hilo
    // principal) SOLO cuando al menos un idioma logro conectar de verdad - antes se
    // arrancaba sin condicion apenas se llamaba a connect(), lo que dejaba el procesamiento
    // corriendo toda la sesion aunque NINGUNA conexion llegara a abrirse (ej. sin
    // OPENAI_API_KEY configurada), gastando CPU del hilo principal por nada y sumando a la
    // lentitud reportada justo al hablar (el motor de traduccion actual via texto sigue
    // andando igual como fallback - ver isHealthy() en master.js).
    function anyConnectionHealthy() {
        for (const conn of connections.values()) {
            if (conn.ws && conn.ws.readyState === WebSocket.OPEN) return true;
        }
        return false;
    }

    function ensureStreamingAudio() {
        if (audioCtx || !pendingMediaStream) return;
        startStreamingAudio(pendingMediaStream);
    }

    function stopStreamingIfIdle() {
        if (audioCtx && !anyConnectionHealthy()) {
            stopStreamingAudio();
        }
    }

    function connState(lang) {
        return connections.get(lang) || null;
    }

    function recordEvent(lang, name, customTs = Date.now()) {
        const conn = connState(lang);
        if (conn) conn.timeline.push({ name, ts: customTs });
    }

    function resetTimeline(lang) {
        const conn = connState(lang);
        if (!conn) return;
        conn.timeline = [];
        conn.firstAudioSent = false;
    }

    async function mintToken(lang) {
        const response = await fetch(config.realtimeTokenUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken,
            },
            body: JSON.stringify({
                sesion_id: config.sesionId,
                idioma: lang,
                mode: 'audio',
            }),
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || `No se pudo obtener token Realtime audio (${response.status}) para ${lang}.`);
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
        const chunkSize = 0x8000; // evitar exceder el limite de argumentos de fromCharCode
        for (let i = 0; i < bytes.length; i += chunkSize) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunkSize));
        }
        return window.btoa(binary);
    }

    // UN solo AudioContext/processor para toda la sesion: el mismo PCM se retransmite a
    // cada socket de idioma que este abierto en ese momento (evita N permisos/N graphs de
    // audio para lo que es, en el fondo, el mismo microfono).
    function startStreamingAudio(mediaStream) {
        if (audioCtx) return;
        const Ctx = window.AudioContext || window.webkitAudioContext;
        audioCtx = new Ctx();
        audioSource = audioCtx.createMediaStreamSource(mediaStream);
        audioProcessor = audioCtx.createScriptProcessor(2048, 1, 1);
        audioProcessor.onaudioprocess = (e) => {
            if (!connections.size) return;
            const inputData = e.inputBuffer.getChannelData(0);
            const pcm = downsampleAndConvert(inputData, audioCtx.sampleRate);
            const payload = JSON.stringify({
                type: 'input_audio_buffer.append',
                audio: int16ToBase64(pcm),
            });
            connections.forEach((conn, lang) => {
                if (!conn.ws || conn.ws.readyState !== WebSocket.OPEN) return;
                if (!conn.firstAudioSent) {
                    conn.firstAudioSent = true;
                    recordEvent(lang, 'first_audio_sent');
                }
                conn.ws.send(payload);
            });
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

    function handleMessage(lang) {
        return (event) => {
            let payload;
            try {
                payload = JSON.parse(event.data);
            } catch (e) {
                return;
            }
            const now = Date.now();
            const conn = connState(lang);
            if (!conn) return;

            if (payload.type === 'input_audio_buffer.speech_started') {
                resetTimeline(lang);
                recordEvent(lang, 'audio_start', now);
                recordEvent(lang, 'vad_speech_started', now);
                onEvent('speech_started', payload, now, lang);
            } else if (payload.type === 'input_audio_buffer.speech_stopped') {
                recordEvent(lang, 'vad_speech_stopped', now);
                if (conn.ws && conn.ws.readyState === WebSocket.OPEN) {
                    conn.ws.send(JSON.stringify({ type: 'input_audio_buffer.commit' }));
                }
                onEvent('speech_stopped', payload, now, lang);
            } else if (payload.type === 'conversation.item.input_audio_transcription.delta') {
                if (!conn.timeline.some((entry) => entry.name === 'first_input_transcript_delta')) {
                    recordEvent(lang, 'first_input_transcript_delta', now);
                }
                onEvent('input_transcript_delta', payload, now, lang);
            } else if (payload.type === 'conversation.item.input_audio_transcription.completed') {
                recordEvent(lang, 'input_transcript_completed', now);
                onEvent('source_transcript', { text: payload.transcript || '' }, now, lang);
            } else if (payload.type === 'input_audio_buffer.committed' || payload.type === 'conversation.item.input_audio_buffer.committed') {
                recordEvent(lang, 'input_committed', now);
                onEvent('input_committed', payload, now, lang);
            } else if (payload.type === 'response.output_text.delta') {
                if (!conn.timeline.some((entry) => entry.name === 'first_output_translation_delta')) {
                    recordEvent(lang, 'first_output_translation_delta', now);
                }
                onEvent('delta', payload, now, lang);
            } else if (payload.type === 'response.done') {
                const textNode = (payload.response?.output || [])
                    .flatMap((item) => item.content || [])
                    .find((c) => c.type === 'output_text' || c.type === 'text');
                recordEvent(lang, 'output_translation_completed', now);
                recordEvent(lang, 'response_completed', now);
                onEvent('final', { text: (textNode?.text || '').trim(), timeline: [...conn.timeline] }, now, lang);
            } else if (payload.type === 'error') {
                onEvent('error', payload, now, lang);
            }
        };
    }

    /**
     * Registra el callback que recibe TODOS los eventos relevantes (speech_started, delta,
     * final, source_transcript, error) con su timestamp Date.now() y el idioma al que
     * pertenece la conexion que lo disparo. Quien llama decide como correlacionarlos en
     * frases por idioma (ver master.js).
     */
    function setEventHandler(fn) {
        onEvent = fn;
    }

    function connectLanguage(lang) {
        return new Promise((resolve) => {
            connections.set(lang, { ws: null, healthy: false, sessionReady: false, timeline: [], firstAudioSent: false });

            mintToken(lang).then((token) => {
                const model = token.model || 'gpt-realtime-2.1';
                const url = `wss://api.openai.com/v1/realtime?model=${encodeURIComponent(model)}`;
                let settled = false;
                const socket = new WebSocket(url, [
                    'realtime',
                    'openai-insecure-api-key.' + token.value,
                ]);

                socket.onopen = () => {
                    const conn = connState(lang);
                    if (!conn) return;
                    conn.ws = socket;
                    conn.healthy = true;
                    conn.sessionReady = true;
                    settled = true;
                    ensureStreamingAudio();
                    resolve(true);
                };

                socket.onmessage = handleMessage(lang);

                socket.onerror = () => {
                    const conn = connState(lang);
                    if (conn) conn.healthy = false;
                    stopStreamingIfIdle();
                    if (!settled) {
                        settled = true;
                        console.warn(`Realtime (audio): no se pudo conectar para "${lang}", se usara el motor actual como fallback para este idioma.`);
                        resolve(false);
                    }
                };

                socket.onclose = () => {
                    const conn = connState(lang);
                    if (conn) {
                        conn.healthy = false;
                        conn.sessionReady = false;
                        if (conn.ws === socket) conn.ws = null;
                    }
                    stopStreamingIfIdle();
                    if (!settled) {
                        settled = true;
                        console.warn(`Realtime (audio): conexion cerrada antes de confirmar sesion para "${lang}".`);
                        resolve(false);
                    }
                };
            }).catch((error) => {
                console.warn(`Realtime (audio): fallo obteniendo token para "${lang}", se usara el motor actual como fallback para este idioma.`, error);
                connections.delete(lang);
                stopStreamingIfIdle();
                resolve(false);
            });
        });
    }

    /**
     * Abre una conexion por cada idioma de `languages` (todas en paralelo) y arranca la
     * captura de audio compartida. No rechaza si alguna(s) conexion(es) fallan - cada
     * idioma que no logro conectar simplemente queda fuera de isHealthy() y master.js cae
     * al motor actual solo para ese idioma puntual.
     */
    async function connect(mediaStream, languages) {
        pendingMediaStream = mediaStream;
        const targetLanguages = Array.from(new Set((languages || []).filter(Boolean)));
        await Promise.all(targetLanguages.map((lang) => connectLanguage(lang)));
    }

    function isHealthy(lang) {
        const conn = connState(lang);
        return !!(conn && conn.healthy && conn.sessionReady && conn.ws);
    }

    function close() {
        connections.forEach((conn) => {
            conn.healthy = false;
            conn.sessionReady = false;
            if (conn.ws) {
                try { conn.ws.close(); } catch (e) { /* noop */ }
            }
        });
        connections.clear();
        stopStreamingAudio();
        pendingMediaStream = null;
    }

    return { connect, setEventHandler, isHealthy, close };
}
