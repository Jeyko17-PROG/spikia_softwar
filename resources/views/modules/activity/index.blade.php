@extends('layouts.spikia')

@section('title', 'Actividad | Spikia')

@push('styles')
@vite('resources/css/activity.css')
@endpush

@section('content')
<div class="min-h-screen bg-[#050505] text-white">
    <div class="spikia-page">
        @if(Storage::disk('public')->exists('media/images/spikia-15.png'))
            <div class="mb-8 flex justify-center">
                <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="h-16 w-auto opacity-90 transition-opacity hover:opacity-100" alt="Spikia">
            </div>
        @endif

        <div class="mb-10 flex flex-col gap-8 lg:flex-row lg:items-start lg:justify-between">
            <div class="space-y-5">
                <a href="{{ route('dashboard') }}" class="group flex h-12 w-12 items-center justify-center rounded-2xl border border-white/10 bg-white/5 shadow-lg transition-all hover:border-[#00d2ff] hover:bg-[#00d2ff]">
                    <svg class="h-5 w-5 text-zinc-400 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>

                <div class="max-w-3xl space-y-3">
                    <p class="text-[10px] font-black uppercase tracking-[0.45em] text-zinc-600">Registro central</p>
                    <h1 class="text-4xl font-black italic uppercase leading-[0.95] tracking-tight">
                        Registro de <span class="text-[#00d2ff]">actividad</span>
                    </h1>
                    <p class="max-w-2xl text-sm leading-7 text-zinc-400 lg:text-base">
                        Documento automatico que organiza cronologicamente eventos, acciones y errores de cada sesion. Aqui puedes revisar lo ocurrido y descargarlo en formato Excel.
                    </p>
                </div>
            </div>

            <div class="flex flex-col gap-4 lg:items-end">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="min-w-[110px] rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                        <p class="text-[9px] font-black uppercase tracking-[0.35em] text-zinc-500">Sesiones</p>
                        <p class="mt-2 text-2xl font-black">{{ $activityTotals['sesiones'] ?? (method_exists($sesionesConEstadisticas, 'total') ? $sesionesConEstadisticas->total() : count($sesionesConEstadisticas ?? [])) }}</p>
                    </div>
                    <div class="min-w-[110px] rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                        <p class="text-[9px] font-black uppercase tracking-[0.35em] text-zinc-500">Transcripciones</p>
                        <p class="mt-2 text-2xl font-black">{{ $activityTotals['transcripciones'] ?? collect($sesionesConEstadisticas ?? [])->sum('transcripciones_count') }}</p>
                    </div>
                    <div class="min-w-[110px] rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                        <p class="text-[9px] font-black uppercase tracking-[0.35em] text-zinc-500">Horas</p>
                        <p class="mt-2 text-2xl font-black">
                            {{ number_format(collect($sesionesConEstadisticas ?? [])->sum(fn ($item) => (float) ($item['duracion_horas'] ?? 0)), 2) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="activity-scroll mb-8 rounded-[2rem] border border-white/10 bg-zinc-900/40 p-5 backdrop-blur-sm lg:p-6">
            <form method="GET" action="{{ route('actividad.index') }}" class="grid grid-cols-1 gap-4 items-end lg:grid-cols-[1fr_auto]">
                <div>
                    <label for="q" class="mb-3 block text-[9px] font-black uppercase tracking-[0.35em] text-zinc-500">Buscar sesion, fecha, usuario o texto</label>
                    <input
                        id="q"
                        name="q"
                        value="{{ $q ?? '' }}"
                        type="text"
                        placeholder="Ej. reunion, 2026-04, cliente..."
                        class="w-full rounded-2xl border border-white/10 bg-black/40 px-5 py-4 text-sm text-white outline-none transition placeholder:text-zinc-600 focus:border-[#00d2ff]/50 focus:ring-2 focus:ring-[#00d2ff]/20"
                    >
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="rounded-2xl bg-[#00d2ff] px-5 py-4 text-[10px] font-black uppercase tracking-[0.35em] text-white transition hover:scale-[1.01]">
                        Buscar
                    </button>
                    @if(($q ?? '') !== '')
                        <a href="{{ route('actividad.index') }}" class="rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-[10px] font-black uppercase tracking-[0.35em] text-zinc-300 transition hover:border-white/25 hover:text-white">
                            Limpiar
                        </a>
                    @endif
                </div>
            </form>
        </div>

        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('actividad.export', array_filter(['q' => $q ?? null])) }}" class="group flex items-center gap-3 rounded-2xl border border-emerald-500/10 bg-emerald-500/5 px-6 py-3 shadow-xl transition-all duration-300 hover:border-emerald-500/40 hover:bg-emerald-500/10">
                    <div class="rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-2 transition-colors group-hover:bg-emerald-500/30">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <div class="flex flex-col text-left">
                        <span class="text-[10px] font-black uppercase tracking-widest text-emerald-500 italic">Generar archivo Excel</span>
                        <span class="text-[8px] font-bold uppercase tracking-tighter text-zinc-500">Exportar a Excel (.xlsx)</span>
                    </div>
                </a>

                @if(($q ?? '') !== '')
                    <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-[9px] font-black uppercase tracking-[0.3em] text-zinc-300">
                        Resultado para "{{ $q }}"
                    </span>
                @endif
            </div>

            <div class="text-right">
                <span class="block text-3xl font-light leading-none text-white italic">{{ method_exists($sesionesConEstadisticas, 'total') ? $sesionesConEstadisticas->total() : count($sesionesConEstadisticas ?? []) }}</span>
                <span class="text-[8px] font-black uppercase tracking-[0.3em] text-zinc-700">Registros indexados</span>
            </div>
        </div>

        <div class="space-y-4">
            @forelse($sesionesConEstadisticas ?? [] as $sesion)
                @php
                    $sesionModel = $sesion['sesion'] ?? null;
                    $isArchived = $sesionModel && method_exists($sesionModel, 'trashed') && $sesionModel->trashed();
                    $shortCode = $sesionModel?->short_code_formatted;
                    $accesoUrl = ($sesionModel && $sesionModel->short_code && ! $isArchived)
                        ? \App\Support\SpikiaUrl::public(route('sesion.short', ['code' => $sesionModel->short_code]))
                        : null;
                    $accesoQrSvg = $accesoUrl
                        ? \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(120)->margin(1)->generate($accesoUrl)
                        : null;
                @endphp
                <details class="group overflow-hidden rounded-[2rem] border border-white/10 bg-zinc-900/40 backdrop-blur-sm">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5 transition hover:bg-white/[0.02] lg:px-8">
                        <div class="flex min-w-0 items-center gap-4">
                            <span class="shrink-0 rounded-full border px-3 py-1 text-[9px] font-black uppercase tracking-[0.2em] {{ $isArchived ? 'border-zinc-500/30 bg-zinc-500/10 text-zinc-400' : 'border-[#00d2ff]/20 bg-[#00d2ff]/10 text-[#00d2ff]' }}">
                                {{ $isArchived ? 'Archivada' : 'Sesión vinculada' }}
                            </span>
                            <div class="min-w-0">
                                <h2 class="truncate text-lg font-black italic uppercase tracking-tight text-white">{{ $sesion['titulo'] }}</h2>
                                <p class="mt-0.5 text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                                    {{ $sesion['fecha'] ?? 'Sin fecha' }} · {{ $sesion['transcripciones_count'] ?? 0 }} transcripciones
                                </p>
                            </div>
                        </div>
                        <svg class="h-5 w-5 shrink-0 text-zinc-500 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </summary>

                    <div class="flex flex-col border-t border-white/5 xl:flex-row xl:items-stretch">
                        <div class="bg-white/[0.02] p-6 xl:w-[34%] xl:border-r xl:border-white/5 lg:p-8">
                            <div class="mt-4 space-y-3 text-sm text-zinc-400">
                                <div class="flex items-center gap-2">
                                    <span class="min-w-[72px] text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Usuario</span>
                                    <span class="font-medium text-zinc-300">{{ $sesion['presentador'] ?? 'Usuario' }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="min-w-[72px] text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Fecha</span>
                                    <span>{{ $sesion['fecha'] ?? 'Sin fecha' }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="min-w-[72px] text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Horario</span>
                                    <span>{{ $sesion['hora_inicio'] ?? '--:--' }} a {{ $sesion['hora_fin'] ?? '--:--' }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="min-w-[72px] text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Idiomas</span>
                                    <span>{{ $sesion['idiomas'] ?? 'Sin idiomas' }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="min-w-[72px] text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Duracion</span>
                                    <span>{{ number_format((float) ($sesion['duracion_horas'] ?? 0), 2) }} h</span>
                                </div>
                            </div>

                            @if($accesoQrSvg)
                                <div class="mt-6 flex items-center gap-4 rounded-2xl border border-white/5 bg-black/30 p-4">
                                    <div class="h-[120px] w-[120px] shrink-0 overflow-hidden rounded-xl bg-white p-2 [&_svg]:block [&_svg]:h-full [&_svg]:w-full">
                                        {!! $accesoQrSvg !!}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-[9px] font-black uppercase tracking-[0.3em] text-zinc-500">Volver a compartir</p>
                                        @if($shortCode)
                                            <p class="mt-1 text-base font-black tracking-[0.1em] text-white">{{ $shortCode }}</p>
                                        @endif
                                        <a href="{{ $accesoUrl }}" target="_blank" class="mt-2 inline-block text-[9px] font-black uppercase tracking-[0.25em] text-[#00d2ff] hover:text-white transition">Abrir acceso</a>
                                    </div>
                                </div>
                            @elseif($shortCode)
                                <p class="mt-6 text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Código {{ $shortCode }} (sesión archivada, ya no escaneable)</p>
                            @endif
                        </div>

                        <div class="xl:flex-1 p-6 lg:p-8 flex flex-col gap-6">
                            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <p class="text-[9px] font-black uppercase tracking-[0.35em] text-zinc-500">Ultimo texto registrado</p>
                                    <p class="mt-2 max-w-4xl text-sm leading-7 text-zinc-200">
                                        {{ !empty($sesion['ultimo_texto']) ? \Illuminate\Support\Str::limit($sesion['ultimo_texto'], 260) : 'Todavia no hay texto capturado para esta sesion.' }}
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                                <div class="rounded-2xl border border-white/5 bg-white/[0.03] px-4 py-4">
                                    <p class="text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Sesion</p>
                                    <p class="mt-2 text-lg font-black text-white">{{ $sesion['titulo'] }}</p>
                                </div>
                                <div class="rounded-2xl border border-white/5 bg-white/[0.03] px-4 py-4">
                                    <p class="text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Fecha</p>
                                    <p class="mt-2 text-lg font-black text-white">{{ $sesion['fecha'] ?? 'N/A' }}</p>
                                </div>
                                <div class="rounded-2xl border border-white/5 bg-white/[0.03] px-4 py-4">
                                    <p class="text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Idiomas</p>
                                    <p class="mt-2 text-lg font-black text-white">{{ $sesion['idiomas'] ?? 'Sin idiomas' }}</p>
                                </div>
                                <div class="rounded-2xl border border-white/5 bg-white/[0.03] px-4 py-4">
                                    <p class="text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Transcripciones</p>
                                    <p class="mt-2 text-lg font-black text-white">{{ $sesion['transcripciones_count'] ?? 0 }}</p>
                                </div>
                            </div>

                            @if(($sesion['extension_count'] ?? 0) > 0)
                                <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                                    <div class="rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-4">
                                        <p class="text-[9px] font-black uppercase tracking-[0.3em] text-emerald-200">Tiempo extra</p>
                                        <p class="mt-2 text-lg font-black text-emerald-100">{{ number_format((float) ($sesion['extra_time_hours'] ?? 0), 2) }} h</p>
                                    </div>
                                    <div class="rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-4">
                                        <p class="text-[9px] font-black uppercase tracking-[0.3em] text-emerald-200">Extensiones</p>
                                        <p class="mt-2 text-lg font-black text-emerald-100">{{ $sesion['extension_count'] ?? 0 }}</p>
                                    </div>
                                    <div class="rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-4">
                                        <p class="text-[9px] font-black uppercase tracking-[0.3em] text-emerald-200">Ultima extension</p>
                                        <p class="mt-2 text-lg font-black text-emerald-100">
                                            {{ $sesion['last_extended_at'] ? \Illuminate\Support\Carbon::parse($sesion['last_extended_at'])->format('Y-m-d H:i') : 'N/A' }}
                                        </p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </details>
            @empty
                <div class="rounded-[2rem] border border-dashed border-white/10 bg-zinc-900/20 p-20 text-center">
                    <p class="text-[10px] font-black uppercase tracking-[0.4em] text-zinc-500">No hay actividad registrada</p>
                    @if(($q ?? '') !== '')
                        <p class="mt-4 text-sm text-zinc-500">No encontramos coincidencias para tu busqueda.</p>
                    @endif
                </div>
            @endforelse
        </div>

        @if(method_exists($sesionesConEstadisticas, 'hasPages') && $sesionesConEstadisticas->hasPages())
            <div class="mt-10 flex justify-center">
                <div class="rounded-2xl border border-white/10 bg-zinc-900/40 px-5 py-4">
                    {{ $sesionesConEstadisticas->onEachSide(1)->links() }}
                </div>
            </div>
        @endif

        <footer class="mt-20 flex flex-col items-center gap-6 opacity-30">
            <div class="h-[1px] w-40 bg-gradient-to-r from-transparent via-zinc-500 to-transparent"></div>
            <p class="text-[9px] font-black uppercase tracking-[0.8em] text-zinc-500">Spikia Control Interface v2.0</p>
        </footer>
    </div>
</div>
@endsection
