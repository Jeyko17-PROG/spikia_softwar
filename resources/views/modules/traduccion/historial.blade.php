@extends('layouts.spikia')

@section('title', 'Historial de Traducciones | Spikia')

@section('content')
<div class="min-h-screen bg-[#050505] text-white px-6 py-10">
    <div class="max-w-6xl mx-auto space-y-8">

        <div class="flex justify-center">
            <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="h-16 w-auto opacity-90 transition-opacity hover:opacity-100" alt="Spikia">
        </div>

        {{-- Header --}}
        <div class="space-y-4">
            <a href="{{ route('sesiones.index') }}" class="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 hover:text-white transition">
                <span>&larr;</span> Volver a sesiones
            </a>
            <div class="flex items-center justify-between gap-5">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.4em] text-zinc-500">OpenAI · MVP</p>
                    <h1 class="text-4xl font-black italic tracking-tighter uppercase">Historial de Traducciones</h1>
                </div>
                <a href="{{ route('traduccion.simultanea') }}"
                    class="rounded-2xl bg-purple-600 px-5 py-3 text-[10px] font-black uppercase tracking-[0.25em] text-white hover:bg-purple-500 transition">
                    + Nueva traducción
                </a>
            </div>
        </div>

        {{-- Lista --}}
        @if($historial->isEmpty())
            <div class="rounded-[1.8rem] border border-white/10 bg-zinc-950/70 p-12 text-center">
                <p class="text-zinc-500 text-sm">Aún no tienes traducciones simultáneas. Crea la primera desde el botón superior.</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($historial as $item)
                    <div class="rounded-[1.8rem] border border-white/10 bg-zinc-950/70 p-6 space-y-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <span class="rounded-full bg-purple-500/20 border border-purple-500/30 px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-purple-300">
                                    {{ $item->translation_mode === 'voice_to_voice' ? 'Voz a Voz' : 'Voz a Texto' }}
                                </span>
                                <span class="text-xs text-zinc-500">
                                    {{ strtoupper($item->input_language) }} → {{ strtoupper($item->output_language) }}
                                </span>
                            </div>
                            <span class="text-xs text-zinc-500">{{ $item->created_at->format('d/m/Y H:i') }}</span>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">Texto original</p>
                                <p class="text-sm text-white bg-white/5 rounded-xl p-3">{{ $item->original_transcript ?: '—' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">Traducción</p>
                                <p class="text-sm text-white bg-purple-500/10 border border-purple-500/20 rounded-xl p-3">{{ $item->translated_text ?: '—' }}</p>
                            </div>
                        </div>

                        @if($item->output_audio_url)
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">Audio traducido</p>
                                <audio controls src="{{ $item->output_audio_url }}" class="w-full rounded-xl"></audio>
                            </div>
                        @endif

                        <div class="flex items-center gap-3 text-[10px] text-zinc-500">
                            <span>STT: {{ $item->speech_to_text_model }}</span>
                            <span>·</span>
                            <span>Translate: {{ $item->translation_model }}</span>
                            @if($item->voice)
                                <span>·</span>
                                <span>Voz: {{ $item->voice }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $historial->links() }}
            </div>
        @endif

    </div>
</div>
@endsection
