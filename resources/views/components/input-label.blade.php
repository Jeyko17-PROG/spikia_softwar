@props(['value'])

<label {{ $attributes->merge(['class' => 'block mb-2 text-[10px] font-black uppercase tracking-[0.25em] text-zinc-500']) }}>
    {{ $value ?? $slot }}
</label>
