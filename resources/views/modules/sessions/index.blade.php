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
    $sttModels = $tsConfig['available_stt_models'] ?? [];
    $translationModels = $tsConfig['available_translation_models'] ?? [];
    $voices = $tsConfig['available_voices'] ?? [];
@endphp
<div class="min-h-screen bg-[#050505] text-white">
    <div class="spikia-page space-y-8">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6">
            <div class="space-y-4">
                <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 hover:text-white transition">
                    <span>&larr;</span> Volver al panel
                </a>
                <div class="flex items-center gap-5">
                    <img src="{{ asset('storage/media/images/spikia-25.png') }}" class="h-16 w-auto drop-shadow-[0_0_18px_rgba(124,58,237,0.35)]" alt="Spikia">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.4em] text-zinc-500">Sesiones privadas</p>
                        <h1 class="text-4xl font-black italic tracking-tighter uppercase">Panel de sesiones</h1>
                    </div>
                </div>
            </div>

            <button onclick="document.getElementById('createModal').style.display='block'" class="self-start rounded-2xl bg-white px-6 py-4 text-[10px] font-black uppercase tracking-[0.35em] text-black transition hover:bg-neonBlue hover:text-white">
                + Nueva sesión
            </button>
        </div>

        @php
            $publicBaseConfigured = config('spikia.public_base_url');
            $publicBaseEffective = rtrim(\App\Support\SpikiaUrl::public(url('/')), '/');
        @endphp
        <div class="rounded-2xl border border-white/10 bg-white/[0.03] px-5 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <p class="text-[9px] font-black uppercase tracking-[0.35em] text-zinc-500">URL publica (QR / movil)</p>
                <p class="mt-1 text-sm font-mono text-white break-all">{{ $publicBaseEffective }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if(empty($publicBaseConfigured))
                    <span class="rounded-full bg-amber-400/10 border border-amber-400/30 px-3 py-1 text-[9px] font-black uppercase tracking-[0.3em] text-amber-200">Detectada automaticamente</span>
                @else
                    <span class="rounded-full bg-emerald-400/10 border border-emerald-400/30 px-3 py-1 text-[9px] font-black uppercase tracking-[0.3em] text-emerald-200">Fijada en .env</span>
                @endif
                <button type="button" onclick="copyToClipboard('{{ $publicBaseEffective }}')" class="rounded-xl border border-white/10 px-3 py-2 text-[9px] font-black uppercase tracking-[0.3em] text-zinc-400 hover:text-white transition">
                    Copiar
                </button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
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

        <div class="rounded-[2rem] border border-white/10 bg-zinc-950/70 overflow-hidden shadow-2xl">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1120px] text-left border-collapse">
                    <thead class="bg-black/70">
                        <tr>
                            <th class="px-6 py-5 text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">QR</th>
                            <th class="px-6 py-5 text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Sesión</th>
                            <th class="px-6 py-5 text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Usuario</th>
                            <th class="px-6 py-5 text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Contenido</th>
                            <th class="px-6 py-5 text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Horario</th>
                            <th class="px-6 py-5 text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 text-right">Gestión</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sesiones as $s)
                            @php
                                $urlTransmision = SpikiaUrl::public(route('sesion.transmision', ['slug' => $s->slug]));
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
                            @endphp
                            <tr class="border-t border-white/5 hover:bg-white/[0.02] transition"
                                @if($scheduledEndAt && empty($s->demo_expires_at))
                                    data-session-end-at="{{ $scheduledEndAt->toIso8601String() }}"
                                @endif
                                @if($extensionDeadline)
                                    data-extension-deadline-at="{{ $extensionDeadline->toIso8601String() }}"
                                @endif>
                                <td class="px-6 py-6 align-top">
                                    <div class="inline-flex flex-col items-center gap-4">
                                        <div id="qr-wrap-{{ $s->slug }}" class="w-[190px] aspect-square rounded-[1.5rem] bg-white p-4 shadow-[0_12px_28px_rgba(0,0,0,0.20)] ring-1 ring-black/5 overflow-hidden flex items-center justify-center [&_svg]:block [&_svg]:w-full [&_svg]:h-full [&_svg]:max-w-full [&_svg]:max-h-full">
                                            {!! $qrSvg !!}
                                        </div>
                                        <div class="space-y-2 text-center">
                                            <a href="{{ $urlTransmisionLocal }}" target="_blank" class="block text-[9px] font-black uppercase tracking-[0.25em] text-zinc-400 hover:text-white transition">
                                                Abrir transmisión
                                            </a>
                                            <a href="{{ $urlMasterLocal }}" target="_blank" class="block text-[9px] font-black uppercase tracking-[0.25em] text-zinc-400 hover:text-white transition">
                                                Abrir master
                                            </a>
                                            <a href="{{ $urlSubtitulosLocal }}" target="_blank" class="block text-[9px] font-black uppercase tracking-[0.25em] text-emerald-300 hover:text-white transition" title="Pantalla de solo subtitulos, para superponer en OBS/vMix">
                                                Abrir subtítulos
                                            </a>
                                            @if(config('spikia.features.sign_avatar') && $s->has_sign_avatar)
                                                <a href="{{ $urlAvatarLocal }}" target="_blank" class="block text-[9px] font-black uppercase tracking-[0.25em] text-fuchsia-300 hover:text-white transition" title="Demostración visual, no es interpretación real de Lengua de Señas">
                                                    Avatar 3D (demo)
                                                </a>
                                                @if($s->avatar_mode === 'human_live')
                                                    <a href="{{ $urlInterpreteLocal }}" target="_blank" class="block text-[9px] font-black uppercase tracking-[0.25em] text-amber-300 hover:text-white transition" title="Vista previa local de camara, sin envio real a los oyentes todavia">
                                                        Intérprete (experimental)
                                                    </a>
                                                @endif
                                            @endif
                                            <button type="button" onclick="downloadQrPng('{{ $s->slug }}', '{{ $urlTransmision }}')" class="block text-[9px] font-black uppercase tracking-[0.25em] text-neonBlue hover:text-white transition">
                                                Descargar ZIP
                                            </button>
                                        </div>
                                    </div>
                                    <button type="button" onclick="copyToClipboard('{{ $urlTransmision }}')" class="mx-auto mt-3 block text-[9px] font-black uppercase tracking-[0.25em] text-zinc-500 hover:text-neonBlue transition">
                                        Copiar enlace
                                    </button>
                                </td>
                                <td class="px-6 py-6 align-top">
                                    <h2 class="text-2xl font-black uppercase italic tracking-tight">{{ $s->titulo }}</h2>
                                    <p class="mt-2 text-[10px] font-black uppercase tracking-[0.3em] text-zinc-600">
                                        Glosario: {{ $s->glosario?->titulo ?? 'Estándar' }}
                                    </p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <span class="inline-flex items-center rounded-full border border-cyan-400/20 bg-cyan-400/10 px-3 py-1 text-[9px] font-black uppercase tracking-[0.25em] text-cyan-200">
                                            {{ ($sessionTranslation['translation_mode'] ?? 'voice_to_voice') === 'voice_to_voice' ? 'Modo voz a voz' : 'Modo voz a texto' }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[9px] font-black uppercase tracking-[0.25em] text-zinc-300">
                                            IA {{ $sessionTranslation['translation_model'] ?? ($tsConfig['translation_model'] ?? 'gpt-5.4-mini') }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full border border-amber-400/20 bg-amber-400/10 px-3 py-1 text-[9px] font-black uppercase tracking-[0.25em] text-amber-200">
                                            {{ ($sessionTranslation['audio_delivery_mode'] ?? 'ultra_fast') === 'premium' ? 'Audio premium' : 'Audio ultra rapido' }}
                                        </span>
                                    </div>
                                    <div class="mt-4 flex flex-wrap gap-3">
                                        <a href="{{ $urlMasterLocal }}" class="inline-flex min-w-[104px] items-center justify-center rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-[9px] font-black uppercase tracking-[0.3em] hover:border-spikiaPurple/50 hover:text-white transition">
                                            Master
                                        </a>
                                        <a href="{{ $urlTransmisionLocal }}" class="inline-flex min-w-[104px] items-center justify-center rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-[9px] font-black uppercase tracking-[0.3em] hover:border-neonBlue/50 hover:text-white transition">
                                            Transmisión
                                        </a>
                                    </div>
                                </td>
                                <td class="px-6 py-6 align-top">
                                    <p class="font-bold text-white">{{ $ownerName }}</p>
                                    <p class="mt-1 text-[10px] text-zinc-500">{{ $ownerEmail }}</p>
                                </td>
                                <td class="px-6 py-6 align-top">
                                    <div class="rounded-2xl border border-white/5 bg-black/30 px-4 py-3">
                                        <p class="text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500">Transcripciones</p>
                                        <p class="mt-2 text-2xl font-black">{{ $s->transcripciones_count ?? 0 }}</p>
                                    </div>
                                </td>
                                <td class="px-6 py-6 align-top">
                                    <p class="text-[11px] font-black uppercase tracking-[0.25em] text-zinc-500">
                                        {{ $s->fecha_inicio ?? 'Sin fecha' }}
                                    </p>
                                    <div class="mt-3 grid gap-3">
                                        <div class="rounded-2xl border border-white/5 bg-white/[0.03] px-4 py-3">
                                            <p class="text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Inicio</p>
                                            <p class="mt-1 text-xl font-black text-white">{{ $s->hora_inicio ?? '--:--' }}</p>
                                        </div>
                                        <div class="rounded-2xl border border-white/5 bg-white/[0.03] px-4 py-3">
                                            <p class="text-[9px] font-black uppercase tracking-[0.3em] text-zinc-600">Fin</p>
                                            <p class="mt-1 text-xl font-black text-neonBlue">{{ $s->hora_fin ?? '--:--' }}</p>
                                        </div>
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
                                    </div>
                                </td>
                                <td class="px-6 py-6 align-top text-right">
                                    <div class="flex flex-col items-end gap-3">
                                        <a href="{{ route('sesiones.edit', $s->id) }}" class="inline-flex w-[136px] items-center justify-center rounded-xl border border-white/10 px-4 py-2 text-[9px] font-black uppercase tracking-[0.25em] text-neonBlue hover:text-white hover:border-neonBlue/40 transition">
                                            Configuración
                                        </a>
                                        <form action="{{ route('sesiones.destroy', $s->id) }}" method="POST" onsubmit="return confirm('¿Estás seguro de eliminar esta sesión?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex w-[136px] items-center justify-center rounded-xl border border-white/10 px-4 py-2 text-[9px] font-black uppercase tracking-[0.3em] text-red-400 hover:text-red-300 transition">
                                                Eliminar
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-20 text-center text-[10px] font-black uppercase tracking-[0.4em] text-zinc-600">
                                    No hay sesiones disponibles
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if(method_exists($sesiones, 'hasPages') && $sesiones->hasPages())
            <div class="flex justify-center">
                <div class="rounded-[1.75rem] border border-white/10 bg-zinc-950/70 px-5 py-4 shadow-2xl">
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
                            <p class="mt-2 text-sm text-zinc-400">Define aquí el modo de traducción y los modelos de IA de esta sesión. `Master` usará esta configuración y `transmisión` recibirá el resultado en tiempo real.</p>
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
                                <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-2">Modelo STT</label>
                                <select name="speech_to_text_model" class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-white outline-none focus:border-neonBlue">
                                    @foreach($sttModels as $model)
                                        <option value="{{ $model['value'] }}" {{ ($model['value'] ?? '') === ($tsConfig['speech_to_text_model'] ?? '') ? 'selected' : '' }}>{{ $model['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500 mb-2">Modelo de traducción</label>
                                <select name="translation_model" class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-white outline-none focus:border-neonBlue">
                                    @foreach($translationModels as $model)
                                        <option value="{{ $model['value'] }}" {{ ($model['value'] ?? '') === ($tsConfig['translation_model'] ?? '') ? 'selected' : '' }}>{{ $model['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
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
                                    <span class="block text-xs text-zinc-400">Activa un avatar de prueba solo para esta sala. Es una demostración visual del pipeline, no reemplaza un intérprete de Lengua de Señas certificado. Si no lo marcas, no se carga ningún recurso extra en la transmisión.</span>
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
