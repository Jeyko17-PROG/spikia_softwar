// Registro de instancias de Unreal Engine conectadas, indexadas por sessionId (el slug de la
// sesion de Spikia). Cada instancia de UE5 (el plugin de C++, ver SpikiaSignWebSocketClient)
// se registra una vez al conectarse con un mensaje {"type":"register","sessionId":"..."}.
//
// Diseño 1 conexion activa POR sesion a proposito: un avatar (un viewport de Unreal
// renderizando) por sesion de Spikia es el caso de uso real. Si en el futuro hiciera falta
// que varias instancias reciban la misma secuencia (ej. redundancia), este es el unico
// archivo que habria que tocar (pasar de Map<sessionId, socket> a Map<sessionId, Set<socket>>).

class UnrealConnectionRegistry {
    constructor() {
        /** @type {Map<string, import('ws').WebSocket>} */
        this.connections = new Map();
    }

    register(sessionId, socket) {
        const existing = this.connections.get(sessionId);
        if (existing && existing !== socket && existing.readyState === existing.OPEN) {
            // Una instancia nueva de Unreal se conecto para la MISMA sesion (ej. el proceso
            // de UE5 se reinicio): cerramos la conexion vieja para no mandarle frames a un
            // socket zombie que ya nadie escucha del otro lado.
            try {
                existing.close(4000, 'Reemplazada por una nueva conexion de Unreal Engine.');
            } catch (error) {
                // noop - si ya estaba rota, no hay nada mas que hacer
            }
        }
        this.connections.set(sessionId, socket);
    }

    unregister(sessionId, socket) {
        if (this.connections.get(sessionId) === socket) {
            this.connections.delete(sessionId);
        }
    }

    get(sessionId) {
        return this.connections.get(sessionId) || null;
    }

    isConnected(sessionId) {
        const socket = this.connections.get(sessionId);
        return !!socket && socket.readyState === socket.OPEN;
    }
}

module.exports = { UnrealConnectionRegistry };
