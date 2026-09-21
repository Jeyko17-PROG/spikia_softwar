@extends('layouts.spikia')

@section('content')
<div class="min-h-screen bg-[#050505] text-white font-sans selection:bg-[#00d2ff]/30 selection:text-white">
    <div class="fixed inset-0 bg-[radial-gradient(circle_at_top_left,rgba(0,210,255,0.15),transparent_38%),radial-gradient(circle_at_bottom_right,rgba(0,210,255,0.10),transparent_35%)] pointer-events-none"></div>

    <div class="relative z-10 spikia-page">
        <div class="mb-8 flex justify-center">
            <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="h-16 w-auto opacity-90 transition-opacity hover:opacity-100" alt="Spikia">
        </div>

        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-8 mb-10">
            <div class="space-y-5">
                <a href="{{ route('dashboard') }}" class="group flex h-12 w-12 items-center justify-center rounded-2xl border border-white/10 bg-white/5 shadow-lg transition-all hover:border-[#00d2ff] hover:bg-[#00d2ff]">
                    <svg class="h-5 w-5 text-zinc-400 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>

                <div class="space-y-3 max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.45em] text-zinc-600">Archivo maestro</p>
                    <h1 class="text-4xl lg:text-5xl font-black italic tracking-tight uppercase leading-[0.95]">
                        Transcripciones <span class="text-[#00d2ff]">por sesión</span>
                    </h1>
                    <p class="text-zinc-400 max-w-2xl leading-7 text-sm lg:text-base">
                        Aquí conservamos el historial completo de cada sesión. Cada bloque agrupa sus idiomas, el último texto guardado y los fragmentos cuando el modo está en detalle.
                    </p>
                </div>
            </div>

            <div class="flex flex-col items-start lg:items-end gap-4">
                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 min-w-[110px]">
                        <p class="text-[9px] font-black uppercase tracking-[0.35em] text-zinc-500">Sesiones</p>
                        <p class="mt-2 text-2xl font-black">{{ $resumenes->total() }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 min-w-[110px]">
                        <p class="text-[9px] font-black uppercase tracking-[0.35em] text-zinc-500">Modo</p>
                        <p class="mt-2 text-2xl font-black uppercase">{{ $modo ?? 'todos' }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 min-w-[110px]">
                        <p class="text-[9px] font-black uppercase tracking-[0.35em] text-zinc-500">Total</p>
                        <p class="mt-2 text-2xl font-black">
                            {{ collect($resumenes->items() ?? [])->sum(fn ($resumen) => $resumen['idiomas']->sum(fn ($idioma) => $idioma['items_count'] ?? 0)) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-[2rem] border border-white/10 bg-zinc-900/40 backdrop-blur-sm p-5 lg:p-6 mb-8">
            <form method="GET" action="{{ route('transcripciones.listado') }}" class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-4 items-end">
                <div>
                    <label for="q" class="block text-[9px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-3">Buscar sesión, idioma o texto</label>
                    <input
                        id="q"
                        name="q"
                        value="{{ $q ?? '' }}"
                        type="text"
                        placeholder="Ej. reunión, es, hola mundo..."
                        class="w-full rounded-2xl border border-white/10 bg-black/40 px-5 py-4 text-sm text-white placeholder:text-zinc-600 outline-none transition focus:border-[#00d2ff]/50 focus:ring-2 focus:ring-[#00d2ff]/20"
                    >
                </div>

                <div class="flex flex-wrap gap-3">
                    <input type="hidden" name="modo" value="{{ $modo ?? '' }}">
                    <button type="submit" class="px-5 py-4 rounded-2xl bg-[#00d2ff] text-white text-[10px] font-black uppercase tracking-[0.35em] hover:scale-[1.01] transition">
                        Buscar
                    </button>
                    @if(($q ?? '') !== '' || ! empty($modo))
                        <a href="{{ route('transcripciones.listado') }}" class="px-5 py-4 rounded-2xl border border-white/10 bg-white/5 text-zinc-300 text-[10px] font-black uppercase tracking-[0.35em] hover:border-white/25 hover:text-white transition">
                            Limpiar
                        </a>
                    @endif
                </div>
            </form>
        </div>

        <div class="flex flex-wrap items-center gap-3 mb-8">
            <span class="text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Filtrar modo:</span>
            <a href="{{ route('transcripciones.listado', array_filter(['q' => $q ?? null])) }}" class="px-4 py-2 rounded-full text-[9px] font-black uppercase tracking-widest border transition-all {{ empty($modo) ? 'bg-[#00d2ff] border-[#00d2ff] text-white' : 'bg-zinc-900 border-white/10 text-zinc-400 hover:text-white' }}">Todos</a>
            <a href="{{ route('transcripciones.listado', array_filter(['modo' => 'resumen', 'q' => $q ?? null])) }}" class="px-4 py-2 rounded-full text-[9px] font-black uppercase tracking-widest border transition-all {{ ($modo ?? null) === 'resumen' ? 'bg-emerald-500 border-emerald-500 text-black' : 'bg-zinc-900 border-white/10 text-zinc-400 hover:text-white' }}">Resumen</a>
            <a href="{{ route('transcripciones.listado', array_filter(['modo' => 'detalle', 'q' => $q ?? null])) }}" class="px-4 py-2 rounded-full text-[9px] font-black uppercase tracking-widest border transition-all {{ ($modo ?? null) === 'detalle' ? 'bg-amber-500 border-amber-500 text-black' : 'bg-zinc-900 border-white/10 text-zinc-400 hover:text-white' }}">Detalle</a>
            @if(($q ?? '') !== '')
                <span class="px-4 py-2 rounded-full bg-white/5 border border-white/10 text-[9px] font-black uppercase tracking-widest text-zinc-300">
                    Resultado para "{{ $q }}"
                </span>
            @endif
        </div>

        <div class="space-y-6">
            @forelse($resumenes as $resumen)
                @php
                    $sesion = $resumen['sesion'];
                    $idiomas = $resumen['idiomas'];
                    $slugBase = $resumen['slug'] ?? 'sin-slug';
                    $idiomaBase = $idiomas->first()['idioma'] ?? 'es';
                @endphp

                <section class="rounded-[2rem] border border-white/10 bg-zinc-900/40 overflow-hidden backdrop-blur-sm">
                    <header class="px-6 lg:px-8 py-6 border-b border-white/5 bg-white/[0.02] flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                        <div class="space-y-2">
                            <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Sesión</p>
                            <h2 class="text-2xl lg:text-3xl font-black italic uppercase tracking-tight text-white">{{ $sesion?->titulo ?? $slugBase }}</h2>
                            <p class="text-zinc-500 text-[10px] font-medium tracking-wider">
                                {{ $sesion?->slug ?? $slugBase }} · {{ $sesion?->created_at ? $sesion->created_at->format('d M, Y - H:i') : 'Sin fecha' }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <span class="px-3 py-1 rounded-full bg-[#00d2ff]/10 text-[#00d2ff] border border-[#00d2ff]/20 text-[9px] font-black uppercase tracking-widest">
                                {{ $idiomas->count() }} idiomas
                            </span>
                            <span class="px-3 py-1 rounded-full bg-white/5 text-zinc-300 border border-white/10 text-[9px] font-black uppercase tracking-widest">
                                {{ $resumen['transcripciones_count'] ?? 0 }} transcripciones
                            </span>
                            <a href="{{ route('transcripcion.descargar', [$slugBase, 'audio', $idiomaBase]) }}" class="px-4 py-2 rounded-xl bg-zinc-800 hover:bg-red-600 transition text-[9px] font-black uppercase tracking-widest">Descargar audio</a>
                            <a href="{{ route('transcripcion.descargar', [$slugBase, 'texto', $idiomaBase]) }}" class="px-4 py-2 rounded-xl bg-zinc-800 hover:bg-[#00d2ff] transition text-[9px] font-black uppercase tracking-widest">Descargar texto</a>
                        </div>
                    </header>

                    <div class="p-6 lg:p-8 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                        @foreach($idiomas as $idiomaData)
                            @php
                                $modoFila = $idiomaData['modo'] ?? 'resumen';
                                $itemsIdioma = $idiomaData['items'] ?? collect();
                                $itemsCount = $idiomaData['items_count'] ?? $itemsIdioma->count();
                                $textoPrincipal = $idiomaData['texto'] ?? '';
                                $updatedAt = $idiomaData['updated_at'] ?? null;
                                $fragmentos = $itemsIdioma;
                            @endphp

                            <article class="rounded-[2rem] border border-white/5 bg-black/30 p-5 flex flex-col gap-4">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="px-3 py-1 bg-zinc-800 rounded-full text-[9px] font-black uppercase tracking-widest text-zinc-300 border border-white/5">{{ $idiomaData['idioma'] ?? 'es' }}</span>
                                    <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest border {{ $modoFila === 'detalle' ? 'bg-amber-500/15 text-amber-400 border-amber-500/30' : 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' }}">{{ $modoFila }}</span>
                                </div>

                                <div class="space-y-2">
                                    <p class="text-[10px] uppercase tracking-[0.3em] text-zinc-500 font-black">Último texto</p>
                                    <div class="min-h-[120px] max-h-[240px] overflow-y-auto rounded-2xl bg-zinc-950/60 border border-white/5 p-4">
                                        <p class="text-sm leading-relaxed text-zinc-100 whitespace-pre-line">
                                            {{ $textoPrincipal !== '' ? $textoPrincipal : 'Sin texto registrado todavía.' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between text-[10px] font-medium text-zinc-600">
                                    <span>Actualizado</span>
                                    <span>{{ $updatedAt ? $updatedAt->format('d M, Y - H:i') : 'N/A' }}</span>
                                </div>

                                @if($modoFila === 'detalle' && $itemsCount > 1)
                                    <div class="rounded-2xl border border-white/5 bg-zinc-950/50 p-4 space-y-3">
                                        <p class="text-[9px] font-black uppercase tracking-[0.3em] text-zinc-500">Fragmentos recientes</p>
                                        <div class="max-h-60 overflow-y-auto space-y-3 pr-1">
                                            @foreach($fragmentos as $item)
                                                <div class="rounded-xl border border-white/5 bg-white/[0.03] p-3">
                                                    <p class="text-[9px] uppercase tracking-[0.25em] text-zinc-500 font-black mb-2">
                                                        {{ $item->created_at ? $item->created_at->format('H:i:s') : 'N/A' }}
                                                    </p>
                                                    <p class="text-sm text-zinc-200 whitespace-pre-line">
                                                        {{ $item->texto }}
                                                    </p>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <div class="flex justify-end gap-2 pt-1">
                                    <a href="{{ route('transcripcion.descargar', [$slugBase, 'audio', $idiomaData['idioma'] ?? 'es']) }}" class="bg-zinc-800 hover:bg-red-600 hover:text-white text-zinc-400 p-3 rounded-xl transition-all" title="Descargar audio">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </a>
                                    <a href="{{ route('transcripcion.descargar', [$slugBase, 'texto', $idiomaData['idioma'] ?? 'es']) }}" class="bg-zinc-800 hover:bg-[#00d2ff] hover:text-white text-zinc-400 p-3 rounded-xl transition-all" title="Descargar texto TXT">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                    </a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="bg-zinc-900/20 border border-dashed border-white/10 rounded-[2rem] p-20 text-center">
                    <p class="text-[10px] font-black uppercase tracking-[0.4em] text-zinc-500">No hay transcripciones todavía</p>
                    @if(($q ?? '') !== '')
                        <p class="mt-4 text-sm text-zinc-500">No encontramos coincidencias para tu búsqueda.</p>
                    @endif
                </div>
            @endforelse
        </div>

        @if($resumenes->hasPages())
            <div class="mt-10 flex justify-center">
                <div class="rounded-2xl border border-white/10 bg-zinc-900/40 px-5 py-4">
                    {{ $resumenes->onEachSide(1)->links() }}
                </div>
            </div>
        @endif
    </div>
</div>

<style>
    body { -webkit-font-smoothing: antialiased; letter-spacing: -0.01em; }
    ::-webkit-scrollbar { width: 4px; height: 4px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #1a1a1a; border-radius: 10px; }
    ::-webkit-scrollbar-thumb:hover { background: #333; }
</style>
@endsection
