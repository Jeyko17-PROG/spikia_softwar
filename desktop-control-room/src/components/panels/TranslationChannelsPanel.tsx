import { Card } from '../ui/Card';
import { Toggle } from '../ui/Toggle';
import { useControlRoomStore } from '../../store/useControlRoomStore';

// Abre el selector de carpeta NATIVO del sistema operativo. window.spikiaNative es el puente
// que expone el preload script de Electron (contextBridge.exposeInMainWorld) o el comando
// invoke() de Tauri - se declara como opcional porque este componente tambien debe poder
// renderizarse en un navegador normal (ej. mientras se desarrolla con "npm run dev" sin
// empaquetar la app) sin romper.
declare global {
    interface Window {
        spikiaNative?: {
            pickFolder: () => Promise<string | null>;
        };
    }
}

export function TranslationChannelsPanel() {
    const channels = useControlRoomStore((state) => state.channels);
    const toggleChannelActive = useControlRoomStore((state) => state.toggleChannelActive);
    const toggleChannelTranscription = useControlRoomStore((state) => state.toggleChannelTranscription);
    const toggleChannelRecording = useControlRoomStore((state) => state.toggleChannelRecording);
    const setChannelSaveFolder = useControlRoomStore((state) => state.setChannelSaveFolder);

    async function handleBrowseFolder(channelId: string) {
        if (!window.spikiaNative) {
            // Fallback en navegador (sin shell nativo): no hay File System Access API
            // confiable multiplataforma para elegir una carpeta de escritura persistente,
            // asi que se deja la ruta como texto editable a mano en ese contexto.
            return;
        }
        const folder = await window.spikiaNative.pickFolder();
        if (folder) setChannelSaveFolder(channelId, folder);
    }

    const activeCount = channels.filter((ch) => ch.isActive).length;

    return (
        <Card
            title="Canales de traducción"
            icon={
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.5 21l5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 016-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25V3m3.334 2.364C11.176 10.658 7.69 15.08 3 17.502m9.334-12.138c.896.061 1.785.147 2.666.257m-4.589 8.495a18.023 18.023 0 01-3.827-5.802" />
                </svg>
            }
            action={
                <span className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[9px] font-black uppercase tracking-widest text-zinc-400">
                    {activeCount} activo{activeCount !== 1 ? 's' : ''}
                </span>
            }
        >
            <div className="space-y-3">
                {channels.map((channel) => (
                    <div
                        key={channel.id}
                        className={`rounded-2xl border p-4 transition-colors ${
                            channel.isActive ? 'border-spikiaPurple/30 bg-spikiaPurple/[0.06]' : 'border-white/5 bg-black/20'
                        }`}
                    >
                        <div className="flex items-center justify-between gap-3">
                            <div className="flex items-center gap-3 min-w-0">
                                <button
                                    type="button"
                                    onClick={() => toggleChannelActive(channel.id)}
                                    className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border text-[10px] font-black uppercase transition-all ${
                                        channel.isActive
                                            ? 'border-spikiaPurple bg-spikiaPurple text-white'
                                            : 'border-white/10 bg-black/40 text-zinc-500 hover:border-white/25'
                                    }`}
                                    title={channel.isActive ? 'Desactivar canal' : 'Activar canal'}
                                >
                                    {channel.languageCode.split('-')[0].toUpperCase()}
                                </button>
                                <div className="min-w-0">
                                    <p className="truncate text-[12px] font-bold text-white">{channel.languageLabel}</p>
                                    <p className="text-[9px] uppercase tracking-widest text-zinc-500">{channel.languageCode}</p>
                                </div>
                            </div>
                        </div>

                        {channel.isActive && (
                            <div className="mt-4 space-y-3 border-t border-white/5 pt-3.5 animate-in fade-in duration-200">
                                <div className="grid grid-cols-2 gap-3">
                                    <Toggle
                                        checked={channel.liveTranscription}
                                        onChange={() => toggleChannelTranscription(channel.id)}
                                        label="Transcripción en vivo"
                                    />
                                    <Toggle checked={channel.recording} onChange={() => toggleChannelRecording(channel.id)} label="Grabar audio" />
                                </div>

                                <div>
                                    <span className="mb-1.5 block text-[9px] font-bold uppercase tracking-[0.2em] text-zinc-500">Carpeta de guardado</span>
                                    <div className="flex gap-2">
                                        <input
                                            type="text"
                                            value={channel.saveFolder}
                                            onChange={(event) => setChannelSaveFolder(channel.id, event.target.value)}
                                            className="min-w-0 flex-1 rounded-lg border border-white/10 bg-black/40 px-3 py-2 font-mono text-[11px] text-zinc-300 outline-none focus:border-neonBlue/60"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => handleBrowseFolder(channel.id)}
                                            className="shrink-0 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-[9px] font-black uppercase tracking-widest text-zinc-300 transition hover:border-white/25 hover:text-white"
                                        >
                                            Examinar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </Card>
    );
}
