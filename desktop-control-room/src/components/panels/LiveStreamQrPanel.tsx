import { QRCodeSVG } from 'qrcode.react';
import { useState } from 'react';
import { Card } from '../ui/Card';
import { Toggle } from '../ui/Toggle';
import { useControlRoomStore } from '../../store/useControlRoomStore';

export function LiveStreamQrPanel() {
    const isLiveStreamEnabled = useControlRoomStore((state) => state.isLiveStreamEnabled);
    const toggleLiveStream = useControlRoomStore((state) => state.toggleLiveStream);
    const sessionCode = useControlRoomStore((state) => state.sessionCode);
    const sessionUrl = useControlRoomStore((state) => state.sessionUrl);
    const listenerCount = useControlRoomStore((state) => state.listenerCount);
    const [copied, setCopied] = useState(false);

    async function handleCopyLink() {
        try {
            await navigator.clipboard.writeText(sessionUrl);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard puede fallar sin permisos/contexto seguro - no rompe la UI, el
            // enlace ya esta visible en pantalla para copiarlo a mano.
        }
    }

    return (
        <Card
            title="Transmisión en vivo"
            icon={
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8.288 15.038a5.25 5.25 0 117.424 0M5.106 18.22a9 9 0 1113.788 0M12 12.75h.008v.008H12v-.008z" />
                </svg>
            }
            action={
                <span
                    className={`flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[9px] font-black uppercase tracking-widest ${
                        isLiveStreamEnabled ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-white/10 bg-white/5 text-zinc-500'
                    }`}
                >
                    <span className={`h-1.5 w-1.5 rounded-full ${isLiveStreamEnabled ? 'animate-pulse bg-emerald-400' : 'bg-zinc-600'}`} />
                    {isLiveStreamEnabled ? 'En vivo' : 'Detenido'}
                </span>
            }
        >
            <div className="space-y-5">
                <Toggle
                    checked={isLiveStreamEnabled}
                    onChange={toggleLiveStream}
                    label="Transmitir a oyentes"
                    description="Habilita el enlace y el código QR para que el público se conecte desde su celular."
                />

                {isLiveStreamEnabled && (
                    <div className="animate-in fade-in slide-in-from-top-2 duration-300 space-y-4">
                        <div className="flex items-center gap-4 rounded-2xl border border-white/10 bg-black/30 p-4">
                            <div className="shrink-0 rounded-xl bg-white p-2">
                                <QRCodeSVG value={sessionUrl} size={96} />
                            </div>
                            <div className="min-w-0 space-y-2">
                                <div>
                                    <p className="text-[9px] font-black uppercase tracking-[0.25em] text-zinc-500">Código de sesión</p>
                                    <p className="font-mono text-base font-black tracking-widest text-neonBlue">{sessionCode}</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={handleCopyLink}
                                    className="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-[9px] font-black uppercase tracking-widest text-zinc-300 transition hover:border-neonBlue/40 hover:text-neonBlue"
                                >
                                    {copied ? 'Copiado ✓' : 'Copiar enlace'}
                                </button>
                            </div>
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-white/5 bg-white/[0.02] px-4 py-3">
                            <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-400">Oyentes conectados</span>
                            <span className="text-lg font-black text-white">{listenerCount}</span>
                        </div>
                    </div>
                )}
            </div>
        </Card>
    );
}
