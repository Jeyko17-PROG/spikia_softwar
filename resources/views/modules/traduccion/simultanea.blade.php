@extends('layouts.spikia')

@section('title', 'Traducción Simultánea | Spikia')

@push('head-scripts')
    @vite('resources/js/traduccion-simultanea.js')
@endpush

@section('content')
@php
    $cfg = $config;
    $languages = $cfg['available_languages'];
    $sttModels = $cfg['available_stt_models'];
    $translationModels = $cfg['available_translation_models'];
    $voices = $cfg['available_voices'];
    $defaultPrompt = $cfg['master_translation_prompt'];
@endphp

<div id="ts-config"
     data-csrf="{{ csrf_token() }}"
     data-store-url="{{ route('traduccion.simultanea.store') }}"
     class="hidden">
</div>

<div class="min-h-screen bg-[#050505] text-white px-6 py-10">
    <div class="max-w-4xl mx-auto space-y-8">

        <div class="flex justify-center">
            <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="h-16 w-auto opacity-90 transition-opacity hover:opacity-100" alt="Spikia">
        </div>

        {{-- Header --}}
        <div class="space-y-4">
            <a href="{{ route('sesiones.index') }}" class="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 hover:text-white transition">
                <span>&larr;</span> Volver a sesiones
            </a>
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.4em] text-zinc-500">OpenAI · MVP</p>
                <h1 class="text-4xl font-black italic tracking-tighter uppercase">Traducción Simultánea</h1>
            </div>
        </div>

        {{-- Formulario de configuración --}}
        <form id="ts-form" class="space-y-6" enctype="multipart/form-data">
            @csrf

            {{-- A: Modo de traducción --}}
            <div class="rounded-[1.8rem] border border-white/10 bg-zinc-950/70 p-6 space-y-4">
                <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Modo de traducción</p>
                <div class="flex gap-4">
                    <label class="ts-radio-card flex-1 cursor-pointer rounded-2xl border border-white/10 p-4 text-center hover:border-purple-500/50 transition has-[:checked]:border-purple-500 has-[:checked]:bg-purple-500/10">
                        <input type="radio" name="translation_mode" value="voice_to_text" checked class="hidden">
                        <span class="block text-sm font-bold">Voz a texto</span>
                        <span class="block text-xs text-zinc-400 mt-1">Recibe texto traducido</span>
                    </label>
                    <label class="ts-radio-card flex-1 cursor-pointer rounded-2xl border border-white/10 p-4 text-center hover:border-purple-500/50 transition has-[:checked]:border-purple-500 has-[:checked]:bg-purple-500/10">
                        <input type="radio" name="translation_mode" value="voice_to_voice" class="hidden">
                        <span class="block text-sm font-bold">Voz a voz</span>
                        <span class="block text-xs text-zinc-400 mt-1">Recibe audio traducido</span>
                    </label>
                </div>
            </div>

            {{-- B: Participantes --}}
            <div class="rounded-[1.8rem] border border-white/10 bg-zinc-950/70 p-6 space-y-4">
                <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Participantes</p>
                <div class="flex gap-4">
                    <label class="ts-radio-card flex-1 cursor-pointer rounded-2xl border border-white/10 p-4 text-center hover:border-purple-500/50 transition has-[:checked]:border-purple-500 has-[:checked]:bg-purple-500/10">
                        <input type="radio" name="speaker_mode" value="single" checked class="hidden">
                        <span class="block text-sm font-bold">Una persona</span>
                    </label>
                    <label class="ts-radio-card flex-1 cursor-pointer rounded-2xl border border-white/10 p-4 text-center hover:border-purple-500/50 transition has-[:checked]:border-purple-500 has-[:checked]:bg-purple-500/10">
                        <input type="radio" name="speaker_mode" value="multiple" class="hidden">
                        <span class="block text-sm font-bold">Más de una persona</span>
                    </label>
                </div>

                {{-- Nombres de hablantes (visible solo en multiple) --}}
                <div id="speakers-container" class="hidden space-y-3">
                    <div class="flex items-center justify-between">
                        <p class="text-xs text-zinc-400">Nombres de participantes</p>
                        <button type="button" id="add-speaker-btn" class="text-xs text-purple-400 hover:text-purple-300 transition">+ Agregar</button>
                    </div>
                    <div id="speakers-list" class="space-y-2">
                        <div class="flex gap-3 items-center speaker-row">
                            <input type="hidden" name="speakers[0][speaker_id]" value="speaker_1">
                            <input type="text" name="speakers[0][name]" placeholder="Nombre persona 1"
                                class="flex-1 rounded-xl bg-white/5 border border-white/10 px-4 py-2 text-sm text-white placeholder-zinc-500 focus:border-purple-500 focus:outline-none">
                        </div>
                        <div class="flex gap-3 items-center speaker-row">
                            <input type="hidden" name="speakers[1][speaker_id]" value="speaker_2">
                            <input type="text" name="speakers[1][name]" placeholder="Nombre persona 2"
                                class="flex-1 rounded-xl bg-white/5 border border-white/10 px-4 py-2 text-sm text-white placeholder-zinc-500 focus:border-purple-500 focus:outline-none">
                        </div>
                    </div>
                </div>
            </div>

            {{-- C: Idiomas --}}
            <div class="rounded-[1.8rem] border border-white/10 bg-zinc-950/70 p-6 space-y-4">
                <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Idiomas</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-xs text-zinc-400">Idioma de entrada</label>
                        <select name="input_language" class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-3 text-sm text-white focus:border-purple-500 focus:outline-none">
                            @foreach($languages as $lang)
                                <option value="{{ $lang['value'] }}" {{ $lang['value'] === 'es' ? 'selected' : '' }}>
                                    {{ $lang['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs text-zinc-400">Idioma de salida</label>
                        <select name="output_language" class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-3 text-sm text-white focus:border-purple-500 focus:outline-none">
                            @foreach($languages as $lang)
                                <option value="{{ $lang['value'] }}" {{ $lang['value'] === 'en' ? 'selected' : '' }}>
                                    {{ $lang['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            {{-- D: Modelos --}}
            <div class="rounded-[1.8rem] border border-white/10 bg-zinc-950/70 p-6 space-y-4">
                <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Modelos de IA</p>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="space-y-2">
                        <label class="text-xs text-zinc-400">Modelo de transcripción</label>
                        <select id="stt-model" name="speech_to_text_model" class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-3 text-sm text-white focus:border-purple-500 focus:outline-none">
                            @foreach($sttModels as $m)
                                <option value="{{ $m['value'] }}" {{ $m['value'] === $cfg['speech_to_text_model'] ? 'selected' : '' }}>
                                    {{ $m['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs text-zinc-400">Modelo de traducción</label>
                        <select name="translation_model" class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-3 text-sm text-white focus:border-purple-500 focus:outline-none">
                            @foreach($translationModels as $m)
                                <option value="{{ $m['value'] }}" {{ $m['value'] === $cfg['translation_model'] ? 'selected' : '' }}>
                                    {{ $m['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Opciones TTS (solo visible en voice_to_voice) --}}
                <div id="tts-options" class="hidden grid grid-cols-1 gap-4 md:grid-cols-2 mt-2">
                    <div class="space-y-2">
                        <label class="text-xs text-zinc-400">Modelo de voz</label>
                        <select name="text_to_speech_model" class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-3 text-sm text-white focus:border-purple-500 focus:outline-none">
                            <option value="gpt-4o-mini-tts">gpt-4o-mini-tts</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs text-zinc-400">Voz</label>
                        <select name="voice" class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-3 text-sm text-white focus:border-purple-500 focus:outline-none">
                            @foreach($voices as $v)
                                <option value="{{ $v }}" {{ $v === $cfg['voice'] ? 'selected' : '' }}>{{ $v }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            {{-- E: Prompt maestro --}}
            <div class="rounded-[1.8rem] border border-white/10 bg-zinc-950/70 p-6 space-y-3">
                <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Prompt maestro de traducción</p>
                <textarea name="master_translation_prompt" rows="4"
                    class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-3 text-sm text-white placeholder-zinc-500 focus:border-purple-500 focus:outline-none resize-none"
                >{{ $defaultPrompt }}</textarea>
            </div>

            {{-- F: Grabación --}}
            <div class="rounded-[1.8rem] border border-white/10 bg-zinc-950/70 p-6 space-y-4">
                <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Grabación</p>
                <div class="flex items-center gap-4">
                    <button type="button" id="record-btn"
                        class="flex items-center gap-3 rounded-2xl bg-white px-6 py-4 text-[10px] font-black uppercase tracking-[0.35em] text-black transition hover:bg-purple-500 hover:text-white">
                        <span id="record-icon" class="h-3 w-3 rounded-full bg-red-500 inline-block"></span>
                        <span id="record-label">Grabar</span>
                    </button>
                    <span id="record-timer" class="text-xs text-zinc-500 hidden">00:00</span>
                </div>

                {{-- Input oculto para el archivo de audio --}}
                <input type="file" id="audio-input" name="audio" accept="audio/*" class="hidden">

                <p id="audio-status" class="text-xs text-zinc-500 hidden">Audio listo para enviar</p>
            </div>

            {{-- Botón enviar --}}
            <button type="submit" id="submit-btn"
                class="w-full rounded-2xl bg-purple-600 px-6 py-5 text-sm font-black uppercase tracking-[0.25em] text-white transition hover:bg-purple-500 disabled:opacity-40 disabled:cursor-not-allowed">
                Traducir
            </button>
        </form>

        {{-- Área de resultados --}}
        <div id="ts-result" class="hidden space-y-4">
            <div class="rounded-[1.8rem] border border-white/10 bg-zinc-950/70 p-6 space-y-6">
                <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Resultado</p>

                <div id="result-transcript" class="space-y-2">
                    <p class="text-xs text-zinc-400">Texto original (transcrito)</p>
                    <p id="transcript-text" class="text-sm text-white leading-relaxed bg-white/5 rounded-xl p-4"></p>
                </div>

                <div id="result-translation" class="space-y-2">
                    <p class="text-xs text-zinc-400">Texto traducido</p>
                    <p id="translated-text" class="text-sm font-medium text-white leading-relaxed bg-purple-500/10 border border-purple-500/20 rounded-xl p-4"></p>
                </div>

                <div id="result-audio" class="hidden space-y-2">
                    <p class="text-xs text-zinc-400">Audio traducido</p>
                    <audio id="output-audio" controls class="w-full rounded-xl"></audio>
                </div>
            </div>
        </div>

        {{-- Estado de carga --}}
        <div id="ts-loading" class="hidden text-center py-12 space-y-4">
            <div class="inline-block h-10 w-10 animate-spin rounded-full border-4 border-purple-500 border-t-transparent"></div>
            <p class="text-sm text-zinc-400" id="loading-step">Procesando...</p>
        </div>

        {{-- Error --}}
        <div id="ts-error" class="hidden rounded-2xl border border-red-500/30 bg-red-500/10 p-4">
            <p class="text-sm text-red-400" id="error-message"></p>
        </div>

    </div>
</div>
@endsection
