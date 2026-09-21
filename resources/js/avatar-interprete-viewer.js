// Modo 'human_live', lado OYENTE — recibe el stream de LiveKit publicado por
// avatar-interprete-broadcaster.js (que corre en /sesiones/{slug}/interprete) y lo reproduce
// en #avatar-video-player. Se conecta a la sala de LiveKit nombrada como config.slug, igual
// que el broadcaster - misma sala, distinto rol (aca solo se suscribe, nunca publica).

import { Room, RoomEvent, Track } from 'livekit-client';

function init() {
    const config = window.__SPIKIA_LISTENER__;
    const video = document.getElementById('avatar-video-player');
    const statusEl = document.getElementById('avatar-live-status');

    if (!config || !config.slug || !video) {
        return;
    }

    function setStatus(text) {
        if (statusEl) {
            statusEl.textContent = text;
        }
    }

    async function connectToRoom() {
        if (!config.livekitTokenUrl) {
            setStatus('Modo intérprete en vivo: todavía no hay video en tiempo real conectado.');
            return;
        }

        let tokenData;
        try {
            const res = await fetch(config.livekitTokenUrl, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                setStatus('No se pudo conectar al video en vivo del intérprete.');
                return;
            }
            tokenData = await res.json();
        } catch (error) {
            setStatus('No se pudo conectar al video en vivo del intérprete.');
            return;
        }

        try {
            const room = new Room();

            room.on(RoomEvent.TrackSubscribed, (track) => {
                if (track.kind === Track.Kind.Video) {
                    track.attach(video);
                    setStatus('');
                } else if (track.kind === Track.Kind.Audio) {
                    track.attach(); // crea su propio <audio>, no hace falta el <video>
                }
            });

            room.on(RoomEvent.Disconnected, () => {
                setStatus('Se perdió la conexión con el intérprete en vivo.');
            });

            setStatus('Conectando con el intérprete en vivo...');
            await room.connect(tokenData.url, tokenData.token);
        } catch (error) {
            console.error('LiveKit subscribe error:', error);
            setStatus('No se pudo conectar al video en vivo del intérprete.');
        }
    }

    connectToRoom();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
