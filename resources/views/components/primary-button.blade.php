<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center px-5 py-2.5 text-sm font-semibold rounded-xl text-black bg-[#4ffcff] shadow-[0_0_25px_#4ffcff] hover:shadow-[0_0_45px_#4ffcff] hover:scale-105 transition-all duration-300']) }}>
    {{ $slot }}
</button>
