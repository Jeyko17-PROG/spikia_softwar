// Cliente de Pixel Streaming para el avatar MetaHuman de Lengua de Señas (Unreal Engine 5).
//
// NOTA DE ARQUITECTURA: este proyecto (Spikia) es 100% Vite + JS vanilla + Blade - no tiene
// React instalado en ningun lado (ver resources/js/avatar-interprete-viewer.js, que resuelve
// el mismo tipo de problema -mostrar un stream de video en vivo- sin React, con LiveKit).
// Por eso esta es la implementacion PRINCIPAL, pensada para integrarse hoy mismo sin agregar
// un toolchain nuevo solo para un componente. Igual se entrega ademas un wrapper de React
// (./components/SignAvatarPixelStream.jsx) para el caso de que este modulo se consuma desde
// otro frontend que si use React.
//
// Usa la libreria OFICIAL de Epic Games para el protocolo de señalizacion/WebRTC de Pixel
// Streaming (@epicgames-ps/lib-pixelstreamingfrontend) en vez de reimplementar a mano el
// intercambio de SDP/ICE candidates: ese protocolo es especifico de cada version de Unreal
// Engine y Epic lo mantiene activamente - reimplementarlo a mano seria fragil y quedaria
// desactualizado en cada upgrade de motor.
//
// Instalar (una sola vez, ajustar el sufijo -ueX.Y a la version de Unreal Engine del
// proyecto - ver el plugin "Pixel Streaming" dentro del propio Unreal Engine para confirmar
// cual le corresponde):
//   npm install @epicgames-ps/lib-pixelstreamingfrontend-ue5.5
//
// Config esperada en window.__SPIKIA_SIGN_AVATAR__ (inyectada desde el Blade de la sesion,
// mismo patron que window.__SPIKIA_LISTENER__ en las demas vistas):
//   {
//     signallingUrl: "wss://mi-servidor-unreal.spikia.com:8888", // WebSocket del
//                                                                 // SignallingWebServer de
//                                                                 // Pixel Streaming (NO es
//                                                                 // el orquestador Node.js -
//                                                                 // ese es otro servidor,
//                                                                 // ver sign-avatar-orchestrator)
//   }

import { Config, PixelStreaming } from '@epicgames-ps/lib-pixelstreamingfrontend-ue5.5';

function init() {
    const config = window.__SPIKIA_SIGN_AVATAR__;
    const container = document.getElementById('sign-avatar-stream-container');
    const statusEl = document.getElementById('sign-avatar-stream-status');

    if (!config || !config.signallingUrl || !container) {
        return;
    }

    function setStatus(text) {
        if (statusEl) {
            statusEl.textContent = text;
        }
    }

    setStatus('Conectando con el avatar...');

    // Config del SDK de Epic: ss=signalling server. useUrlParams=false porque nuestra URL
    // sale de window.__SPIKIA_SIGN_AVATAR__ (inyectada por Laravel), no de la query string.
    const pixelStreamingConfig = new Config({
        useUrlParams: false,
        initialSettings: {
            ss: config.signallingUrl,
            // AutoPlayVideo/AutoConnect: el avatar debe arrancar solo, sin que el oyente
            // tenga que tocar un boton de "play" - coherente con como se comporta el resto
            // de Spikia (audio/video en vivo automatico apenas se entra a la sesion).
            AutoPlayVideo: true,
            AutoConnect: true,
            // StartVideoMuted queda en false: el "audio" real de la sesion lo sigue
            // manejando el pipeline normal de Spikia (voz sintetizada via ElevenLabs) - el
            // stream de Unreal es SOLO video del avatar. Si el proyecto de Unreal tambien
            // manda audio por el mismo stream, se puede mutear aca para no duplicar voces.
            StartVideoMuted: false,
        },
    });

    const pixelStreaming = new PixelStreaming(pixelStreamingConfig, {
        videoElementParent: container,
    });

    pixelStreaming.addEventListener('webRtcConnecting', () => setStatus('Conectando con el avatar...'));
    pixelStreaming.addEventListener('webRtcConnected', () => setStatus(''));
    pixelStreaming.addEventListener('webRtcDisconnected', () => setStatus('Se perdió la conexión con el avatar. Reintentando...'));
    pixelStreaming.addEventListener('videoInitialized', () => setStatus(''));

    // Reintento simple: si la señalizacion se cae (reinicio del servidor de Unreal, corte de
    // red), reconectamos en vez de dejar al oyente con un cuadro congelado para siempre.
    let reconnectTimer = null;
    pixelStreaming.addEventListener('webRtcDisconnected', () => {
        if (reconnectTimer) return;
        reconnectTimer = setTimeout(() => {
            reconnectTimer = null;
            try {
                pixelStreaming.reconnect();
            } catch (error) {
                console.warn('No se pudo reconectar al avatar de lengua de señas:', error);
            }
        }, 3000);
    });

    // Se expone en window para poder desconectar prolijamente si la sesion termina (ej. el
    // listener navega a otra pagina sin recargar - SPA-like) o para debugging manual.
    window.__spikiaSignAvatarStream = pixelStreaming;
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
