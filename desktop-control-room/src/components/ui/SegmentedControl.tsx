interface SegmentedOption<T extends string> {
    value: T;
    label: string;
}

interface SegmentedControlProps<T extends string> {
    options: SegmentedOption<T>[];
    value: T;
    onChange: (value: T) => void;
    fullWidth?: boolean;
}

// Grupo de botones tipo "pill row" para elegir UNA opcion entre pocas (perfil de
// rendimiento, color de texto, etc.) - mas rapido de escanear visualmente que un <select>
// para 2-4 opciones.
export function SegmentedControl<T extends string>({ options, value, onChange, fullWidth = true }: SegmentedControlProps<T>) {
    return (
        <div className={`grid gap-2 ${fullWidth ? '' : 'inline-grid'}`} style={{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }}>
            {options.map((option) => {
                const active = option.value === value;
                return (
                    <button
                        key={option.value}
                        type="button"
                        onClick={() => onChange(option.value)}
                        className={`rounded-xl border px-3 py-2 text-[10px] font-black uppercase tracking-widest transition-all ${
                            active
                                ? 'border-spikiaPurple bg-spikiaPurple text-white shadow-glow'
                                : 'border-white/10 bg-black/30 text-zinc-400 hover:border-white/25 hover:text-white'
                        }`}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
