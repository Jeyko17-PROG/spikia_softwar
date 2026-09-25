import { Card } from '../ui/Card';
import { Toggle } from '../ui/Toggle';
import { useControlRoomStore } from '../../store/useControlRoomStore';
import type { AvatarConnectionStatus } from '../../types';

const STATUS_STYLES: Record<AvatarConnectionStatus, string> = {
    desconectado: 'border-white/10 bg-white/5 text-zinc-500',
    conectando: 'border-amber-500/30 bg-amber-500/10 text-amber-300',
    conectado: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
};

const STATUS_DOT: Record<AvatarConnectionStatus, string> = {
    desconectado: 'bg-zinc-600',
    conectando: 'animate-pulse bg-amber-400',
    conectado: 'animate-pulse bg-emerald-400',
};

// Modulo integrado del avatar de Lengua de Señas (ver arquitectura Unreal Engine 5 +
// Pixel Streaming ya definida para Spikia: sign-nlp-service, sign-avatar-orchestrator,
// unreal/SpikiaSignAvatar). En produccion, este <div> de preview es donde se monta el
// <video> del stream WebRTC (ver SignAvatarPixelStream.jsx) - aca se deja un placeholder
// visual equivalente para que el layout del Control Room sea demostrable sin depender de
// tener Unreal Engine corriendo.
export function Avatar3DPanel() {
    const isEnabled = useControlRoomStore((state) => state.isAvatarEnabled);
    const toggleEnabled = useControlRoomStore((state) => state.toggleAvatarEnabled);
    const connectionStatus = useControlRoomStore((state) => state.avatarConnectionStatus);
    const currentGloss = useControlRoomStore((state) => state.currentGloss);

    return (
        <Card
            title="Avatar 3D · Lengua de señas"
            icon={
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                </svg>
            }
            action={
                <span className={`flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[9px] font-black uppercase tracking-widest ${STATUS_STYLES[connectionStatus]}`}>
                    <span className={`h-1.5 w-1.5 rounded-full ${STATUS_DOT[connectionStatus]}`} />
                    {connectionStatus}
                </span>
            }
        >
            <div className="space-y-4">
                <Toggle
                    checked={isEnabled}
                    onChange={toggleEnabled}
                    label="Avatar activo"
                    description="Acompaña las salidas de subtítulos con interpretación a Lengua de Señas en tiempo real."
                />

                <div className="relative aspect-square overflow-hidden rounded-2xl border border-white/10 bg-[radial-gradient(circle_at_50%_20%,#1e1b4b,#000)]">
                    {isEnabled ? (
                        <div className="flex h-full flex-col items-center justify-center gap-3 px-4 text-center">
                            <div className="flex h-16 w-16 items-center justify-center rounded-full border border-spikiaPurple/40 bg-spikiaPurple/10">
                                <svg className="h-8 w-8 text-spikiaPurple" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0" />
                                </svg>
                            </div>
                            <p className="text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-400">
                                {connectionStatus === 'conectado' ? 'Stream del avatar en vivo' : 'Esperando conexión con Unreal Engine...'}
                            </p>
                            {currentGloss && (
                                <span className="rounded-full border border-neonBlue/30 bg-neonBlue/10 px-3 py-1 font-mono text-[11px] font-black text-neonBlue">
                                    {currentGloss}
                                </span>
                            )}
                        </div>
                    ) : (
                        <div className="flex h-full items-center justify-center">
                            <p className="text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-600">Avatar desactivado</p>
                        </div>
                    )}
                </div>
            </div>
        </Card>
    );
}
