// Cola de glosas por sesion con "pacing": manda UN frame a Unreal Engine, espera su
// duracion estimada (+ un margen configurable) y recien ahi manda el siguiente. Esto es lo
// que impide que las señas se atropellen entre si si la traduccion de una frase larga
// devuelve 10-15 frames de golpe.
//
// Si llega una secuencia NUEVA mientras la anterior todavia se esta reproduciendo (el
// presentador siguio hablando), la cola vieja se reemplaza por la nueva: igual que con el
// audio en vivo, lo que importa es lo mas reciente, no terminar de mostrar una frase vieja a
// destiempo.

const { frameGapPaddingMs, maxBufferedFramesPerSession } = require('./config');

class SessionGlossQueue {
    constructor(sessionId, connectionRegistry, logger) {
        this.sessionId = sessionId;
        this.connectionRegistry = connectionRegistry;
        this.logger = logger;
        this.frames = [];
        this.isProcessing = false;
        this.currentTimer = null;
    }

    /** Reemplaza la cola pendiente por una secuencia nueva y arranca a procesarla. */
    enqueueSequence(frames) {
        if (!Array.isArray(frames) || frames.length === 0) {
            return;
        }

        this.frames = frames.slice(0, maxBufferedFramesPerSession);
        if (frames.length > maxBufferedFramesPerSession) {
            this.logger.warn(
                `[${this.sessionId}] Secuencia de ${frames.length} frames truncada a ${maxBufferedFramesPerSession} (limite de buffer).`
            );
        }

        if (!this.isProcessing) {
            this._processNext();
        }
    }

    /** Corta la reproduccion en curso (ej. la sesion termino o el presentador la interrumpe). */
    clear() {
        this.frames = [];
        if (this.currentTimer) {
            clearTimeout(this.currentTimer);
            this.currentTimer = null;
        }
        this.isProcessing = false;
    }

    _processNext() {
        if (this.frames.length === 0) {
            this.isProcessing = false;
            return;
        }

        this.isProcessing = true;
        const frame = this.frames.shift();
        const socket = this.connectionRegistry.get(this.sessionId);

        if (!socket || socket.readyState !== socket.OPEN) {
            // Unreal Engine todavia no esta conectado (o se desconecto) para esta sesion:
            // devolvemos el frame al FRENTE de la cola y reintentamos en poco tiempo, en vez
            // de perderlo. unrealConnections.register() dispara el reintento apenas conecte,
            // pero este timer es la red de seguridad si la conexion tarda en aparecer.
            this.frames.unshift(frame);
            this.currentTimer = setTimeout(() => this._processNext(), 500);
            return;
        }

        socket.send(
            JSON.stringify({
                type: 'sign_frame',
                sessionId: this.sessionId,
                gloss: frame.gloss,
                animation: frame.animation,
                duration: frame.duration,
                isFingerspelling: !!frame.is_fingerspelling,
                remainingInSequence: this.frames.length,
            })
        );

        const waitMs = Math.max(0, Math.round(frame.duration * 1000)) + frameGapPaddingMs;
        this.currentTimer = setTimeout(() => this._processNext(), waitMs);
    }
}

class GlossQueueManager {
    constructor(connectionRegistry, logger) {
        this.connectionRegistry = connectionRegistry;
        this.logger = logger;
        /** @type {Map<string, SessionGlossQueue>} */
        this.queues = new Map();
    }

    _getOrCreate(sessionId) {
        let queue = this.queues.get(sessionId);
        if (!queue) {
            queue = new SessionGlossQueue(sessionId, this.connectionRegistry, this.logger);
            this.queues.set(sessionId, queue);
        }
        return queue;
    }

    /** Se llama cuando Unreal Engine se registra: reintenta drenar lo que haya quedado en cola. */
    onUnrealConnected(sessionId) {
        const queue = this.queues.get(sessionId);
        if (queue && queue.frames.length > 0 && !queue.isProcessing) {
            queue._processNext();
        }
    }

    enqueueSequence(sessionId, frames) {
        this._getOrCreate(sessionId).enqueueSequence(frames);
    }

    clear(sessionId) {
        const queue = this.queues.get(sessionId);
        if (queue) queue.clear();
    }
}

module.exports = { GlossQueueManager };
