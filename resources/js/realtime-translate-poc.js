/**
 * PoC/benchmark (ver informe "reduccion de delay a 1-2s"): traduccion ES->EN via OpenAI
 * Realtime API, en paralelo al motor actual (Chat Completions / /traducciones/batch), que
 * NO se toca. Se activa solo si config.translationEngine === 'realtime_experimental'.
 *
 * Requisitos de diseño (ver informe):
 * - UNA sola conexion WebSocket por sesion Master, abierta al inicio y mantenida viva
 *   durante toda la sesion (NO una conexion por frase).
 * - Si la conexion falla o se cae, master.js debe poder seguir usando el motor actual
 *   sin que el usuario note nada raro (ver isHealthy()).
 * - Solo cubre el idioma configurado en config.realtimePocLanguage (hoy 'en').
 *
 * Conexion verificada contra la documentacion oficial de OpenAI (guias "voice-webrtc",
 * "realtime-websocket", "realtime-conversations"):
 * - El browser no puede mandar headers custom en un WebSocket nativo -> la autenticacion
 *   va por el subprotocolo "openai-insecure-api-key.<token efimero>", NO por header.
 * - wss://api.openai.com/v1/realtime?model=<model>
 * - Sesion de solo texto: session.update con output_modalities: ["text"].
 * - Una frase = conversation.item.create (role user, input_text) + response.create.
 * - Resultado final en el evento response.done
 *   (response.output[0].content[0].text); response.output_text.delta va llegando antes,
 *   pero para el benchmark se usa el texto final de response.done.
 */

const RESPONSE_TIMEOUT_MS = 6000; // si Realtime no contesta en este tiempo, se cuenta como fallo (ver requisito 8)

export function createRealtimeTranslator(config) {
    let ws = null;
    let connecting = null;
    let healthy = false;
    let queue = Promise.resolve();
    let sessionReady = false;

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
            }),
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || `No se pudo obtener token Realtime (${response.status}).`);
        }
        return data; // { value, model, expires_at }
    }

    function connect() {
        if (connecting) return connecting;

        connecting = (async () => {
            const token = await mintToken();
            const model = token.model || 'gpt-realtime-2.1';
            const url = `wss://api.openai.com/v1/realtime?model=${encodeURIComponent(model)}`;

            await new Promise((resolve, reject) => {
                let settled = false;
                const socket = new WebSocket(url, [
                    'realtime',
                    'openai-insecure-api-key.' + token.value,
                ]);

                socket.onopen = () => {
                    // Red de seguridad: aunque el token ya viene pre-configurado en modo
                    // texto (ver OpenAiRealtimeTokenService), se reafirma aqui por si la
                    // configuracion de sesion en client_secrets no aplica igual sobre WS.
                    socket.send(JSON.stringify({
                        type: 'session.update',
                        session: {
                            type: 'realtime',
                            output_modalities: ['text'],
                        },
                    }));
                    sessionReady = true;
                    healthy = true;
                    ws = socket;
                    settled = true;
                    resolve();
                };

                socket.onmessage = handleMessage;

                socket.onerror = () => {
                    healthy = false;
                    if (!settled) {
                        settled = true;
                        reject(new Error('No se pudo abrir la conexion Realtime.'));
                    }
                };

                socket.onclose = () => {
                    healthy = false;
                    sessionReady = false;
                    if (ws === socket) ws = null;
                    if (!settled) {
                        settled = true;
                        reject(new Error('Conexion Realtime cerrada antes de confirmar sesion.'));
                    }
                };
            });
        })().catch((error) => {
            healthy = false;
            connecting = null;
            throw error;
        });

        return connecting;
    }

    // Solo puede haber una respuesta pendiente a la vez (la Realtime API no da un id de
    // correlacion util para varias respuestas en simultaneo dentro de la misma sesion).
    let pending = null;

    function handleMessage(event) {
        let payload;
        try {
            payload = JSON.parse(event.data);
        } catch (e) {
            return;
        }

        if (!pending) return;

        if (payload.type === 'response.done') {
            const textNode = payload.response?.output?.[0]?.content?.find(
                (c) => c.type === 'output_text' || c.type === 'text'
            );
            const text = (textNode?.text || '').trim();
            const resolver = pending;
            pending = null;
            if (text) {
                resolver.resolve(text);
            } else {
                resolver.reject(new Error('Realtime respondio sin texto.'));
            }
        } else if (payload.type === 'error' || payload.type === 'response.failed') {
            const resolver = pending;
            pending = null;
            resolver.reject(new Error(payload.error?.message || 'Error en Realtime.'));
        }
    }

    async function translateOne(text) {
        if (!ws || !sessionReady) {
            throw new Error('Realtime no esta conectado.');
        }

        return new Promise((resolve, reject) => {
            const timeoutId = window.setTimeout(() => {
                if (pending && pending.reject === reject) {
                    pending = null;
                    reject(new Error('Timeout esperando traduccion de Realtime.'));
                }
            }, RESPONSE_TIMEOUT_MS);

            pending = {
                resolve: (value) => {
                    window.clearTimeout(timeoutId);
                    resolve(value);
                },
                reject: (error) => {
                    window.clearTimeout(timeoutId);
                    reject(error);
                },
            };

            ws.send(JSON.stringify({
                type: 'conversation.item.create',
                item: {
                    type: 'message',
                    role: 'user',
                    content: [{ type: 'input_text', text }],
                },
            }));
            ws.send(JSON.stringify({
                type: 'response.create',
                response: { output_modalities: ['text'] },
            }));
        });
    }

    /**
     * Traduce una frase. Las llamadas se serializan (una respuesta Realtime a la vez);
     * en el flujo real de Spikia las frases finales llegan espaciadas (no en rafaga), asi
     * que esto no debería notarse, pero se documenta como limite conocido del PoC.
     */
    function translate(text) {
        const result = queue.then(() => translateOne(text));
        // Si esta frase falla, no debe tumbar la cola para la siguiente.
        queue = result.catch(() => {});
        return result;
    }

    function isHealthy() {
        return healthy && sessionReady && !!ws;
    }

    function close() {
        healthy = false;
        sessionReady = false;
        if (ws) {
            try { ws.close(); } catch (e) { /* noop */ }
        }
        ws = null;
        connecting = null;
    }

    return { connect, translate, isHealthy, close };
}

/**
 * Reporta el resultado de una traduccion Realtime al mismo camino de
 * persistencia/relay/broadcast que ya usa el motor actual (ver
 * TraduccionController::storeRealtimeBenchmark), y registra los timestamps de cada etapa.
 */
export async function reportRealtimeBenchmark(config, payload) {
    const response = await fetch(config.realtimeBenchmarkUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': config.csrfToken,
        },
        body: JSON.stringify(payload),
    });

    return response.json().catch(() => ({}));
}
