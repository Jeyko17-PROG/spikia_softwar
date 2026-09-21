@use('SimpleSoftwareIO\QrCode\Facades\QrCode')
@extends('layouts.spikia')

@section('content')
<div class="flex flex-col min-h-screen bg-black text-white font-sans">
    {{-- HEADER ESTILO MASTER --}}
    <header class="relative z-10 p-8 flex justify-between items-start">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_50%_-20%,#1e1b4b,transparent)] opacity-50"></div>
        <div class="relative z-20">
            <h2 class="text-4xl font-extralight tracking-tighter mb-2 text-white italic">
                Spikia <span class="font-black not-italic text-transparent bg-clip-text bg-gradient-to-r from-white via-zinc-400 to-zinc-600">Sessions Panel</span>
            </h2>
            <p class="text-zinc-500 font-bold text-[12px] tracking-widest uppercase italic">Listado de conferencias activas</p>
        </div>
        <div class="relative z-20">
            <a href="{{ route('sesiones.index') }}" class="px-6 py-3 bg-zinc-900 border border-white/10 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:border-spikiaPurple/50 transition-all">
                + Crear Nueva Sesion
            </a>
        </div>
    </header>

    {{-- LISTADO DE SESIONES --}}
    <main class="relative z-10 flex-1 p-8 pt-0">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @forelse($sesiones as $item)
                @php
                    $urlAcceso = route('sesion.reunion', ['slug' => $item->slug]);
                @endphp
                <div class="group relative bg-zinc-950 border border-white/5 rounded-[2.5rem] p-6 hover:border-spikiaPurple/40 transition-all duration-500 shadow-2xl overflow-hidden">
                    {{-- AURA DE FONDO --}}
                    <div class="absolute -right-10 -top-10 w-32 h-32 bg-spikiaPurple/5 blur-3xl group-hover:bg-spikiaPurple/15 transition-all"></div>
                    
                    <div class="flex flex-col gap-6">
                        <div class="flex items-center gap-4">
                            <div class="p-4 bg-white rounded-3xl shadow-lg group-hover:scale-105 transition-transform">
                                {!! QrCode::size(80)->margin(0)->generate($urlAcceso) !!}
                            </div>
                            <div>
                                <span class="text-[9px] font-black text-spikiaPurple uppercase tracking-[0.2em]">ID: #{{ $item->id }}</span>
                                <h3 class="text-xl font-bold uppercase italic tracking-tighter leading-tight">{{ $item->titulo }}</h3>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <a href="{{ route('sesion.master', $item->slug) }}" class="flex items-center justify-center w-full py-4 bg-zinc-900 border border-white/5 group-hover:border-spikiaPurple/50 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all hover:bg-spikiaPurple hover:text-white">
                                ENTRAR AL MASTER CONTROL
                            </a>
                            
                            <form action="{{ route('sesiones.destroy', $item->id) }}" method="POST" onsubmit="return confirm('Eliminar sesionA')">
                                @csrf @method('DELETE')
                                <button type="submit" class="w-full text-[8px] font-bold text-zinc-600 hover:text-red-500 transition-colors uppercase tracking-[0.3em]">
                                    Eliminar Registro
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full py-20 text-center bg-zinc-950/50 border border-dashed border-white/10 rounded-[3rem]">
                    <p class="text-zinc-600 uppercase font-black tracking-[0.4em] italic">No hay señales de transmision activas</p>
                </div>
            @endforelse
        </div>
    </main>
</div>
@endsection
