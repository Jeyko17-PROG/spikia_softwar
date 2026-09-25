@php
    // El host (Master) SI puede activar otra sesion; el oyente (Transmision/Movil/Reunion)
    // no tiene panel propio, asi que su mensaje debe pedirle un codigo nuevo en vez de
    // decirle que "active otra sesion", algo que no puede hacer desde ahi.
    $isHost = $isHost ?? false;
    $expiredMessage = $isHost
        ? 'Se acabo el tiempo del demo, por favor activa otra sesion.'
        : 'Se acabo el tiempo de esta demo. Pedile al organizador un codigo nuevo.';

    $fallbackExpiresAt = session('spikia.demo_expires_at.' . ($sesion?->slug ?? ''));
    $demoExpiresAt = $sesion?->demo_expires_at ?? ($fallbackExpiresAt ? \Illuminate\Support\Carbon::parse($fallbackExpiresAt) : null);
    $isExpired = (bool) ($demoExpiresAt && now()->greaterThanOrEqualTo($demoExpiresAt));
    $remaining = $demoExpiresAt ? max(0, now()->diffInMinutes($demoExpiresAt, false)) : null;
@endphp

@if($demoExpiresAt)
    <div class="mb-4 rounded-2xl border px-4 py-2.5 text-[12px] font-medium {{ $isExpired ? 'border-red-500/30 bg-red-500/10 text-red-100' : 'border-amber-400/20 bg-amber-400/10 text-amber-100' }}"
        data-demo-banner
        data-demo-expires-at="{{ $demoExpiresAt?->toIso8601String() }}">
        @if($isExpired)
            {{ $expiredMessage }}
        @else
            Demo activa. Tiempo restante: <span data-demo-countdown>{{ $remaining }} minutos</span>.
        @endif
    </div>
    <script>
        (() => {
            const banner = document.querySelector('[data-demo-banner][data-demo-expires-at="{{ $demoExpiresAt?->toIso8601String() }}"]');
            const label = banner?.querySelector('[data-demo-countdown]');
            const expiresAt = Date.parse(banner?.dataset.demoExpiresAt || '');
            if (!banner || !label || Number.isNaN(expiresAt)) return;

            let timer = null;
            const tick = () => {
                const remaining = Math.max(0, expiresAt - Date.now());
                const minutes = Math.floor(remaining / 60000);
                const seconds = Math.floor((remaining % 60000) / 1000);
                label.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                if (remaining <= 0) {
                    banner.className = 'mb-4 rounded-2xl border px-4 py-2.5 text-[12px] font-medium border-red-500/30 bg-red-500/10 text-red-100';
                    banner.textContent = @json($expiredMessage);
                    if (timer) clearInterval(timer);
                }
            };

            tick();
            timer = setInterval(tick, 1000);
        })();
    </script>
@endif
