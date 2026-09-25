interface ToggleProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label?: string;
    description?: string;
    disabled?: boolean;
}

// Switch propio (no <input type="checkbox"> nativo): mismo patron visual usado en todo
// Spikia para toggles (ver "Detectar idioma automáticamente" en el Master de la app web).
export function Toggle({ checked, onChange, label, description, disabled = false }: ToggleProps) {
    return (
        <label className={`flex items-center justify-between gap-3 ${disabled ? 'opacity-40' : 'cursor-pointer'}`}>
            {(label || description) && (
                <span className="min-w-0">
                    {label && <span className="block text-[11px] font-bold text-zinc-200">{label}</span>}
                    {description && <span className="mt-0.5 block text-[10px] leading-relaxed text-zinc-500">{description}</span>}
                </span>
            )}
            <span className="relative inline-flex h-6 w-11 shrink-0 items-center">
                <input
                    type="checkbox"
                    className="peer sr-only"
                    checked={checked}
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.checked)}
                />
                <span className="pointer-events-none absolute inset-0 rounded-full bg-zinc-700 transition-colors peer-checked:bg-spikiaPurple" />
                <span className="pointer-events-none absolute left-0.5 h-5 w-5 rounded-full bg-white transition-transform peer-checked:translate-x-5" />
            </span>
        </label>
    );
}
