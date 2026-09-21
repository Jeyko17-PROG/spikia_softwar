@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'w-full px-4 py-2.5 text-sm bg-black text-white border border-[#7C3AED]/40 rounded-xl placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-[#4ffcff] focus:shadow-[0_0_15px_#4ffcff] transition']) }}>
