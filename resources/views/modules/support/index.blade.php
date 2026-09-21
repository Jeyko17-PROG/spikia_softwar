@extends('layouts.spikia')

@section('title', 'Soporte | Spikia')

@push('styles')
@vite('resources/css/support.css')
@endpush

@push('head-scripts')
@vite('resources/js/support.js')
@endpush

@section('content')
<div class="min-h-screen support-glow text-white">
    <div class="spikia-page">
        @if(Storage::disk('public')->exists('media/images/spikia-15.png'))
            <div class="mb-8 flex justify-center">
                <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="h-16 w-auto opacity-90 transition-opacity hover:opacity-100" alt="Spikia">
            </div>
        @endif

        <div class="mb-10 flex items-center gap-4">
            <a href="{{ route('dashboard') }}" class="group flex h-12 w-12 items-center justify-center rounded-2xl border border-white/10 bg-white/5 shadow-lg transition-all hover:border-[#00d2ff] hover:bg-[#00d2ff]">
                <svg class="h-5 w-5 text-zinc-400 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <h1 class="text-4xl font-black italic uppercase leading-none tracking-tighter">
                    Centro de <span class="text-[#00d2ff]">soporte</span>
                </h1>
                <p class="mt-2 text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500 italic">
                    Ayuda rapida y asistente local para resolver dudas frecuentes
                </p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                @if(session('support_status'))
                    <div class="rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-5 py-4 text-sm font-bold text-emerald-100">
                        {{ session('support_status') }}
                    </div>
                @endif
                @if($errors->any())
                    <div class="rounded-2xl border border-red-400/20 bg-red-400/10 px-5 py-4 text-sm font-bold text-red-100">
                        Revisa los campos del formulario de soporte.
                    </div>
                @endif

                <div class="rounded-[2rem] border border-white/10 bg-zinc-900/40 p-8 backdrop-blur-sm shadow-[0_25px_80px_rgba(0,0,0,0.32)]">
                    <h2 class="mb-4 text-xl font-bold">Asistente de soporte</h2>
                    <p class="text-sm leading-6 text-zinc-300">
                        Este chat responde de forma local con guias sobre creditos, demo, sesiones, glosarios, actividad y exportacion.
                    </p>

                    <div class="mt-6">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Preguntas rapidas</p>
                            <div id="supportMicStatus" class="text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500">Micrófono</div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach($quickQuestions as $question)
                                <button type="button" data-message="{{ $question }}" class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-[10px] font-black uppercase tracking-[0.25em] text-zinc-300 transition hover:border-[#00d2ff]/40 hover:text-white">
                                    {{ $question }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div id="chatBox" class="support-chat-box mt-6 max-h-[420px] space-y-4 overflow-y-auto rounded-[1.75rem] border border-white/5 bg-black/30 p-4">
                        @foreach($messages as $message)
                            <div class="{{ ($message['role'] ?? 'assistant') === 'user' ? 'flex justify-end' : 'flex justify-start' }}">
                                <div class="{{ ($message['role'] ?? 'assistant') === 'user' ? 'border-[#00d2ff]/20 bg-[#00d2ff]/15 text-white' : 'border-white/10 bg-white/5 text-zinc-200' }} max-w-[85%] rounded-2xl border px-4 py-3 text-sm leading-6">
                                    {{ $message['text'] ?? '' }}
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <form id="supportChatForm" data-chat-url="{{ route('soporte.chat') }}" data-csrf="{{ csrf_token() }}" class="mt-5 space-y-4">
                        @csrf
                        <div>
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <label for="message" class="block text-[10px] font-black uppercase tracking-[0.35em] text-zinc-500">Tu mensaje</label>
                                <button id="supportMicBtn" type="button" class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-2 text-[10px] font-black uppercase tracking-[0.25em] text-zinc-300 transition hover:border-[#00d2ff]/40 hover:text-white">
                                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-[#00d2ff]"></span>
                                    <span>Micrófono</span>
                                </button>
                            </div>
                            <textarea
                                id="message"
                                name="message"
                                rows="3"
                                class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-sm text-white outline-none transition focus:border-[#00d2ff]"
                                placeholder="Ej: Como activo la licencia?"
                                required
                            ></textarea>
                        </div>

                        <button type="submit" class="spikia-action bg-white text-black hover:bg-neonBlue hover:text-white">
                            Enviar al asistente
                        </button>
                    </form>
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-[2rem] border border-white/10 bg-zinc-900/40 p-8 backdrop-blur-sm">
                    <h2 class="mb-4 text-xl font-bold">Accesos utiles</h2>
                    <div class="space-y-3 text-sm">
                        <a href="{{ route('dashboard') }}" class="block rounded-xl bg-white/5 px-4 py-3 transition hover:bg-white/10">Ir al panel</a>
                        <a href="{{ route('sesiones.index') }}" class="block rounded-xl bg-white/5 px-4 py-3 transition hover:bg-white/10">Gestionar sesiones</a>
                        <a href="{{ route('transcripciones.listado') }}" class="block rounded-xl bg-white/5 px-4 py-3 transition hover:bg-white/10">Ver transcripciones</a>
                        <a href="{{ route('glosarios') }}" class="block rounded-xl bg-white/5 px-4 py-3 transition hover:bg-white/10">Abrir glosarios</a>
                        <a href="{{ route('actividad.pin') }}" class="block rounded-xl bg-white/5 px-4 py-3 transition hover:bg-white/10">Ver actividad</a>
                    </div>
                </div>

                <div class="rounded-[2rem] border border-white/10 bg-zinc-900/40 p-8 backdrop-blur-sm">
                    <h2 class="mb-4 text-xl font-bold">Guia rapida</h2>
                    <ul class="space-y-3 text-sm leading-6 text-zinc-300">
                        <li>• La licencia agrega tokens al usuario actual.</li>
                        <li>• La demo crea una sesion con Español e Inglés por 20 minutos.</li>
                        <li>• La actividad se descarga como archivo Excel.</li>
                        <li>• El glosario permite usar temas preconfigurados y editar terminos.</li>
                    </ul>
                </div>

                <div class="rounded-[2rem] border border-white/10 bg-zinc-900/40 p-8 backdrop-blur-sm">
                    <h2 class="mb-4 text-xl font-bold">Contactar soporte</h2>
                    <p class="mb-5 text-sm leading-6 text-zinc-300">
                        Si las respuestas rapidas no solucionan el caso, envia un mensaje directo al equipo de soporte.
                    </p>
                    <form method="POST" action="{{ route('soporte.contact') }}" class="space-y-4">
                        @csrf
                        <div>
                            <label for="support_name" class="mb-2 block text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500">Nombre</label>
                            <input id="support_name" name="name" value="{{ old('name', auth()->user()->name ?? '') }}" required class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-3 text-sm text-white outline-none focus:border-neonBlue">
                        </div>
                        <div>
                            <label for="support_email" class="mb-2 block text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500">Correo de respuesta</label>
                            <input id="support_email" name="email" type="email" value="{{ old('email', auth()->user()->email ?? '') }}" required class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-3 text-sm text-white outline-none focus:border-neonBlue">
                        </div>
                        <div>
                            <label for="support_message" class="mb-2 block text-[10px] font-black uppercase tracking-[0.3em] text-zinc-500">Mensaje</label>
                            <textarea id="support_message" name="message" rows="5" required class="w-full rounded-2xl border border-white/10 bg-black/60 px-5 py-4 text-sm text-white outline-none focus:border-neonBlue" placeholder="Describe el problema que no resolvio el asistente.">{{ old('message') }}</textarea>
                        </div>
                        <button type="submit" class="spikia-action bg-neonBlue text-black hover:bg-white">
                            Enviar correo
                        </button>
                    </form>
                    <p class="mt-4 text-[10px] text-zinc-500">Destino: soporte.spikia@plataforma.com.co</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
