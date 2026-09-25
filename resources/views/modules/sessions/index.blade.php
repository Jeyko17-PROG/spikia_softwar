@extends('layouts.spikia')
@php use App\Support\SpikiaUrl; @endphp

@push('styles')
@vite('resources/css/sessions-index.css')
@endpush

@push('head-scripts')
@vite('resources/js/sessions-index.js')
@endpush

@section('content')
@php
    $tsConfig = config('spikia.translation_simultaneous', []);
    $voices = $tsConfig['available_voices'] ?? [];
@endphp
<div class="min-h-screen bg-[#050505] text-white">
    <div class="spikia-page space-y-8">
        <div class="flex justify-center">
            <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="h-20 w-auto opacity-90 transition-opacity hover:opacity-100" alt="Spikia">
        </div>

        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6">
            <div class="space-y-4">
                <a href="{{ route('dashboard') }}" class="group flex h-12 w-12 items-center justify-center rounded-2xl border border-white/10 bg-white/5 shadow-lg transition-all hover:border-[#00d2ff] hover:bg-[#00d2ff]">
                    <svg class="h-5 w-5 text-zinc-400 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.4em] text-zinc-500">Sesiones privadas</p>
                    <h1 class="text-4xl font-black italic tracking-tighter uppercase">Panel de sesiones</h1>
                </div>
            </div>

            <button onclick="document.getElementById('createModal').style.display='block'" class="self-start rounded-2xl bg-white px-6 py-4 text-[10px] font-black uppercase tracking-[0.35em] text-black transition hover:bg-neonBlue hover:text-white">
                + Nueva sesión
            </button>
        </div>

        {{-- Antes había un bloque acá arriba mostrando solo el dominio base de la app
        ("URL pública (QR/móvil)") con su propio botón "Copiar" - un dato técnico de
        diagnóstico, no un link a nada en particular, que se pisaba visualmente con el
        "Copiar enlace" de cada sesión (ese sí es el link real y compartible). Se saca de
        acá; cada sesión ya tiene su propio enlace y QR en su tarjeta desplegada. --}}

        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div class="rounded-[1.8rem] border border-white/10 bg-white/5 p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500">Sesiones</p>
                <p class="mt-3 text-3xl font-black">{{ $resumenContenido['sesiones'] ?? 0 }}</p>
            </div>
            <div class="rounded-[1.8rem] border border-white/10 bg-white/5 p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500">Glosarios</p>
                <p class="mt-3 text-3xl font-black">{{ $resumenContenido['glosarios'] ?? 0 }}</p>
            </div>
            <div class="rounded-[1.8rem] border border-white/10 bg-white/5 p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500">Transcripciones</p>
                <p class="mt-3 text-3xl font-black">{{ $resumenContenido['transcripciones'] ?? 0 }}</p>
            </div>
            <div class="rounded-[1.8rem] border border-white/10 bg-white/5 p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500">Videos</p>
                <p class="mt-3 text-3xl font-black">{{ $resumenContenido['videos'] ?? 0 }}</p>
            </div>
        </div>

        <div class="rounded-[2rem] border border-white/10 bg-zinc-900/40 overflow-hidden backdrop-blur-sm divide-y divide-white/5">
            @forelse($sesiones as $s)
                @php
                    $urlTransmision = SpikiaUrl::public(route('sesion.transmision', ['slug' => $s->slug]));
                    $urlCorto = $s->short_code ? SpikiaUrl::public(route('sesion.short', ['code' => $s->short_code])) : $urlTransmision;
                    $urlTransmisionLocal = route('sesion.transmision', ['slug' => $s->slug]);
                    $urlMasterLocal = route('sesion.master', ['slug' => $s->slug]);
                    $urlSubtitulosLocal = route('sesion.subtitulos', ['slug' => $s->slug]);
                    $urlAvatarLocal = route('sesion.avatar', ['slug' => $s->slug]);
                    $urlInterpreteLocal = route('sesion.interprete', ['slug' => $s->slug]);
                    $ownerName = $s->user?->name ?? auth()->user()->name;
                    $ownerEmail = $s->user?->email ?? auth()->user()->email;
                    $qrSvg = $qrSvgs[$s->id] ?? null;
                    $sessionTranslation = array_merge([
                        'translation_mode' => 'voice_to_voice',
                        'translation_model' => $tsConfig['translation_model'] ?? 'gpt-5.4-mini',
                        'voice' => $tsConfig['voice'] ?? 'marin',
                        'audio_delivery_mode' => $tsConfig['audio_delivery_mode'] ?? 'ultra_fast',
                    ], is_array($s->translation_settings ?? null) ? $s->translation_settings : []);
                    $scheduledEndAt = $s->scheduledEndAt();
                    $extensionDeadline = $s->extension_grace_expires_at;

                    $statusLabel = 'Activa';
                    $statusClass = 'border-emerald-400/30 bg-emerald-400/10 text-emerald-200';
                    if (! empty($s->demo_expires_at)) {
                        $statusLabel = $s->demo_expired ? 'Demo vencida' : 'Demo activa';
                        $statusClass = $s->demo_expired ? 'border-red-400/30 bg-red-400/10 text-red-200' : 'border-amber-400/30 bg-amber-400/10 text-amber-200';
                    } elseif ($s->can_extend_now) {
                        $statusLabel = 'Vencida';
                        $statusClass = 'border-red-400/30 bg-red-400/10 text-red-200';
                    }
                @endphp
                <details class="group" id="sesion-row-{{ $s->slug }}"
                    @if(request('activada') === $s->slug) open @endif
                    @if($scheduledEndAt && empty($s->demo_expires_at))
                        data-session-end-at="{{ $scheduledEndAt->toIso8601String() }}"
                    @endif
                    @if($extensionDeadline)
                        data-extension-deadline-at="{{ $extensionDeadline->toIso8601String() }}"
                    @endif>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5 transition hover:bg-white/[0.02]">
                        <div class="flex min-w-0 items-center gap-4">
                            <span class="shrink-0 rounded-full border px-3 py-1 text-[9px] font-black uppercase tracking-[0.2em] {{ $statusClass }}">{{ $statusLabel }}</span>
                            <div class="min-w-0">
                                <h2 class="truncate text-lg font-black uppercase italic tracking-tight text-white">{{ $s->titulo }}</h2>
                                <p class="mt-0.5 text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                                    {{ $s->fecha_inicio ?? 'Sin fecha' }} · {{ $s->hora_inicio ?? '--:--' }}@if($s->hora_fin) - {{ $s->hora_fin }}@endif
                                    · {{ $ownerName }}
                                </p>
                            </div>
                        </div>
                        <svg class="h-5 w-5 shrink-0 text-zinc-500 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </summary>

                    <div class="grid grid-cols-1 gap-8 border-t border-white/5 bg-black/20 px-6 py-8 lg:grid-cols-[190px_1fr_260px]">
                        <div class="flex flex-col items-center gap-4">
                            <div id="qr-wrap-{{ $s->slug }}" class="w-[190px] aspect-square rounded-[1.5rem] bg-white p-4 shadow-[0_12px_28px_rgba(0,0,0,0.20)] ring-1 ring-black/5 overflow-hidden flex items-center justify-center [&_svg]:block [&_svg]:w-full [&_svg]:h-full [&_svg]:max-w-full [&_svg]:max-h-full">
                                {!! $qrSvg !!}
                            </div>
                            @if($s->short_code)
                                <p class="text-lg font-black tracking-[0.15em] text-white">{{ $s->short_code_formatted }}</p>
                            @endif
                            <p class="text-center text-[8px] font-bold uppercase tracking-[0.2em] text-zinc-600">Codigo/QR de acceso para tu publico</p>
                        </div>

                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.3em] text-zinc-600">
                                Glosario: {{ $s->glosario?->titulo ?? 'Estándar' }}
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span class="inline-flex items-center rounded-full border border-amber-400/20 bg-amber-400/10 px-3 py-1 text-[9px] font-black uppercase tracking-[0.25em] text-amber-200">
                                    {{ ($sessionTranslation['audio_delivery_mode'] ?? 'ultra_fast') === 'premium' ? 'Audio premium' : 'Audio ultra rapido' }}
                                </span>
                            </div>
                            @php
                                $actionCardClass = 'flex flex-col gap-0.5 rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-left transition hover:bg-white/[0.08]';
                            @endphp
                            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <a href="{{ $urlMasterLocal }}" target="_blank" class="{{ $actionCardClass }} hover:border-[#00d2ff]/50">
                                    <span class="text-[10px] font-black uppercase tracking-[0.3em] text-white">Master</span>
                                    <span class="text-[9px] font-bold text-zinc-500">Vos hablás y controlás la sesión desde acá</span>
                                </a>
                                <a href="{{ $urlTransmisionLocal }}" target="_blank" class="{{ $actionCardClass }} hover:border-neonBlue/50">
                                    <span class="text-[10px] font-black uppercase tracking-[0.3em] text-white">Traducción</span>
                                    <span class="text-[9px] font-bold text-zinc-500">Lo que ve y escucha tu público</span>
                                </a>
                                <a href="{{ $urlSubtitulosLocal }}" target="_blank" class="{{ $actionCardClass }} hover:border-emerald-400/50">
                                    <span class="text-[10px] font-black uppercase tracking-[0.3em] text-emerald-300">Subtítulos</span>
                                    <span class="text-[9px] font-bold text-zinc-500">Para proyectar solo el texto, ej. en pantalla u OBS</span>
                                </a>
                                <button type="button" onclick="downloadQrPng('{{ $s->slug }}', '{{ $urlCorto }}', '{{ $s->short_code_formatted }}')" class="{{ $actionCardClass }} hover:border-neonBlue/50">
                                    <span class="text-[10px] font-black uppercase tracking-[0.3em] text-neonBlue">Descargar QR</span>
                                    <span class="text-[9px] font-bold text-zinc-500">Listo para imprimir</span>
                                </button>
                                <button type="button" onclick="copyToClipboard('{{ $urlCorto }}')" class="{{ $actionCardClass }} hover:border-white/30">
                                    <span class="text-[10px] font-black uppercase tracking-[0.3em] text-white">Copiar enlace</span>
                                    <span class="text-[9px] font-bold text-zinc-500">Para compartir sin el QR</span>
                                </button>
                                @if(config('spikia.features.sign_avatar') && $s->has_sign_avatar)
                                    <a href="{{ $urlAvatarLocal }}" target="_blank" class="{{ $actionCardClass }} hover:border-fuchsia-400/50">
                                        <span class="text-[10px] font-black uppercase tracking-[0.3em] text-fuchsia-300">Avatar 3D (demo)</span>
                                        <span class="text-[9px] font-bold text-zinc-500">Demostración visual, no es LSE real</span>
                                    </a>
                                    @if($s->avatar_mode === 'human_live')
                                        <a href="{{ $urlInterpreteLocal }}" target="_blank" class="{{ $actionCardClass }} hover:border-amber-400/50">
                                            <span class="text-[10px] font-black uppercase tracking-[0.3em] text-amber-300">Intérprete (experimental)</span>
                                            <span class="text-[9px] font-bold text-zinc-500">Vista previa local de cámara</span>
                                        </a>
                                    @endif
                                @endif
                            </div>
                            <div class="mt-6 rounded-2xl border border-white/5 bg-black/30 px-4 py-3 inline-flex items-center gap-3">
                                <span class="text-[9px] font-black uppercase tracking-[0.3em] text-zinc-500">Transcripciones</span>
                                <span class="text-lg font-black text-white">{{ $s->transcripciones_count ?? 0 }}</span>
                            </div>
                            <p class="mt-4 text-[10px] text-zinc-600">{{ $ownerEmail }}</p>
                        </div>

                        <div class="grid gap-3 content-start">
                            @if(!empty($s->demo_expires_at))
                                <div class="rounded-2xl border border-amber-400/20 bg-amber-400/10 px-4 py-3">
                                    <p class="text-[9px] font-black uppercase tracking-[0.3em] text-amber-200">Demo</p>
                                    <p class="mt-1 text-[11px] font-bold text-amber-100">
                                        {{ $s->demo_expired ? 'Demo vencida' : 'Activa por ' . ($s->demo_remaining_minutes ?? 0) . ' min' }}
                                    </p>
                                </div>
                            @endif
                            @if(($s->extra_time_minutes ?? 0) > 0)
                                <div class="rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3">
                                    <p class="text-[9px] font-black uppercase tracking-[0.3em] text-emerald-200">Tiempo extra</p>
                                    <p class="mt-1 text-[11px] font-bold text-emerald-100">
                                        {{ (int) (($s->extra_time_minutes ?? 0) / 60) }} h agregadas / {{ $s->extension_count ?? 0 }} extension(es)
                                    </p>
                                </div>
                            @endif
                            @if($s->can_extend_now)
                                <form action="{{ route('sesiones.extend-time', $s->id) }}" method="POST" class="rounded-2xl border border-red-400/30 bg-red-400/10 px-4 py-3 text-left">
                                    @csrf
                                    <p class="text-[9px] font-black uppercase tracking-[0.3em] text-red-200">
                                        Sesion vencida
                                    </p>
                                    <p class="mt-1 text-[11px] font-bold text-red-100">
                                        Tiempo para extender: <span data-extension-countdown="{{ $extensionDeadline?->toIso8601String() }}">10:00</span>
                                    </p>
                                    <div class="mt-3 flex gap-2">
                                        @foreach([1, 2, 3] as $hours)
                                            <button name="extra_hours" value="{{ $hours }}" class="rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-[9px] font-black uppercase tracking-[0.2em] text-white hover:bg-neonBlue hover:text-black">
                                                +{{ $hours }}h
                                            </button>
                                        @endforeach
                                    </div>
                                </form>
                            @endif
                            <a href="{{ route('sesiones.edit', $s->id) }}" class="inline-flex items-center justify-center rounded-xl border border-white/10 px-4 py-2 text-[9px] font-black uppercase tracking-[0.25em] text-neonBlue hover:text-white hover:border-neonBlue/40 transition">
                                Configuración de la sesión
                            </a>
                            <form action="{{ route('sesiones.destroy', $s->id) }}" method="POST" onsubmit="return spikiaConfirmSubmit(event, '¿Estás seguro de eliminar esta sesión? Esta acción no se puede deshacer.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="w-full inline-flex items-center justify-center rounded-xl border border-white/10 px-4 py-2 text-[9px] font-black uppercase tracking-[0.3em] text-red-400 hover:text-red-300 transition">
                                    Eliminar sesión
                                </button>
                            </form>
                        </div>
                    </div>
                </details>
            @empty
                <p class="px-6 py-20 text-center text-[10px] font-black uppercase tracking-[0.4em] text-zinc-600">
                    No hay sesiones disponibles
                </p>
            @endforelse
        </div>

        @if(method_exists($sesiones, 'hasPages') && $sesiones->hasPages())
            <div class="flex justify-center">
                <div class="rounded-2xl border border-white/10 bg-zinc-900/40 px-5 py-4">
                    {{ $sesiones->onEachSide(1)->links() }}
                </div>
            </div>
        @endif

        <div id="createModal" class="fixed inset-0 z-50 hidden bg-black/90 backdrop-blur-md">
            <div class="absolute inset-0" onclick="document.getElementById('createModal').style.display='none'"></div>
            <div class="relative mx-auto mt-10 w-[92%] max-w-2xl rounded-[2rem] border border-white/10 bg-zinc-950 p-8 shadow-2xl max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between gap-4 mb-6">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Nueva sesión</p>
                        <h2 class="text-3xl font-black italic tracking-tighter">Crear sesión</h2>
                    </div>
                    <button type="button" onclick="document.getElementById('createModal').style.display='none'" class="text-zinc-500 hover:text-white transition">X</button>
                </div>

                <form action="{{ route('sesiones.store') }}" method="POST" class="space-y-5">
                    @csrf
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-2">Título</label>
                        <input type="text" name="titulo" placeholder="Ej: Congreso de Medicina" required class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-white outline-none focus:border-neonBlue">
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-2">Fecha</label>
                            <input type="date" name="fecha_inicio" required class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-white outline-none focus:border-neonBlue">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-2">Hora de inicio</label>
                            <input type="time" name="hora_inicio" required class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-white outline-none focus:border-neonBlue">
                        </div>
                    </div>
                    <div>
                        <div class="mb-2 flex items-center gap-2">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-white/10 bg-white/5 text-[10px]">O</span>
                            <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Hora de término</label>
                        </div>
                        <input type="time" name="hora_fin" class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-white outline-none focus:border-neonBlue">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-2">Glosario</label>
                        <select name="glosario_id" class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-white outline-none focus:border-neonBlue">
                            <option value="">Ninguno (Voz estándar)</option>
                            @foreach($glosarios as $g)
                                <option value="{{ $g->id }}">{{ $g->titulo }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-3">Idiomas de traducción</label>
                        <div class="grid gap-3 md:grid-cols-2 rounded-[1.5rem] border border-white/10 bg-white/5 p-4">
                            <label class="flex items-center gap-3 text-sm text-zinc-300"><input type="checkbox" name="idiomas[]" value="es-ES"> Español España</label>
                            <label class="flex items-center gap-3 text-sm text-zinc-300"><input type="checkbox" name="idiomas[]" value="es-419"> Español LatAm</label>
                            <label class="flex items-center gap-3 text-sm text-zinc-300"><input type="checkbox" name="idiomas[]" value="en"> Inglés</label>
                            <label class="flex items-center gap-3 text-sm text-zinc-300"><input type="checkbox" name="idiomas[]" value="fr"> Francés</label>
                            <label class="flex items-center gap-3 text-sm text-zinc-300"><input type="checkbox" name="idiomas[]" value="pt"> Portugués</label>
                            <label class="flex items-center gap-3 text-sm text-zinc-300"><input type="checkbox" name="idiomas[]" value="de"> Alemán</label>
                            <label class="flex items-center gap-3 text-sm text-zinc-300"><input type="checkbox" name="idiomas[]" value="it"> Italiano</label>
                        </div>
                    </div>
                    <div class="rounded-[1.5rem] border border-cyan-400/15 bg-cyan-400/5 p-5 space-y-5">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.35em] text-cyan-300">Configuración de traducción</p>
                            <p class="mt-2 text-sm text-zinc-400">Define aquí el modo de traducción y los modelos de IA de esta sesión. `Master` usará esta configuración y `Traducción` recibirá el resultado en tiempo real.</p>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-3">Modo de traducción</label>
                            <div class="grid gap-3 md:grid-cols-2">
                                <label class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4 text-sm text-zinc-200">
                                    <input type="radio" name="translation_mode" value="voice_to_text" class="mr-3"> Voz a texto
                                </label>
                                <label class="rounded-2xl border border-cyan-400/30 bg-cyan-400/10 px-4 py-4 text-sm font-bold text-cyan-100">
                                    <input type="radio" name="translation_mode" value="voice_to_voice" checked class="mr-3"> Voz a voz
                                </label>
                            </div>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-2">Modelo de voz</label>
                                <select name="text_to_speech_model" class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-white outline-none focus:border-neonBlue">
                                    <option value="{{ $tsConfig['text_to_speech_model'] ?? 'gpt-4o-mini-tts' }}">{{ $tsConfig['text_to_speech_model'] ?? 'gpt-4o-mini-tts' }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-2">Voz</label>
                                <select name="voice" class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-white outline-none focus:border-neonBlue">
                                    @foreach($voices as $voice)
                                        <option value="{{ $voice }}" {{ $voice === ($tsConfig['voice'] ?? '') ? 'selected' : '' }}>{{ $voice }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-2">Prioridad de audio</label>
                                <select name="audio_delivery_mode" class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-white outline-none focus:border-neonBlue">
                                    @foreach(($tsConfig['available_audio_delivery_modes'] ?? []) as $mode)
                                        <option value="{{ $mode['value'] }}" {{ ($mode['value'] ?? '') === ($tsConfig['audio_delivery_mode'] ?? '') ? 'selected' : '' }}>{{ $mode['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-2">Prompt maestro</label>
                            <textarea name="master_translation_prompt" rows="4" class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-white outline-none focus:border-neonBlue">{{ $tsConfig['master_translation_prompt'] ?? '' }}</textarea>
                        </div>
                    </div>
                    <div class="rounded-[1.5rem] border border-white/10 bg-white/5 p-5 space-y-3">
                        <div>
                            <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-zinc-500">Enlace de la reunión a traducir (Zoom / Google Meet) — opcional</label>
                            <input type="url" name="zoom_link" placeholder="https://meet.google.com/... o https://zoom.us/j/..." class="w-full rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-xs text-zinc-200">
                            <p class="mt-2 text-[11px] text-zinc-400">Pégalo aquí. Desde el panel Master podrás abrirlo con un clic y compartir el audio de esa pestaña para traducirlo en vivo (modo "Pestaña / Zoom / Meet")@if(config('spikia.features.meeting_bot')), o pedirle al bot de Spikia que entre solo a esa reunión @endif.</p>
                        </div>
                        @if(config('spikia.features.meeting_bot'))
                            <div>
                                <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-zinc-500">Idioma que se habla en esa reunión (para el bot)</label>
                                <select name="meeting_bot_source_lang" class="w-full rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-xs text-zinc-200">
                                    @foreach(config('spikia.master_languages', []) as $language)
                                        <option value="{{ $language['id'] }}" {{ $language['id'] === 'es-ES' ? 'selected' : '' }}>{{ $language['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                    @if(config('spikia.features.sign_avatar'))
                        <div class="rounded-[1.5rem] border border-fuchsia-400/15 bg-fuchsia-400/5 p-5" x-data="{ avatarMode: '3d' }">
                            <label class="flex items-center gap-3 text-sm text-zinc-200">
                                <input type="checkbox" name="has_sign_avatar" value="1" class="h-4 w-4">
                                <span>
                                    <span class="block font-bold">Avatar 3D — demo (no es interpretación real de LSE)</span>
                                    <span class="block text-xs text-zinc-400">Activa un avatar de prueba solo para esta sala. Es una demostración visual del pipeline, no reemplaza un intérprete de Lengua de Señas certificado. Si no lo marcas, no se carga ningún recurso extra en la traducción.</span>
                                </span>
                            </label>

                            <div class="mt-4 grid gap-2 sm:grid-cols-3">
                                <label class="flex items-center gap-2 rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-zinc-300">
                                    <input type="radio" name="avatar_mode" value="3d" x-model="avatarMode" checked> Avatar 3D
                                </label>
                                <label class="flex items-center gap-2 rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-zinc-300">
                                    <input type="radio" name="avatar_mode" value="video" x-model="avatarMode"> Video pregrabado
                                </label>
                                <label class="flex items-center gap-2 rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-zinc-300">
                                    <input type="radio" name="avatar_mode" value="human_live" x-model="avatarMode"> Intérprete en vivo (experimental)
                                </label>
                            </div>

                            <div x-show="avatarMode === '3d'" class="mt-3">
                                <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-zinc-500">Personaje</label>
                                <select name="avatar_character" class="w-full rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-xs text-zinc-200">
                                    <option value="avatar_femenino">Avatar 1 (femenino)</option>
                                    <option value="avatar_masculino">Avatar 2 (masculino)</option>
                                </select>
                            </div>

                            <div x-show="avatarMode === 'video'" class="mt-3">
                                <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-zinc-500">URL del video</label>
                                <input type="url" name="avatar_video_url" placeholder="https://..." class="w-full rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-xs text-zinc-200">
                            </div>

                            <div x-show="avatarMode === 'human_live'" class="mt-3 rounded-xl border border-amber-400/20 bg-amber-400/5 px-3 py-2 text-[11px] text-amber-200">
                                Experimental: todavía no hay proveedor de video en tiempo real conectado (pendiente LiveKit/Agora). Una vez creada la sala, abre "Intérprete" desde el panel para la vista previa local de cámara.
                            </div>
                        </div>
                    @endif
                    <button type="submit" class="w-full rounded-2xl bg-white px-6 py-4 text-[10px] font-black uppercase tracking-[0.35em] text-black transition hover:bg-neonBlue hover:text-white">
                        Crear sesión
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    (() => {
        const activada = new URLSearchParams(window.location.search).get('activada');
        if (activada) {
            const row = document.getElementById('sesion-row-' + activada);
            if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        const reloadOnce = (delay) => window.setTimeout(() => window.location.reload(), Math.max(1000, delay));

        document.querySelectorAll('[data-session-end-at]').forEach((row) => {
            if (row.dataset.extensionDeadlineAt) {
                return;
            }

            const endAt = Date.parse(row.dataset.sessionEndAt || '');
            if (Number.isNaN(endAt)) {
                return;
            }

            const delay = endAt - Date.now() + 500;
            if (delay > 0 && delay < 86400000) {
                reloadOnce(delay);
            }
        });

        document.querySelectorAll('[data-extension-countdown]').forEach((label) => {
            const deadline = Date.parse(label.dataset.extensionCountdown || '');
            if (Number.isNaN(deadline)) {
                return;
            }

            const tick = () => {
                const remainingSeconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
                const m = Math.floor(remainingSeconds / 60);
                const s = remainingSeconds % 60;
                label.textContent = `${m}:${String(s).padStart(2, '0')}`;
                if (remainingSeconds <= 0) {
                    window.location.reload();
                }
            };

            tick();
            window.setInterval(tick, 500);
        });
    })();
</script>

@endsection
