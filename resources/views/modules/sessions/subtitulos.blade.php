@extends('layouts.spikia')

@section('title', 'Spikia - Subtítulos - ' . $sesion->titulo)

@section('content')
<div id="subs-root" class="fixed inset-0 flex items-end justify-center overflow-hidden" style="background: var(--subs-bg, #000000);">

    <button id="subs-settings-toggle" type="button" class="fixed top-4 right-4 z-20 flex items-center justify-center w-10 h-10 rounded-full bg-black/40 border border-white/10 text-white/70 hover:text-white transition" title="Ajustes de subtítulos">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
    </button>

    <div id="subs-settings-panel" class="fixed top-16 right-4 z-20 hidden w-64 rounded-2xl border border-white/10 bg-zinc-950/95 backdrop-blur-md p-4 shadow-2xl">
        <p class="text-[10px] font-black uppercase tracking-[0.25em] text-zinc-500 mb-3">Ajustes</p>

        <label class="block text-[10px] font-bold uppercase tracking-widest text-zinc-400 mb-1">Idioma</label>
        <select id="subs-lang-select" class="w-full mb-3 rounded-lg bg-zinc-900 border border-white/10 text-white text-sm px-2 py-1.5">
            @foreach(config('spikia.listener_languages', []) as $language)
                <option value="{{ $language['id'] }}">{{ $language['label'] }}</option>
            @endforeach
        </select>

        <div class="grid grid-cols-2 gap-3 mb-3">
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-widest text-zinc-400 mb-1">Letra</label>
                <input id="subs-text-color" type="color" value="#ffffff" class="w-full h-9 rounded-lg border border-white/10 bg-zinc-900 cursor-pointer">
            </div>
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-widest text-zinc-400 mb-1">Fondo</label>
                <input id="subs-bg-color" type="color" value="#000000" class="w-full h-9 rounded-lg border border-white/10 bg-zinc-900 cursor-pointer">
            </div>
        </div>

        <label class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400 cursor-pointer">
            <input id="subs-bg-transparent" type="checkbox" class="rounded border-white/20 bg-zinc-900">
            Fondo transparente (OBS)
        </label>
    </div>

    <main class="relative z-10 w-full px-8 pb-10 flex flex-col items-center text-center pointer-events-none">
        <p id="subs-text" class="font-black leading-tight tracking-tight drop-shadow-[0_2px_10px_rgba(0,0,0,0.8)]" style="color: var(--subs-color, #ffffff); font-size: clamp(1.5rem, 4vw, 3.25rem);"></p>
    </main>
</div>

@php
    $listenerLanguageLabels = [];
    foreach (config('spikia.listener_languages', []) as $language) {
        if (! empty($language['id']) && ! empty($language['label'])) {
            $listenerLanguageLabels[$language['id']] = $language['label'];
        }
    }
    $subtitulosConfig = [
        'slug' => $sesion->slug,
        'feedUrl' => route('sesiones.mensajes.feed', ['slug' => $sesion->slug], false),
        'defaultLang' => 'es-ES',
        'languageLabels' => $listenerLanguageLabels,
    ];
@endphp

@push('head-scripts')
<script>
    window.__SPIKIA_SUBTITULOS__ = @json($subtitulosConfig);
</script>
@vite('resources/js/subtitulos.js')
@endpush
@endsection
