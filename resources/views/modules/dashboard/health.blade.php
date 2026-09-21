@extends('layouts.spikia')

@section('content')
<div class="min-h-screen bg-[#050505] text-white px-6 py-10">
    <div class="max-w-3xl mx-auto">
        <div class="flex items-center justify-between mb-8">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.4em] text-zinc-600">Estado del sistema</p>
                <h1 class="text-3xl font-black italic uppercase">Spikia Health Check</h1>
            </div>
            <div class="text-right">
                <p class="text-[9px] font-black uppercase tracking-[0.35em] text-zinc-500">Estado global</p>
                @if($allOk)
                    <p class="text-3xl font-black text-emerald-400">TODO OK</p>
                @else
                    <p class="text-3xl font-black text-amber-400">REVISAR</p>
                @endif
            </div>
        </div>

        <div class="space-y-3">
            @foreach($checks as $c)
                @php
                    $statusColor = match($c['status'] ?? '') {
                        'ok' => 'emerald',
                        'warning' => 'amber',
                        'error' => 'red',
                        default => 'zinc',
                    };
                    $statusLabel = match($c['status'] ?? '') {
                        'ok' => 'OK',
                        'warning' => 'AVISO',
                        'error' => 'ERROR',
                        default => '?',
                    };
                @endphp
                <div class="rounded-2xl border border-{{ $statusColor }}-400/20 bg-{{ $statusColor }}-400/5 px-5 py-4 flex items-start gap-4">
                    <span class="rounded-full bg-{{ $statusColor }}-400/20 text-{{ $statusColor }}-200 px-3 py-1 text-[10px] font-black uppercase tracking-widest min-w-[70px] text-center">
                        {{ $statusLabel }}
                    </span>
                    <div class="flex-1">
                        <p class="font-black text-white">{{ $c['name'] }}</p>
                        <p class="text-[12px] text-zinc-400 mt-1">{{ $c['detail'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-8 flex gap-4">
            <a href="{{ route('dashboard') }}" class="rounded-xl border border-white/10 px-4 py-2 text-[10px] font-black uppercase tracking-[0.3em] text-zinc-400 hover:text-white">
                Volver al panel
            </a>
            <button onclick="location.reload()" class="rounded-xl bg-neonBlue/20 border border-neonBlue/30 px-4 py-2 text-[10px] font-black uppercase tracking-[0.3em] text-neonBlue hover:bg-neonBlue/30">
                Refrescar checks
            </button>
        </div>
    </div>
</div>
@endsection
