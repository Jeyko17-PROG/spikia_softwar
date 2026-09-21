const btn = document.getElementById('hamburgerBtn');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('overlay');
const openLicenseModalBtn = document.getElementById('openLicenseModal');
const licenseModal = document.getElementById('licenseModal');
const creditsBadge = document.getElementById('creditsBadge');
const dashboardConfig = window.__SPIKIA_DASHBOARD__ || {};
let dashboardAudioContext = null;

const playCoinSound = () => {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;

    if (!AudioContextClass) {
        return;
    }

    if (!dashboardAudioContext) {
        dashboardAudioContext = new AudioContextClass();
    }

    if (dashboardAudioContext.state === 'suspended') {
        dashboardAudioContext.resume();
    }

    const notes = [
        { frequency: 784, duration: 0.07, gain: 0.045 },
        { frequency: 988, duration: 0.06, gain: 0.05 },
        { frequency: 1318, duration: 0.09, gain: 0.055 },
    ];

    let offset = 0.02;

    notes.forEach((note) => {
        const startAt = dashboardAudioContext.currentTime + offset;
        const oscillator = dashboardAudioContext.createOscillator();
        const gainNode = dashboardAudioContext.createGain();

        oscillator.type = 'triangle';
        oscillator.frequency.setValueAtTime(note.frequency, startAt);
        oscillator.frequency.exponentialRampToValueAtTime(note.frequency * 1.12, startAt + note.duration);

        gainNode.gain.setValueAtTime(0.0001, startAt);
        gainNode.gain.exponentialRampToValueAtTime(note.gain, startAt + 0.01);
        gainNode.gain.exponentialRampToValueAtTime(0.0001, startAt + note.duration);

        oscillator.connect(gainNode);
        gainNode.connect(dashboardAudioContext.destination);

        oscillator.start(startAt);
        oscillator.stop(startAt + note.duration + 0.03);

        offset += note.duration * 0.72;
    });
};

const animateCoin = () => {
    if (!creditsBadge) {
        return;
    }

    creditsBadge.classList.add('dashboard-credits-bump');
    window.setTimeout(() => creditsBadge.classList.remove('dashboard-credits-bump'), 420);
};

if (btn && sidebar && overlay) {
    const openMenu = () => {
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
    };

    const closeMenu = () => {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
    };

    btn.addEventListener('click', () => {
        const isOpen = !sidebar.classList.contains('-translate-x-full');
        if (isOpen) {
            closeMenu();
            return;
        }

        openMenu();
    });

    overlay.addEventListener('click', closeMenu);
}

if (openLicenseModalBtn && licenseModal) {
    const closeButtons = licenseModal.querySelectorAll('[data-close-license-modal]');

    const openModal = () => {
        licenseModal.classList.remove('hidden');
        licenseModal.classList.add('flex');
    };

    const closeModal = () => {
        licenseModal.classList.add('hidden');
        licenseModal.classList.remove('flex');
    };

    openLicenseModalBtn.addEventListener('click', () => {
        playCoinSound();
        animateCoin();
        openModal();
    });
    licenseModal.querySelectorAll('input[type="radio"][name="plan"]').forEach((radio) => {
        radio.addEventListener('change', () => {
            playCoinSound();
            animateCoin();
        });
    });
    closeButtons.forEach((button) => button.addEventListener('click', closeModal));
    licenseModal.addEventListener('click', (event) => {
        if (event.target === licenseModal) {
            closeModal();
        }
    });
}

if (creditsBadge) {
    creditsBadge.addEventListener('click', () => {
        playCoinSound();
        animateCoin();
    });
}

const metricsUrl = dashboardConfig.metricsUrl;
const sessionsChart = document.getElementById('dashboardSessionsChart');
const sessionsMeta = document.getElementById('dashboardSessionsMeta');

function updateSessionsChart(items) {
    if (!sessionsChart || !Array.isArray(items) || !items.length) {
        return;
    }

    const maxCount = Math.max(1, ...items.map((item) => Number(item.count || 0)));
    const bars = sessionsChart.querySelectorAll('[data-month-bar]');
    bars.forEach((bar) => {
        const key = bar.getAttribute('data-month-bar');
        const item = items.find((entry) => entry.key === key);
        if (!item) {
            return;
        }

        const count = Number(item.count || 0);
        const height = Math.max(14, Math.min(100, (count / maxCount) * 100));
        bar.style.height = `${height}%`;

        const countLabel = sessionsChart.querySelector(`[data-month-count="${key}"]`);
        if (countLabel) {
            countLabel.textContent = String(count);
        }
    });

    if (sessionsMeta) {
        const total = items.reduce((sum, item) => sum + Number(item.count || 0), 0);
        sessionsMeta.textContent = `${total} usos en 6 meses`;
    }
}

async function refreshDashboardMetrics() {
    if (!metricsUrl) {
        return;
    }

    try {
        const response = await fetch(metricsUrl, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json();
        updateSessionsChart(payload.sessions || []);
    } catch (error) {
        //
    }
}

updateSessionsChart(dashboardConfig.sessionsChart || []);
if (metricsUrl) {
    window.setInterval(refreshDashboardMetrics, 20000);
}
