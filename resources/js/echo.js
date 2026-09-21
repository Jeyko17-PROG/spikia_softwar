import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Cliente Echo/Pusher compartido, con inicializacion perezosa: si no hay
// VITE_PUSHER_APP_KEY (entorno sin broadcasting configurado), getEcho() devuelve
// null y el llamador debe seguir funcionando solo con polling.
let echoInstance = null;
let attempted = false;

export function getEcho() {
    if (attempted) {
        return echoInstance;
    }

    attempted = true;

    const key = import.meta.env.VITE_PUSHER_APP_KEY;
    if (!key) {
        return null;
    }

    window.Pusher = Pusher;

    const cluster = import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1';
    const scheme = import.meta.env.VITE_PUSHER_SCHEME || 'https';
    const host = import.meta.env.VITE_PUSHER_HOST || '';
    const port = Number(import.meta.env.VITE_PUSHER_PORT || 443);

    try {
        echoInstance = new Echo({
            broadcaster: 'pusher',
            key,
            cluster,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            ...(host ? { wsHost: host, wsPort: port, wssPort: port } : {}),
        });
    } catch (error) {
        console.warn('No se pudo inicializar Echo/Pusher:', error);
        echoInstance = null;
    }

    return echoInstance;
}
