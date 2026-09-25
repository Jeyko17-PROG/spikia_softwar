interface SelectOption {
    value: string;
    label: string;
}

interface SelectProps {
    label?: string;
    value: string;
    options: SelectOption[];
    placeholder?: string;
    onChange: (value: string) => void;
}

export function Select({ label, value, options, placeholder, onChange }: SelectProps) {
    return (
        <label className="block">
            {label && <span className="mb-2 block text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-400">{label}</span>}
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="w-full appearance-none rounded-xl border border-white/10 bg-black/40 px-3.5 py-2.5 text-[12px] font-semibold text-white outline-none transition focus:border-neonBlue/60"
            >
                {placeholder && (
                    <option value="" disabled>
                        {placeholder}
                    </option>
                )}
                {options.map((option) => (
                    <option key={option.value} value={option.value} className="bg-zinc-900">
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}
