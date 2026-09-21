@extends('layouts.spikia')
@php use App\Support\SpikiaUrl; @endphp

@section('title', 'Spikia - ' . $sesion->titulo)

@push('styles')
@vite('resources/css/sessions-mobile.css')
@endpush

@push('head-scripts')
<script>
    window.__SPIKIA_MOBILE__ = @json([
        'streamBaseUrl' => SpikiaUrl::public(route('sesion.transmision', ['slug' => $sesion->slug])),
    ]);
</script>
@vite('resources/js/mobile.js')
@endpush

@section('content')
<div class="mobile-container">
    <div class="mb-4">
        @include('modules.sessions.partials.demo-banner', ['sesion' => $sesion])
    </div>
    <div id="view-info" class="card">
        <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="logo" alt="Spikia">
        <h1>{{ $sesion->titulo }}</h1>
        <p>{{ $sesion->presentador }}<br>{{ $sesion->fecha_inicio }} | {{ $sesion->hora_inicio }}</p>
        <button id="show-languages-btn" class="btn-assist">ASISTIR</button>
    </div>

    <div id="view-langs" class="card" style="display: none;">
        <h2 style="margin-top: 0;">Selecciona tu idioma</h2>
        <p>Escoge el canal de audio para la interpretación en vivo.</p>
        <div style="display: grid; gap: 12px;">
            @foreach(config('spikia.listener_languages', []) as $language)
                <button class="btn-lang" data-mobile-lang="{{ $language['id'] }}">
                    {{ $language['label'] }}
                    <span class="dot"></span>
                </button>
            @endforeach
        </div>
        <button id="back-btn" style="background: none; border: none; color: #777; margin-top: 20px; cursor: pointer;">← Volver</button>
    </div>
</div>

@endsection
