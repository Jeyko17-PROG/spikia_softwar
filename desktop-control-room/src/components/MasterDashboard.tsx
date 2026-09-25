import { GeneralConfigPanel } from './panels/GeneralConfigPanel';
import { AudioInputPanel } from './panels/AudioInputPanel';
import { LiveStreamQrPanel } from './panels/LiveStreamQrPanel';
import { TranslationChannelsPanel } from './panels/TranslationChannelsPanel';
import { SubtitleOutputPanel } from './panels/SubtitleOutputPanel';
import { Avatar3DPanel } from './panels/Avatar3DPanel';
import { useControlRoomStore } from '../store/useControlRoomStore';

// Vista "Control Room" / Master Panel: una sola pantalla con todo lo que un operador de
// eventos necesita durante una sesion en vivo - a la izquierda, lo que ENTRA (fuente de
// audio, configuracion del motor); a la derecha, lo que SALE (idiomas, subtitulos, avatar).
// Ese orden espacial (entrada -> salida, izquierda -> derecha) es deliberado: coincide con
// como fluye la señal real, asi que el layout se "lee" solo sin necesitar instrucciones.
export function MasterDashboard() {
    const isLiveStreamEnabled = useControlRoomStore((state) => state.isLiveStreamEnabled);
    const activeChannelsCount = useControlRoomStore((state) => state.channels.filter((ch) => ch.isActive).length);
    const isAvatarEnabled = useControlRoomStore((state) => state.isAvatarEnabled);

    return (
        <div className="min-h-screen bg-black font-sans text-white">
            <div className="pointer-events-none fixed inset-0 bg-[radial-gradient(circle_at_20%_-10%,rgba(124,58,237,0.12),transparent_45%),radial-gradient(circle_at_80%_110%,rgba(79,252,255,0.08),transparent_45%)]" />

            <div className="relative mx-auto flex min-h-screen max-w-[1600px] flex-col">
                <header className="flex flex-wrap items-center justify-between gap-4 border-b border-white/5 px-6 py-5 lg:px-10">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-spikiaPurple to-neonPink shadow-glow">
                            <span className="text-lg font-black italic">S</span>
                        </div>
                        <div>
                            <h1 className="text-lg font-black italic leading-none tracking-tight">
                                Spikia <span className="not-italic text-zinc-400">Control Room</span>
                            </h1>
                            <p className="text-[10px] font-bold uppercase tracking-[0.3em] text-zinc-600">Master Panel de sesión</p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <StatusChip
                            active={isLiveStreamEnabled}
                            activeLabel="Transmitiendo"
                            idleLabel="Sin transmitir"
                            icon={
                                <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8.288 15.038a5.25 5.25 0 117.424 0M5.106 18.22a9 9 0 1113.788 0M12 12.75h.008v.008H12v-.008z" />
                                </svg>
                            }
                        />
                        <span className="rounded-full border border-white/10 bg-white/5 px-3.5 py-1.5 text-[10px] font-black uppercase tracking-widest text-zinc-300">
                            {activeChannelsCount} canal{activeChannelsCount !== 1 ? 'es' : ''} activo{activeChannelsCount !== 1 ? 's' : ''}
                        </span>
                        <StatusChip
                            active={isAvatarEnabled}
                            activeLabel="Avatar activo"
                            idleLabel="Avatar apagado"
                            icon={
                                <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                                </svg>
                            }
                        />
                    </div>
                </header>

                <main className="grid flex-1 grid-cols-1 gap-6 px-6 py-6 lg:grid-cols-[380px_1fr] lg:px-10 lg:py-8">
                    {/* Columna izquierda: Panel Maestro (control de entrada) */}
                    <div className="space-y-6">
                        <GeneralConfigPanel />
                        <AudioInputPanel />
                        <LiveStreamQrPanel />
                    </div>

                    {/* Columna derecha: canales, subtitulos y avatar */}
                    <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                        <div className="space-y-6 xl:col-span-2">
                            <TranslationChannelsPanel />
                        </div>
                        <SubtitleOutputPanel />
                        <Avatar3DPanel />
                    </div>
                </main>
            </div>
        </div>
    );
}

function StatusChip({
    active,
    activeLabel,
    idleLabel,
    icon,
}: {
    active: boolean;
    activeLabel: string;
    idleLabel: string;
    icon: React.ReactNode;
}) {
    return (
        <span
            className={`flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-[10px] font-black uppercase tracking-widest ${
                active ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-white/10 bg-white/5 text-zinc-500'
            }`}
        >
            {icon}
            {active ? activeLabel : idleLabel}
        </span>
    );
}
