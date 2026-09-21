@extends('layouts.spikia')

@section('title', 'Portada')

@section('content')
<div class="min-h-screen bg-black text-white flex items-center justify-center px-6 py-12">
    <div class="max-w-xl w-full rounded-[2rem] border border-white/10 bg-zinc-950/90 p-10 text-center shadow-2xl">
        <img src="{{ asset('storage/media/images/spikia-15.png') }}" alt="Spikia" class="h-20 w-auto mx-auto mb-6">
        <h1 class="text-3xl font-black uppercase tracking-tight">Portada del sistema</h1>
        <p class="mt-4 text-zinc-400 leading-6">Usa el dashboard para navegar por sesiones, transcripciones, glosarios y soporte.</p>
        <a href="{{ route('dashboard') }}" class="inline-flex mt-8 px-5 py-3 rounded-2xl bg-white text-black font-black uppercase tracking-widest text-xs">Ir al dashboard</a>
    </div>
</div>
@endsection
