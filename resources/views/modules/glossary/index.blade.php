@extends('layouts.spikia')

@section('title', 'Glosarios | Spikia')

@push('styles')
@vite('resources/css/glossary.css')
@endpush

@push('head-scripts')
@vite('resources/js/glossary.js')
@endpush

@section('content')
@php
    $languageLabels = [
        'es' => 'Español',
        'en' => 'Inglés',
        'pt' => 'Portugués',
        'fr' => 'Francés',
        'de' => 'Alemán',
        'it' => 'Italiano',
    ];

    $languageStyles = [
        'es' => 'text-cyan-300 bg-cyan-500/10 border-cyan-500/20',
        'en' => 'text-emerald-300 bg-emerald-500/10 border-emerald-500/20',
        'pt' => 'text-amber-300 bg-amber-500/10 border-amber-500/20',
        'fr' => 'text-fuchsia-300 bg-fuchsia-500/10 border-fuchsia-500/20',
        'de' => 'text-violet-300 bg-violet-500/10 border-violet-500/20',
        'it' => 'text-rose-300 bg-rose-500/10 border-rose-500/20',
    ];

    $templates = [
        'medicina' => [
            'titulo' => 'Terminologia Medica',
            'idioma' => 'es',
            'terminos' => "stroke => accidente cerebrovascular\nheart failure => insuficiencia cardiaca\npaciente\nhistorial clinico\nurgencias\nmedicina preventiva",
        ],
        'legal' => [
            'titulo' => 'Terminologia Legal',
            'idioma' => 'es',
            'terminos' => "agreement => contrato\nclause => clausula\naudiencia\njurisprudencia\nparte demandante\nrepresentacion legal",
        ],
        'educacion' => [
            'titulo' => 'Terminologia Educativa',
            'idioma' => 'es',
            'terminos' => "curriculum => curriculo\nstudent success => exito estudiantil\ndocente\nevaluacion\naula\nmatricula",
        ],
        'tecnologia' => [
            'titulo' => 'Terminologia Tecnologica',
            'idioma' => 'en',
            'terminos' => "deploy => despliegue\nauthentication => autenticacion\nencryption => cifrado\nserver\nAPI\ncloud",
        ],
        'negocios' => [
            'titulo' => 'Terminologia Empresarial',
            'idioma' => 'en',
            'terminos' => "revenue => ingresos\nstakeholder => parte interesada\nbudget\ninvoice\nproposal\nworkflow",
        ],
        'eventos' => [
            'titulo' => 'Terminologia de Eventos',
            'idioma' => 'es',
            'terminos' => "keynote speaker => ponente principal\nsimultaneous interpretation => interpretacion simultanea\norador\nagenda\nlogistica\nanfitrion",
        ],
        'personalizado' => [
            'titulo' => '',
            'idioma' => 'es',
            'terminos' => '',
        ],
    ];
@endphp

<div
    id="glossaryRoot"
    data-templates='@json($templates)'
    data-store-url="{{ route('glosarios.store') }}"
    data-update-url-template="{{ route('glosarios.update', ['id' => '__ID__']) }}"
    class="glossary-panel min-h-screen bg-[#050505] text-white">
    <div class="spikia-page">
        <div class="mb-8 flex justify-center">
            <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="h-20 w-auto opacity-90 transition-opacity hover:opacity-100" alt="Spikia">
        </div>

        <div class="mb-12 flex items-center justify-between gap-6">
            <div class="flex items-center gap-6">
                <a href="{{ route('dashboard') }}" class="group flex h-12 w-12 items-center justify-center rounded-2xl border border-white/10 bg-white/5 shadow-lg transition-all hover:border-[#00d2ff] hover:bg-[#00d2ff]">
                    <svg class="h-5 w-5 text-zinc-400 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div>
                    <h2 class="text-4xl font-black italic uppercase leading-none tracking-tighter">
                        Mis <span class="text-[#00d2ff]">glosarios</span>
                    </h2>
                    <p class="mt-2 text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500 italic">Biblioteca de terminos personalizados</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">
            <div class="space-y-4 xl:col-span-5">
                @forelse($glosarios as $g)
                    @php
                        $idioma = $g->idioma ?? 'es';
                        $idiomaLabel = $languageLabels[$idioma] ?? strtoupper($idioma);
                        $style = $languageStyles[$idioma] ?? 'text-zinc-300 bg-white/5 border-white/10';
                    @endphp
                    <div class="group flex items-center justify-between rounded-[2rem] border border-white/5 bg-zinc-900/30 p-6 backdrop-blur-sm transition-all hover:bg-zinc-900/60">
                        <div class="space-y-3">
                            <p class="text-base font-bold uppercase italic tracking-tight group-hover:text-[#00d2ff] transition-colors">{{ $g->titulo }}</p>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-[9px] font-black uppercase tracking-[0.25em] {{ $style }}">
                                    <span class="h-2 w-2 rounded-full bg-current"></span>
                                    {{ $idiomaLabel }}
                                </span>
                                <span class="rounded bg-white/5 px-2 py-0.5 text-[8px] font-black uppercase text-[#00d2ff]">{{ strtoupper($idioma) }}</span>
                                <p class="text-[9px] font-black uppercase tracking-[0.2em] text-zinc-600">Sincronizado</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                data-edit-glosary
                                data-id="{{ $g->id }}"
                                data-titulo="{{ $g->titulo }}"
                                data-terminos="{{ $g->terminos }}"
                                data-idioma="{{ $g->idioma ?? 'es' }}"
                                class="flex h-10 w-10 items-center justify-center rounded-xl bg-zinc-800/50 text-zinc-500 transition-all hover:bg-[#00d2ff]/10 hover:text-[#00d2ff]">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                </svg>
                            </button>

                            <form action="{{ route('glosarios.destroy', $g->id) }}" method="POST" onsubmit="return spikiaConfirmSubmit(event, '¿Eliminar este glosario? Esta acción no se puede deshacer.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="flex h-10 w-10 items-center justify-center rounded-xl bg-zinc-800/50 text-zinc-500 transition-all hover:bg-red-500/10 hover:text-red-500">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </div>
                @empty
                    <div class="rounded-[2.5rem] border-2 border-dashed border-white/5 p-16 text-center text-zinc-700">
                        <p class="text-[10px] font-black uppercase tracking-widest italic">No hay glosarios creados</p>
                    </div>
                @endforelse

                @if(method_exists($glosarios, 'hasPages') && $glosarios->hasPages())
                    <div class="rounded-[1.75rem] border border-white/10 bg-zinc-950/70 px-5 py-4 shadow-2xl">
                        {{ $glosarios->onEachSide(1)->links() }}
                    </div>
                @endif
            </div>

            <div class="xl:col-span-7">
                <div class="relative overflow-hidden rounded-[3rem] border border-white/10 bg-zinc-900/20 p-10 shadow-2xl backdrop-blur-md">
                    <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-transparent via-[#00d2ff] to-transparent"></div>

                    <div id="glosario-form-header" class="mb-8 flex items-center justify-between border-b border-white/5 pb-8">
                        <div>
                            <h3 id="formTitle" class="text-xl font-black uppercase italic tracking-[0.2em] text-[#00d2ff]">Nuevo glosario</h3>
                            <p class="mt-2 text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600" id="glosario-form-subtitle">Creá un glosario o tocá uno de la lista para editarlo.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" id="glosario-form-close" class="hidden rounded-full border border-white/10 px-5 py-2.5 text-[10px] font-black uppercase tracking-widest text-zinc-400 transition-all hover:border-white/25 hover:text-white">
                                Cerrar
                            </button>
                            <button type="button" data-new-glosario class="rounded-full bg-[#00d2ff] px-6 py-2.5 text-[10px] font-black uppercase tracking-widest text-black shadow-lg transition-all hover:bg-white">
                                + Nuevo glosario
                            </button>
                        </div>
                    </div>

                    <div id="glosario-form-panel" class="hidden">

                    <div class="mb-6">
                        <p class="mb-3 text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">O empezá desde una plantilla (precarga términos de ejemplo, editables)</p>
                        <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                            @foreach ($templates as $key => $template)
                                @if($key !== 'personalizado')
                                    <button type="button" data-template-trigger="{{ $key }}" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-left transition hover:border-[#00d2ff]/40 hover:bg-[#00d2ff]/10">
                                        <span class="block text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500">{{ $key }}</span>
                                        <span class="mt-2 block text-sm font-bold text-white">{{ $template['titulo'] }}</span>
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    <form id="glosarioForm" action="{{ route('glosarios.store') }}" method="POST" class="space-y-8">
                        @csrf
                        <input type="hidden" name="_method" id="formMethod" value="POST">

                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label class="mb-4 ml-4 block text-[10px] font-black uppercase tracking-[0.4em] text-zinc-500 italic">Tema preconfigurado</label>
                                <select id="tema" class="w-full cursor-pointer appearance-none rounded-2xl border border-white/5 bg-black/60 px-6 py-5 text-sm font-bold text-white outline-none transition-all shadow-inner focus:border-[#00d2ff]">
                                    @foreach ($templates as $key => $template)
                                        <option value="{{ $key }}">{{ ucfirst($key) === 'Personalizado' ? 'Personalizado' : $key }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="mb-4 ml-4 block text-[10px] font-black uppercase tracking-[0.4em] text-zinc-500 italic">Idioma de origen</label>
                                <select name="idioma" id="idioma" class="w-full cursor-pointer appearance-none rounded-2xl border border-white/5 bg-black/60 px-6 py-5 text-sm font-bold text-white outline-none transition-all shadow-inner focus:border-[#00d2ff]">
                                    <option value="es" @selected(old('idioma', 'es') === 'es')>Español (Latam/ES)</option>
                                    <option value="en" @selected(old('idioma') === 'en')>Inglés (US/UK)</option>
                                    <option value="pt" @selected(old('idioma') === 'pt')>Portugués</option>
                                    <option value="fr" @selected(old('idioma') === 'fr')>Francés</option>
                                    <option value="de" @selected(old('idioma') === 'de')>Alemán</option>
                                    <option value="it" @selected(old('idioma') === 'it')>Italiano</option>
                                </select>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($languageLabels as $code => $label)
                                        <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[9px] font-black uppercase tracking-[0.25em] text-zinc-400">
                                            {{ $label }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="mb-4 ml-4 block text-[10px] font-black uppercase tracking-[0.4em] text-zinc-500 italic">Titulo del glosario</label>
                            <input type="text" name="titulo" id="titulo" required placeholder="Ej: Terminologia Medica"
                                class="w-full rounded-2xl border border-white/5 bg-black/60 px-6 py-5 text-sm font-bold text-white outline-none shadow-inner transition-all focus:border-[#00d2ff]">
                        </div>

                        <div>
                            <label class="mb-4 ml-4 block text-[10px] font-black uppercase tracking-[0.4em] text-zinc-500 italic">Terminos de refuerzo (IA)</label>
                            <textarea name="terminos" id="terminos" rows="8" placeholder="Usa una linea por termino. Ejemplos:&#10;stroke => accidente cerebrovascular&#10;heart failure => insuficiencia cardiaca&#10;electrocardiograma"
                                class="w-full resize-none rounded-[2rem] border border-white/5 bg-black/60 px-6 py-6 text-sm text-zinc-300 outline-none shadow-inner transition-all focus:border-[#00d2ff]"></textarea>
                            <div class="mt-4 rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-xs text-zinc-400">
                                Formato recomendado: una linea por termino.
                                Si quieres forzar una traduccion, usa <code class="text-cyan-300">origen =&gt; traduccion preferida</code>.
                                Si solo quieres preservar un termino tecnico, escribe solo la palabra o frase.
                            </div>
                        </div>

                        <div class="flex justify-end pt-6">
                            <button type="submit" id="submitBtn" class="rounded-2xl bg-white px-14 py-5 text-[11px] font-black uppercase tracking-[0.3em] text-black shadow-xl transition-all hover:bg-[#00d2ff] hover:text-white active:scale-95">
                                Guardar glosario
                            </button>
                        </div>
                    </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
