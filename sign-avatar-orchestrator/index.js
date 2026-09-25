// Servidor orquestador de tiempo real: puente entre Spikia (Laravel) y Unreal Engine 5.
//
// - HTTP  (Express)   : Laravel (ProcessSignGlossesJob) publica aca la secuencia de glosas ya
//                       traducida por el microservicio Python (sign-nlp-service).
// - WebSocket (ws)    : el plugin de C++ dentro de Unreal Engine 5 mantiene UNA conexion
//                       persistente aca por sesion, y recibe las señas en cola, una por una,
//                       con el pacing que decide glossQueue.js.
//
// Arrancar:
//   npm install
//   npm start
//
// Variables de entorno: ver .env.example.

const http = require('http');
const express = require('express');
const { WebSocketServer } = require('ws');

const config = require('./config');
const { UnrealConnectionRegistry } = require('./unrealConnections');
const { GlossQueueManager } = require('./glossQueue');

const logger = {
    info: (...args) => console.log('[info]', ...args),
    warn: (...args) => console.warn('[warn]', ...args),
    error: (...args) => console.error('[error]', ...args),
};

const connectionRegistry = new UnrealConnectionRegistry();
const queueManager = new GlossQueueManager(connectionRegistry, logger);

// ---------------------------------------------------------------------------
// HTTP: recibe secuencias de glosas desde Laravel
// ---------------------------------------------------------------------------
const app = express();
app.use(express.json({ limit: '256kb' }));

function verifyInternalToken(req, res, next) {
    if (!config.internalServiceToken) {
        // Sin token configurado (desarrollo local): no se exige nada.
        return next();
    }
    const provided = req.header('X-Internal-Token') || '';
    if (provided !== config.internalServiceToken) {
        return res.status(401).json({ success: false, message: 'Token interno invalido.' });
    }
    return next();
}

app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        unrealConnections: connectionRegistry.connections.size,
    });
});

// Body esperado (coincide 1:1 con TranslateResponse del microservicio Python):
// { "session_id": "demo-es-en-1", "sequence": [{ "gloss": "HOLA", "animation": "Sign_Hola",
//   "duration": 0.8, "is_fingerspelling": false }, ...], "total_duration": 4.2 }
app.post('/api/sessions/:sessionId/sign-sequence', verifyInternalToken, (req, res) => {
    const { sessionId } = req.params;
    const sequence = req.body?.sequence;

    if (!Array.isArray(sequence) || sequence.length === 0) {
        return res.status(400).json({ success: false, message: 'El body debe incluir "sequence" (array no vacio).' });
    }

    queueManager.enqueueSequence(sessionId, sequence);
    logger.info(`[${sessionId}] Secuencia encolada: ${sequence.length} señas.`);

    return res.json({
        success: true,
        queued: sequence.length,
        unrealConnected: connectionRegistry.isConnected(sessionId),
    });
});

// Permite cortar en seco la reproduccion en curso (ej. el presentador cambia de tema, o la
// sesion termino) sin esperar a que la cola actual se vacie sola.
app.post('/api/sessions/:sessionId/sign-sequence/clear', verifyInternalToken, (req, res) => {
    queueManager.clear(req.params.sessionId);
    return res.json({ success: true });
});

const server = http.createServer(app);

// ---------------------------------------------------------------------------
// WebSocket: conexion persistente con la(s) instancia(s) de Unreal Engine 5
// ---------------------------------------------------------------------------
const wss = new WebSocketServer({ server, path: '/unreal' });

wss.on('connection', (socket) => {
    let boundSessionId = null;
    socket.isAlive = true;

    socket.on('pong', () => {
        socket.isAlive = true;
    });

    socket.on('message', (raw) => {
        let message;
        try {
            message = JSON.parse(raw.toString());
        } catch (error) {
            logger.warn('Mensaje no-JSON recibido de un cliente de Unreal Engine, se ignora.');
            return;
        }

        // Primer mensaje esperado del plugin de C++ (ver SpikiaSignWebSocketClient::Connect):
        // { "type": "register", "sessionId": "demo-es-en-1", "token": "..." }
        if (message.type === 'register') {
            if (config.unrealAuthToken && message.token !== config.unrealAuthToken) {
                logger.warn('Intento de registro de Unreal Engine con token invalido.');
                socket.close(4001, 'Token invalido.');
                return;
            }
            if (!message.sessionId) {
                socket.close(4002, 'Falta sessionId en el registro.');
                return;
            }

            boundSessionId = String(message.sessionId);
            connectionRegistry.register(boundSessionId, socket);
            queueManager.onUnrealConnected(boundSessionId);
            logger.info(`[${boundSessionId}] Instancia de Unreal Engine conectada y registrada.`);
            socket.send(JSON.stringify({ type: 'registered', sessionId: boundSessionId }));
            return;
        }

        // Confirmacion opcional de que Unreal ya termino de reproducir una seña - hoy el
        // pacing lo decide solo el orquestador (por duracion estimada, ver glossQueue.js),
        // pero dejamos el hook aca para el dia que se quiera sincronizar por ACK real en vez
        // de por tiempo estimado (mas preciso, requiere que el AnimInstance lo reporte).
        if (message.type === 'frame_ack') {
            logger.info(`[${boundSessionId}] ACK de frame recibido de Unreal Engine: ${message.gloss}`);
            return;
        }
    });

    socket.on('close', () => {
        if (boundSessionId) {
            connectionRegistry.unregister(boundSessionId, socket);
            logger.info(`[${boundSessionId}] Instancia de Unreal Engine desconectada.`);
        }
    });

    socket.on('error', (error) => {
        logger.error('Error en conexion WebSocket de Unreal Engine:', error.message);
    });
});

// Ping/pong cada 30s: detecta conexiones "zombie" (el proceso de Unreal se colgo sin cerrar
// el socket TCP prolijamente) y las cierra, para que connectionRegistry.isConnected() no
// mienta diciendo que hay un avatar activo cuando en realidad nadie va a recibir nada.
const heartbeatInterval = setInterval(() => {
    wss.clients.forEach((socket) => {
        if (socket.isAlive === false) {
            return socket.terminate();
        }
        socket.isAlive = false;
        socket.ping();
    });
}, 30000);

wss.on('close', () => clearInterval(heartbeatInterval));

server.listen(config.httpPort, () => {
    logger.info(`Spikia Sign Avatar Orchestrator escuchando en puerto ${config.httpPort}`);
    logger.info(`  HTTP:      POST http://localhost:${config.httpPort}/api/sessions/:sessionId/sign-sequence`);
    logger.info(`  WebSocket: ws://localhost:${config.httpPort}/unreal`);
});
