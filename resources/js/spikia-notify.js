// Notificaciones y confirmaciones propias de Spikia - reemplazan alert()/confirm() nativos
// del navegador (que muestran la URL cruda del sitio en el titulo y no se pueden estilizar).
// Modulo compartido: se importa tanto desde app.js (paginas normales) como desde master.js
// (las paginas de sesion en vivo no cargan app.js, ver el @vite condicional del layout).

// Toast NO bloqueante, se autodestruye solo. Uso: spikiaAlert('Enlace copiado al portapapeles')
export function spikiaAlert(message, options = {}) {
    const duration = options.duration || 2600;
    const tone = options.tone || 'success'; // success | error

    const toast = document.createElement('div');
    toast.className = 'fixed bottom-6 left-1/2 z-[9999] flex max-w-[90vw] items-center gap-3 rounded-2xl border bg-zinc-900 px-5 py-3.5 shadow-2xl shadow-black/50 opacity-0 transition-all duration-300 ease-out '
        + (tone === 'error' ? 'border-red-500/30' : 'border-emerald-500/30');
    toast.style.transform = 'translate(-50%, 8px)';

    const iconWrap = tone === 'error'
        ? '<div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-500/15 text-red-400"><svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg></div>'
        : '<div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-400"><svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></div>';

    toast.innerHTML = `${iconWrap}<p class="text-[11px] font-bold leading-snug text-zinc-100"></p>`;
    toast.querySelector('p').textContent = message;
    document.body.appendChild(toast);

    requestAnimationFrame(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translate(-50%, 0)';
    });

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translate(-50%, 8px)';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// Modal de confirmacion (reemplaza confirm() nativo). Uso:
//   onsubmit="return spikiaConfirmSubmit(event, 'Mensaje...')"
export function spikiaConfirm(message, options = {}) {
    const confirmLabel = options.confirmLabel || 'Eliminar';
    const cancelLabel = options.cancelLabel || 'Cancelar';

    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-black/70 backdrop-blur-sm px-4';
        overlay.innerHTML = `
            <div class="w-full max-w-sm rounded-[1.75rem] border border-white/10 bg-zinc-900 shadow-2xl shadow-black/60 p-6 space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-500/10 border border-red-500/30 text-red-400">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                        </svg>
                    </div>
                    <p class="text-sm font-medium text-zinc-100 leading-snug pt-2">${message}</p>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" data-action="cancel" class="px-4 py-2.5 rounded-xl border border-white/10 bg-white/5 text-zinc-300 text-[10px] font-black uppercase tracking-[0.2em] hover:border-white/25 hover:text-white transition">${cancelLabel}</button>
                    <button type="button" data-action="confirm" class="px-4 py-2.5 rounded-xl bg-red-600 text-white text-[10px] font-black uppercase tracking-[0.2em] hover:bg-red-500 transition">${confirmLabel}</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        const close = (result) => {
            document.removeEventListener('keydown', onKeydown);
            overlay.remove();
            resolve(result);
        };
        const onKeydown = (event) => {
            if (event.key === 'Escape') close(false);
        };

        document.addEventListener('keydown', onKeydown);
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close(false);
        });
        overlay.querySelector('[data-action="cancel"]').addEventListener('click', () => close(false));
        overlay.querySelector('[data-action="confirm"]').addEventListener('click', () => close(true));
    });
}

// Intercepta el submit de un <form>, muestra el modal y si se confirma envia el form
// "de verdad" (form.submit() no vuelve a disparar el evento submit, asi que no hay loop).
export function spikiaConfirmSubmit(event, message, options = {}) {
    event.preventDefault();
    const form = event.target;

    spikiaConfirm(message, options).then((confirmed) => {
        if (confirmed) {
            HTMLFormElement.prototype.submit.call(form);
        }
    });

    return false;
}
