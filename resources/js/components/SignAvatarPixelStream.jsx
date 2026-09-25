// Version en React del cliente de Pixel Streaming (ver sign-avatar-pixel-stream.js para la
// version vanilla JS, que es la que hoy se integra directo en Spikia - este proyecto no
// tiene React instalado, ver la nota de arquitectura en ese archivo). Este componente se
// entrega para el caso de consumirse desde otro frontend en React, o si en el futuro se migra
// parte de Spikia a React.
//
// Requiere instalar (no vienen incluidas en este proyecto):
//   npm install react react-dom @epicgames-ps/lib-pixelstreamingfrontend-ue5.5
//
// Uso:
//   <SignAvatarPixelStream signallingUrl="wss://mi-servidor-unreal.spikia.com:8888" />

import { useEffect, useRef, useState } from 'react';
import { Config, PixelStreaming } from '@epicgames-ps/lib-pixelstreamingfrontend-ue5.5';

/**
 * @param {Object} props
 * @param {string} props.signallingUrl - WebSocket del SignallingWebServer de Pixel Streaming
 *   de Unreal Engine (distinto del orquestador Node.js de glosas - ver sign-avatar-orchestrator).
 * @param {string} [props.className] - Clases CSS adicionales para el contenedor.
 */
export default function SignAvatarPixelStream({ signallingUrl, className = '' }) {
    const containerRef = useRef(null);
    const streamRef = useRef(null);
    const [connectionState, setConnectionState] = useState('idle'); // idle | connecting | connected | disconnected

    useEffect(() => {
        if (!signallingUrl || !containerRef.current) {
            return undefined;
        }

        setConnectionState('connecting');

        const pixelStreamingConfig = new Config({
            useUrlParams: false,
            initialSettings: {
                ss: signallingUrl,
                AutoPlayVideo: true,
                AutoConnect: true,
                StartVideoMuted: false,
            },
        });

        const pixelStreaming = new PixelStreaming(pixelStreamingConfig, {
            videoElementParent: containerRef.current,
        });
        streamRef.current = pixelStreaming;

        const onConnecting = () => setConnectionState('connecting');
        const onConnected = () => setConnectionState('connected');
        const onDisconnected = () => setConnectionState('disconnected');

        pixelStreaming.addEventListener('webRtcConnecting', onConnecting);
        pixelStreaming.addEventListener('webRtcConnected', onConnected);
        pixelStreaming.addEventListener('webRtcDisconnected', onDisconnected);
        pixelStreaming.addEventListener('videoInitialized', onConnected);

        let reconnectTimer = null;
        const onDisconnectedWithRetry = () => {
            if (reconnectTimer) return;
            reconnectTimer = setTimeout(() => {
                reconnectTimer = null;
                try {
                    pixelStreaming.reconnect();
                } catch (error) {
                    console.warn('No se pudo reconectar al avatar de lengua de señas:', error);
                }
            }, 3000);
        };
        pixelStreaming.addEventListener('webRtcDisconnected', onDisconnectedWithRetry);

        // Cleanup: se ejecuta si signallingUrl cambia o si el componente se desmonta - evita
        // dejar conexiones WebRTC huerfanas abiertas (consumen CPU/red aunque el usuario ya
        // haya navegado a otra pantalla).
        return () => {
            if (reconnectTimer) clearTimeout(reconnectTimer);
            pixelStreaming.removeEventListener('webRtcConnecting', onConnecting);
            pixelStreaming.removeEventListener('webRtcConnected', onConnected);
            pixelStreaming.removeEventListener('webRtcDisconnected', onDisconnected);
            pixelStreaming.removeEventListener('webRtcDisconnected', onDisconnectedWithRetry);
            pixelStreaming.removeEventListener('videoInitialized', onConnected);
            try {
                pixelStreaming.disconnect();
            } catch (error) {
                // noop - si ya estaba desconectado no hay nada que limpiar
            }
            streamRef.current = null;
        };
    }, [signallingUrl]);

    return (
        <div className={`relative overflow-hidden rounded-[2rem] border border-white/10 bg-zinc-950 ${className}`}>
            <div ref={containerRef} className="aspect-video w-full [&_video]:h-full [&_video]:w-full [&_video]:object-cover" />

            {connectionState !== 'connected' && (
                <div className="absolute inset-0 flex items-center justify-center bg-black/70 backdrop-blur-sm">
                    <div className="flex flex-col items-center gap-3 text-center px-6">
                        <span className="h-8 w-8 animate-spin rounded-full border-2 border-cyan-400 border-t-transparent" />
                        <p className="text-[10px] font-black uppercase tracking-[0.3em] text-zinc-300">
                            {connectionState === 'disconnected'
                                ? 'Se perdió la conexión con el avatar. Reintentando...'
                                : 'Conectando con el avatar...'}
                        </p>
                    </div>
                </div>
            )}
        </div>
    );
}
