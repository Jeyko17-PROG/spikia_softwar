// Avatar en modo 'video' — reproduce un clip pregrabado o generado por IA en vez de una
// escena 3D. Se carga SOLO cuando $sesion->avatar_mode === 'video' (ver avatar.blade.php /
// transmision.blade.php); si el modo es '3d' o 'human_live' este archivo ni se descarga.
//
// No interpreta glosas de verdad: el clip configurado (avatar_video_url) se reproduce en
// loop, y solo se resalta visualmente (clase "is-signing") mientras hay una glosa activa,
// igual que el motor 3D acelera su animacion. El punto de extension es reemplazar el clip
// unico por una cola de clips por glosa (uno por seña) cuando existan.

import { getEcho } from './echo';

function init() {
    const config = window.__SPIKIA_LISTENER__;
    const video = document.getElementById('avatar-video-player');
    const captionEl = document.getElementById('avatar-caption');

    if (!config || !config.slug || !video) {
        return;
    }

    const videoUrl = config.avatarVideoUrl || '';
    if (!videoUrl) {
        console.warn('avatar-video-player: la sala esta en modo "video" pero no tiene avatar_video_url configurado.');
        return;
    }

    video.src = videoUrl;
    video.loop = true;
    video.muted = true;
    video.playsInline = true;
    video.play().catch((error) => {
        // Los navegadores bloquean autoplay con audio; como ya forzamos muted=true esto no
        // deberia disparar seguido, pero si pasa preferimos loguear en vez de romper la vista.
        console.warn('avatar-video-player: no se pudo iniciar la reproduccion automatica:', error);
    });

    const glossQueue = [];
    let currentGloss = '';

    function updateCaption() {
        if (!captionEl) {
            return;
        }
        captionEl.textContent = currentGloss;
    }

    function setSigning(active) {
        video.classList.toggle('is-signing', active);
    }

    function playNextGloss() {
        if (glossQueue.length === 0) {
            currentGloss = '';
            setSigning(false);
            updateCaption();
            return;
        }

        currentGloss = glossQueue.shift();
        setSigning(true);
        updateCaption();
        window.setTimeout(playNextGloss, 700);
    }

    function handleSignBroadcast(payload) {
        const glosses = Array.isArray(payload?.glosses) ? payload.glosses : [];
        if (glosses.length === 0) {
            return;
        }

        const wasEmpty = glossQueue.length === 0 && currentGloss === '';
        glossQueue.push(...glosses);

        if (wasEmpty) {
            playNextGloss();
        }
    }

    function subscribeEcho() {
        const echo = getEcho();
        if (!echo) {
            return;
        }

        try {
            echo.channel(`transmision.${config.slug}`)
                .listen('.SignLanguageBroadcast', (payload) => handleSignBroadcast(payload));
        } catch (error) {
            console.warn('avatar-video-player: no se pudo suscribir a Echo:', error);
        }
    }

    updateCaption();
    subscribeEcho();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
