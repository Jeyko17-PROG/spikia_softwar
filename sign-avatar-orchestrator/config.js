// Configuracion centralizada, leida desde variables de entorno (.env en esta misma carpeta).
// Ver .env.example para la lista completa documentada.
require('dotenv').config();

module.exports = {
    httpPort: Number(process.env.HTTP_PORT || 4100),

    // Token que Laravel (ProcessSignGlossesJob) debe mandar en el header "X-Internal-Token"
    // al publicar una secuencia nueva. Si se deja vacio, no se exige (comodo en desarrollo).
    internalServiceToken: process.env.INTERNAL_SERVICE_TOKEN || '',

    // Token separado para el HANDSHAKE de Unreal Engine (el cliente C++ lo manda en el
    // mensaje de registro por WebSocket). Distinto del de Laravel porque son dos superficies
    // de confianza distintas: un server Laravel autenticado vs. un cliente nativo en la red.
    unrealAuthToken: process.env.UNREAL_AUTH_TOKEN || '',

    // Cuantos milisegundos de margen se le suman a la duracion de cada seña antes de mandar
    // la siguiente de la cola (evita que se pisen dos animaciones si el render en Unreal va
    // un poco mas lento que el reloj de este servidor).
    frameGapPaddingMs: Number(process.env.FRAME_GAP_PADDING_MS || 80),

    // Si una sesion manda una secuencia nueva mientras la instancia de Unreal Engine
    // correspondiente todavia no se conecto (arranca despues, o se cayo momentaneamente), la
    // guardamos en un buffer y se la mandamos apenas se conecte - hasta este limite de
    // frames en cola, para no acumular memoria indefinidamente si Unreal nunca aparece.
    maxBufferedFramesPerSession: Number(process.env.MAX_BUFFERED_FRAMES_PER_SESSION || 500),

    logLevel: process.env.LOG_LEVEL || 'info',
};
