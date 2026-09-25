import { Card } from '../ui/Card';
import { SegmentedControl } from '../ui/SegmentedControl';
import { Slider } from '../ui/Slider';
import { Select } from '../ui/Select';
import { useControlRoomStore } from '../../store/useControlRoomStore';
import type { SubtitleBackground, SubtitleLineCount, SubtitleOutlineStyle, SubtitleTextColor } from '../../types';

const BACKGROUND_OPTIONS: { value: SubtitleBackground; label: string }[] = [
    { value: 'transparente', label: 'Transparente' },
    { value: 'negro-solido', label: 'Negro sólido' },
    { value: 'blanco-solido', label: 'Blanco sólido' },
    { value: 'degradado', label: 'Degradado' },
];

const TEXT_COLOR_OPTIONS: { value: SubtitleTextColor; label: string }[] = [
    { value: 'blanco', label: 'Blanco' },
    { value: 'amarillo', label: 'Amarillo' },
    { value: 'cian', label: 'Cian' },
];

const OUTLINE_OPTIONS: { value: SubtitleOutlineStyle; label: string }[] = [
    { value: 'contorno', label: 'Contorno' },
    { value: 'sombra', label: 'Sombra' },
    { value: 'ninguno', label: 'Ninguno' },
];

const LINE_COUNT_OPTIONS: { value: string; label: string }[] = [
    { value: '1', label: '1 línea' },
    { value: '2', label: '2 líneas' },
    { value: '3', label: '3 líneas' },
];

// Mapeos de nuestros valores "de negocio" (en español, legibles en el store) a clases/estilos
// CSS reales - se mantienen separados del store a proposito: el store no deberia saber nada
// de Tailwind ni de valores de CSS, solo de la intencion ("negro-solido"), para que mas
// adelante se pueda reemplazar la capa visual sin tocar el estado.
const BACKGROUND_CLASSES: Record<SubtitleBackground, string> = {
    transparente: 'bg-transparent',
    'negro-solido': 'bg-black/85',
    'blanco-solido': 'bg-white',
    degradado: 'bg-gradient-to-t from-black/90 via-black/50 to-transparent',
};

const TEXT_COLOR_CLASSES: Record<SubtitleTextColor, string> = {
    blanco: 'text-white',
    amarillo: 'text-yellow-300',
    cian: 'text-neonBlue',
};

const OUTLINE_STYLES: Record<SubtitleOutlineStyle, string> = {
    contorno: '-1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000, 1px 1px 0 #000, 0 0 8px rgba(0,0,0,0.5)',
    sombra: '0 3px 6px rgba(0,0,0,0.85)',
    ninguno: 'none',
};

export function SubtitleOutputPanel() {
    const previewText = useControlRoomStore((state) => state.subtitlePreviewText);
    const setPreviewText = useControlRoomStore((state) => state.setSubtitlePreviewText);
    const background = useControlRoomStore((state) => state.subtitleBackground);
    const setBackground = useControlRoomStore((state) => state.setSubtitleBackground);
    const textColor = useControlRoomStore((state) => state.subtitleTextColor);
    const setTextColor = useControlRoomStore((state) => state.setSubtitleTextColor);
    const outlineStyle = useControlRoomStore((state) => state.subtitleOutlineStyle);
    const setOutlineStyle = useControlRoomStore((state) => state.setSubtitleOutlineStyle);
    const fontSize = useControlRoomStore((state) => state.subtitleFontSize);
    const setFontSize = useControlRoomStore((state) => state.setSubtitleFontSize);
    const lineCount = useControlRoomStore((state) => state.subtitleLineCount);
    const setLineCount = useControlRoomStore((state) => state.setSubtitleLineCount);
    const displays = useControlRoomStore((state) => state.availableDisplays);
    const outputDisplayId = useControlRoomStore((state) => state.outputDisplayId);
    const setOutputDisplay = useControlRoomStore((state) => state.setOutputDisplay);

    return (
        <Card
            title="Salida de subtítulos"
            icon={
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h12" />
                </svg>
            }
        >
            <div className="space-y-5">
                <div>
                    <p className="mb-2 text-[9px] font-black uppercase tracking-[0.25em] text-zinc-500">Vista previa · lo que se proyecta</p>
                    <div className="relative aspect-video overflow-hidden rounded-2xl border border-white/10 bg-[radial-gradient(circle_at_50%_30%,#18181b,#000)]">
                        <div className={`absolute inset-x-0 bottom-0 flex items-end justify-center px-6 pb-6 pt-10 ${BACKGROUND_CLASSES[background]}`}>
                            <p
                                className={`text-center font-bold leading-tight ${TEXT_COLOR_CLASSES[textColor]}`}
                                style={{
                                    fontSize: `${fontSize * 0.42}px`,
                                    textShadow: OUTLINE_STYLES[outlineStyle],
                                    display: '-webkit-box',
                                    WebkitBoxOrient: 'vertical',
                                    WebkitLineClamp: lineCount,
                                    overflow: 'hidden',
                                }}
                            >
                                {previewText || 'El texto transcrito aparece acá en tiempo real...'}
                            </p>
                        </div>
                    </div>
                    <input
                        type="text"
                        value={previewText}
                        onChange={(event) => setPreviewText(event.target.value)}
                        placeholder="Escribí un texto de prueba para ver cómo se ve..."
                        className="mt-2 w-full rounded-xl border border-white/10 bg-black/40 px-3.5 py-2.5 text-[11px] text-zinc-300 outline-none placeholder:text-zinc-600 focus:border-neonBlue/60"
                    />
                </div>

                <div className="h-px bg-white/5" />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <span className="mb-2 block text-[9px] font-bold uppercase tracking-[0.2em] text-zinc-500">Fondo</span>
                        <SegmentedControl options={BACKGROUND_OPTIONS} value={background} onChange={setBackground} />
                    </div>
                    <div>
                        <span className="mb-2 block text-[9px] font-bold uppercase tracking-[0.2em] text-zinc-500">Color de texto</span>
                        <SegmentedControl options={TEXT_COLOR_OPTIONS} value={textColor} onChange={setTextColor} />
                    </div>
                    <div>
                        <span className="mb-2 block text-[9px] font-bold uppercase tracking-[0.2em] text-zinc-500">Contorno / sombra</span>
                        <SegmentedControl options={OUTLINE_OPTIONS} value={outlineStyle} onChange={setOutlineStyle} />
                    </div>
                    <div>
                        <span className="mb-2 block text-[9px] font-bold uppercase tracking-[0.2em] text-zinc-500">Número de líneas</span>
                        <SegmentedControl
                            options={LINE_COUNT_OPTIONS}
                            value={String(lineCount)}
                            onChange={(value) => setLineCount(Number(value) as SubtitleLineCount)}
                        />
                    </div>
                </div>

                <Slider label="Tamaño de fuente" value={fontSize} min={24} max={72} unit="px" onChange={setFontSize} />

                <Select
                    label="Pantalla de salida física"
                    value={outputDisplayId}
                    options={displays.map((display) => ({ value: display.id, label: `${display.label} (${display.resolution})` }))}
                    onChange={setOutputDisplay}
                />
            </div>
        </Card>
    );
}
