@extends('layouts.spikia')

@section('title', 'Dashboard | Spikia')

@push('styles')
    @vite('resources/css/dashboard.css')
@endpush

@push('head-scripts')
    @vite('resources/js/dashboard.js')
@endpush

@section('content')
@php
    // MONEDA REALISTA, GRANDE Y CON SALTO (INTACTA)
    $coinIcon = <<<'SVG'
<svg viewBox="0 0 64 64" fill="none" aria-hidden="true" class="h-12 w-12 animate-bounce">
    <defs>
        <radialGradient id="coinGlow" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(22 20) rotate(45) scale(40 40)">
            <stop offset="0" stop-color="#fff3bf"></stop>
            <stop offset="0.45" stop-color="#f8c94d"></stop>
            <stop offset="1" stop-color="#d67f00"></stop>
        </radialGradient>
        <linearGradient id="coinEdge" x1="16" y1="16" x2="48" y2="48" gradientUnits="userSpaceOnUse">
            <stop stop-color="#fff0a8"></stop>
            <stop offset="1" stop-color="#8b4e00"></stop>
        </linearGradient>
    </defs>
    <circle cx="32" cy="32" r="24" fill="url(#coinGlow)" stroke="url(#coinEdge)" stroke-width="4"></circle>
    <circle cx="32" cy="32" r="16" fill="rgba(255,255,255,0.12)" stroke="#ffd86b" stroke-opacity="0.55" stroke-width="2"></circle>
    <path d="M28 29h8c3.8 0 7 3.1 7 7s-3.2 7-7 7h-1.5l2.8 5h-5l-2.2-5H28l1.8-4H36c1.6 0 3-1.4 3-3s-1.4-3-3-3h-8l-1.8-4Z" fill="rgba(122,67,0,0.9)"></path>
</svg>
SVG;

    $dashboardConfig = [
        'metricsUrl' => route('dashboard.metrics'),
        'sessionsChart' => $sessionsChart ?? [],
        'brandLogo' => asset('storage/media/images/spikia-15.png'),
    ];
@endphp

<audio id="marioDeathSound" src="https://www.myinstants.com/media/sounds/mario-dies.mp3" preload="auto"></audio>

<div id="dashboard-config" data-config="{{ json_encode($dashboardConfig) }}" class="hidden"></div>

<div class="flex min-h-screen text-white bg-zinc-900">
    
    <aside id="sidebar" class="fixed left-0 top-0 z-50 h-screen w-72 -translate-x-full overflow-y-auto border-r border-white/10 bg-zinc-950 px-5 py-6 shadow-2xl transition-transform duration-300">
        <div class="mb-6 flex justify-center">
            <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="h-20 w-auto object-contain" alt="Spikia">
        </div>
        <div class="mb-3 px-2">
            <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-500">Navegacion</h2>
        </div>
        <div class="space-y-1">
            <a href="{{ route('dashboard') }}" class="flex items-center justify-between rounded-xl px-4 py-3 transition-colors {{ request()->routeIs('dashboard') ? 'bg-white/10 text-white' : 'text-zinc-400 hover:bg-white/5 hover:text-white' }}">
                <span class="flex items-center gap-3">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h4a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1h4a1 1 0 001-1V10"/></svg>
                    Portada
                </span>
                <span class="text-zinc-600">›</span>
            </a>
            <a href="{{ route('sesiones.index') }}" class="flex items-center justify-between rounded-xl px-4 py-3 transition-colors {{ request()->routeIs('sesiones.*') ? 'bg-white/10 text-white' : 'text-zinc-400 hover:bg-white/5 hover:text-white' }}">
                <span class="flex items-center gap-3">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/></svg>
                    Sesiones
                </span>
                <span class="text-zinc-600">›</span>
            </a>
            <a href="{{ route('glosarios') }}" class="flex items-center justify-between rounded-xl px-4 py-3 transition-colors {{ request()->routeIs('glosarios*') ? 'bg-white/10 text-white' : 'text-zinc-400 hover:bg-white/5 hover:text-white' }}">
                <span class="flex items-center gap-3">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 19.5A2.5 2.5 0 016.5 17H20M4 19.5A2.5 2.5 0 006.5 22H20V4a1 1 0 00-1-1H6.5A2.5 2.5 0 004 5.5v14z"/></svg>
                    Glosario
                </span>
                <span class="text-zinc-600">›</span>
            </a>
            @if(auth()->user()->email === 'luisgarciab193@gmail.com')
                <a href="{{ route('actividad.index') }}" class="flex items-center justify-between rounded-xl px-4 py-3 transition-colors {{ request()->routeIs('actividad.*') ? 'bg-white/10 text-white' : 'text-zinc-400 hover:bg-white/5 hover:text-white' }}">
                    <span class="flex items-center gap-3">
                        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6M15 19v-10M12 19v-3M3 19h18M3 5l6 4 5-3 7 5"/></svg>
                        Actividad
                    </span>
                    <span class="text-zinc-600">›</span>
                </a>
            @endif
            <a href="{{ route('transcripciones.listado') }}" class="flex items-center justify-between rounded-xl px-4 py-3 transition-colors {{ request()->routeIs('transcripciones.*') ? 'bg-white/10 text-white' : 'text-zinc-400 hover:bg-white/5 hover:text-white' }}">
                <span class="flex items-center gap-3">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6M9 16h6M9 8h1M7 4h7l5 5v11a1 1 0 01-1 1H7a1 1 0 01-1-1V5a1 1 0 011-1z"/></svg>
                    Transcripciones
                </span>
                <span class="text-zinc-600">›</span>
            </a>
        </div>
        <div class="my-5 border-t border-white/10"></div>
        <div class="space-y-1">
            <a href="{{ route('soporte') }}" class="flex items-center justify-between rounded-xl px-4 py-3 text-zinc-400 transition-colors hover:bg-white/5 hover:text-white">
                <span class="flex items-center gap-3">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.5 9a2.5 2.5 0 115 .5c0 1.5-2.5 1.5-2.5 3.5M12 17h.01M12 21a9 9 0 100-18 9 9 0 000 18z"/></svg>
                    Soporte
                </span>
                <span class="text-zinc-600">›</span>
            </a>
            <form method="POST" id="logout-form" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="flex w-full items-center justify-between rounded-xl px-4 py-3 text-red-400 transition-colors hover:bg-red-500/10">
                    <span class="flex items-center gap-3">
                        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m0-8H5a2 2 0 00-2 2v12a2 2 0 002 2h2"/></svg>
                        Cerrar sesion
                    </span>
                    <span class="text-red-400/50">›</span>
                </button>
            </form>
        </div>
    </aside>

    <div id="overlay" class="fixed inset-0 z-40 hidden bg-black/50 backdrop-blur-sm"></div>

    <main class="min-h-screen flex-1 min-w-0">
        <div class="header-container relative flex flex-col gap-4 px-4 py-6 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
            <img src="{{ asset('storage/media/images/spikia-15.png') }}" class="pointer-events-none absolute left-1/2 top-1/2 hidden h-14 w-auto -translate-x-1/2 -translate-y-1/2 object-contain opacity-90 lg:block" alt="Spikia">

            <div class="flex items-center gap-4">
                <button id="hamburgerBtn" class="rounded-lg px-3 py-2 text-3xl text-white hover:bg-white/10 transition-colors">☰</button>
                <div>
                    <h1 class="text-2xl font-bold leading-none">Panel de control</h1>
                    <p class="text-sm text-zinc-400 mt-1">Bienvenido {{ explode(' ', auth()->user()->name)[0] }}</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-4 lg:gap-8">
                <div id="creditsBadge" class="flex items-center gap-4">
                    {!! $coinIcon !!}
                    <div>
                        <p class="text-[10px] uppercase tracking-widest text-zinc-500 leading-none mb-1">Creditos</p>
                        <p class="text-xl font-black {{ auth()->user()->hasUnlimitedCredits() ? 'text-emerald-400' : 'text-white' }}">
                            {{ auth()->user()->hasUnlimitedCredits() ? 'Ilimitados' : ($creditStats['remaining'] ?? 0) }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-full bg-zinc-800/50 p-1.5 pr-4 border border-white/5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-tr from-[#00d2ff] to-cyan-300 text-xs font-bold text-black">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </div>
                    <span class="text-sm font-medium hidden sm:inline">{{ auth()->user()->name }}</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 px-4 pb-8 sm:px-6 md:grid-cols-2 lg:grid-cols-3 lg:px-8">
            <section class="card-item rounded-3xl border border-white/5 bg-zinc-800/50 p-6 flex flex-col justify-between min-h-[160px]">
                <div>
                    <h3 class="font-bold text-lg text-zinc-100">Sesión rápida</h3>
                    <p class="mt-2 text-sm text-zinc-400">Empezá a traducir en minutos, sin configurar nada. Gratis por <b>20 minutos</b>; el tiempo corre desde que la activás, aunque no uses el micrófono todavía.</p>
                </div>
                <form id="demoForm" method="POST" action="{{ route('dashboard.demo.activate') }}">
                    @csrf
                    <button type="submit" class="mt-4 w-fit rounded-xl bg-[#00d2ff] px-6 py-2.5 text-xs font-bold text-black hover:bg-[#33dbff] transition-all">
                        Activar sesión rápida
                    </button>
                </form>
            </section>

            <section class="card-item rounded-3xl border border-white/5 bg-zinc-800/50 p-6 flex flex-col min-h-[160px]">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-lg text-zinc-100">Uso reciente</h3>
                    <span class="text-[9px] font-bold text-zinc-500 uppercase tracking-widest">Estadísticas</span>
                </div>
                <div class="flex-1 rounded-2xl bg-black/20 p-4 border border-white/5">
                    <div id="dashboardSessionsChart" class="flex h-28 items-end gap-2">
                        @foreach($sessionsChart as $month)
                            @php
                                $maxCount = max(1, collect($sessionsChart)->max('count') ?? 1);
                                $barHeight = max(14, min(100, (int) ((($month['count'] ?? 0) / $maxCount) * 100)));
                            @endphp
                            <div class="flex-1 flex flex-col items-center gap-1">
                                <span data-month-count="{{ $month['key'] }}" class="text-[10px] font-black text-white">{{ $month['count'] ?? 0 }}</span>
                                <div data-month-bar="{{ $month['key'] }}" class="w-full bg-[#00d2ff] rounded-t-sm transition-all duration-500 shadow-[0_0_16px_rgba(0,210,255,0.35)]" style="height: {{ $barHeight }}%"></div>
                                <span class="text-[8px] text-zinc-600 font-bold uppercase">{{ $month['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <p id="dashboardSessionsMeta" class="mt-3 text-[9px] font-bold uppercase tracking-[0.25em] text-zinc-500">
                        {{ collect($sessionsChart)->sum('count') }} usos en 6 meses
                    </p>
                </div>
            </section>

            <section class="card-item rounded-3xl border border-white/5 bg-zinc-800/50 p-6 flex flex-col justify-between min-h-[160px]">
                <div>
                    <div class="flex justify-between items-start mb-2">
                        <h3 class="font-bold text-lg text-zinc-100">Tu Plan</h3>
                        <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[9px] font-black uppercase tracking-widest text-zinc-300">
                            {{ strtoupper($currentPlan) }}
                        </span>
                    </div>
                    <p class="text-sm text-zinc-400 leading-tight">Activa tu licencia para obtener más tokens.</p>
                </div>
                <button type="button" id="openLicenseModal" class="mt-4 inline-flex w-fit items-center gap-1.5 text-xs font-bold text-[#00d2ff] hover:text-white transition-all">
                    Ver planes
                    <span aria-hidden="true">&rarr;</span>
                </button>
            </section>
        </div>
    </main>
</div>

<div id="licenseModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 px-4 py-8 backdrop-blur-sm">
    <div class="absolute inset-0" data-close-license-modal onclick="closeAndReset()"></div>
    <div class="relative w-full max-w-2xl overflow-hidden rounded-[2.5rem] border border-white/10 bg-zinc-900 shadow-2xl p-8">
        <h2 class="text-2xl font-black italic uppercase mb-6">Activar Licencia</h2>
        <form id="licenseForm" method="POST" action="{{ route('dashboard.license.activate') }}" class="space-y-6">
            @csrf
            <div id="plansGrid" class="grid gap-3">
                @foreach($licensePlans as $key => $plan)
                    <label class="cursor-pointer rounded-2xl border px-5 py-4 border-white/10 bg-white/5 hover:border-white/20 transition-all">
                        <div class="flex items-center gap-3">
                            <input type="radio" name="plan" value="{{ $key }}" class="accent-[#00d2ff]" onchange="handlePlanSelection('{{ $key }}')">
                            <div>
                                <p class="font-bold text-zinc-100">{{ $plan['label'] }}</p>
                                <p class="text-[10px] text-[#00d2ff] font-black uppercase">{{ $plan['credits'] }} TOKENS</p>
                            </div>
                        </div>
                    </label>
                @endforeach
            </div>

            <div id="dynamicContent" class="hidden rounded-2xl bg-black/30 p-6 border border-white/5"></div>

            <div class="flex justify-end gap-3 mt-4">
                <button type="button" class="text-zinc-500 text-sm" onclick="closeAndReset()">Cancelar</button>
                <button type="button" id="confirmLicenseBtn" onclick="validateStep()" disabled class="rounded-xl bg-[#00d2ff] px-8 py-2 font-bold text-sm text-black">Siguiente</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentStep = 1;
    let lastRequestTime = {}; 
    let stepTimer = null;
    let timeLeft = 10;
    let reselectionCount = 0; // CONTADOR DE REINTENTOS

    function closeAndReset() {
        const modal = document.getElementById('licenseModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        clearInterval(stepTimer);
        // Al cancelar, sumamos al contador para la próxima vez
        reselectionCount++;
    }

    function handlePlanSelection(plan) {
        currentStep = 1;
        clearInterval(stepTimer);
        const dynamicContent = document.getElementById('dynamicContent');
        const confirmBtn = document.getElementById('confirmLicenseBtn');
        const mario = document.getElementById('marioDeathSound');

        // SI ES LA SEGUNDA VEZ QUE ELIGEN (O MÁS) Y NO LLEGÓ EL CÓDIGO
        if (reselectionCount >= 2 && plan !== 'free') {
            mario.play();
        }

        dynamicContent.classList.remove('hidden');
        confirmBtn.disabled = false;
        confirmBtn.innerText = "Siguiente";

        if (plan === 'free') {
            dynamicContent.innerHTML = `<p class="text-emerald-400 italic text-sm text-center">Plan Free: Recibirás 100 tokens automáticamente.</p>`;
            confirmBtn.innerText = "Activar Ahora";
        } else {
            dynamicContent.innerHTML = `
                <div class="space-y-4">
                    <p class="text-[10px] font-bold text-[#00d2ff] uppercase tracking-widest text-center">Paso 1: Datos del Cliente</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <input type="text" id="u_name" name="name" placeholder="Tu nombre" required class="w-full bg-zinc-800 border border-white/10 rounded-lg px-3 py-2 text-sm outline-none">
                        <input type="email" id="u_email" name="email" placeholder="Correo para el código" required class="w-full bg-zinc-800 border border-white/10 rounded-lg px-3 py-2 text-sm outline-none">
                    </div>
                </div>`;
        }
    }

    function startCodeTimer() {
        timeLeft = 10;
        const display = document.getElementById('timer-count');
        const mario = document.getElementById('marioDeathSound');
        
        stepTimer = setInterval(() => {
            timeLeft--;
            if (display) display.innerText = timeLeft;
            if (timeLeft <= 0) {
                clearInterval(stepTimer);
                mario.play();
                alert('¡Se acabó el tiempo!');
            }
        }, 1000);
    }

    function validateStep() {
        const plan = document.querySelector('input[name="plan"]:checked').value;
        const confirmBtn = document.getElementById('confirmLicenseBtn');
        const mario = document.getElementById('marioDeathSound');

        if (plan === 'free') {
            document.getElementById('licenseForm').submit();
            return;
        }

        if (currentStep === 1) {
            const email = document.getElementById('u_email').value;
            const now = Date.now();

            if (lastRequestTime[email] && (now - lastRequestTime[email] < 15000)) {
                mario.play();
                alert('Espera 15 segundos.');
                return;
            }

            if (!document.getElementById('u_name').value || !email) {
                alert('Rellena los datos.');
                return;
            }

            lastRequestTime[email] = now;
            currentStep = 2;
            confirmBtn.innerText = "Activar Ahora";
            
            document.getElementById('dynamicContent').innerHTML = `
                <div class="space-y-4 text-center">
                    <p class="text-[10px] font-bold text-amber-500 uppercase tracking-widest">Paso 2: Código de Activación</p>
                    <input type="text" id="u_code" placeholder="Pega tu código aquí" class="w-full bg-zinc-800 border border-amber-500/50 rounded-lg px-4 py-3 text-center text-xl font-bold text-white outline-none">
                    <div class="mt-2 py-1 px-3 bg-red-500/10 rounded-lg border border-red-500/20 inline-block">
                        <p class="text-[11px] text-red-400 font-bold uppercase">Tiempo restante: <span id="timer-count">10</span>s</p>
                    </div>
                </div>`;
            startCodeTimer();
        } else {
            if (!document.getElementById('u_code').value) { alert('Inserta el código.'); return; }
            clearInterval(stepTimer);
            document.getElementById('licenseForm').submit();
        }
    }

    window.__SPIKIA_DASHBOARD__ = JSON.parse(document.getElementById('dashboard-config').dataset.config);
</script>
@endsection
