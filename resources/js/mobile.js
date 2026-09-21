const config = window.__SPIKIA_MOBILE__;

if (config) {
    document.addEventListener('DOMContentLoaded', () => {
        const viewInfo = document.getElementById('view-info');
        const viewLangs = document.getElementById('view-langs');
        const showLanguagesBtn = document.getElementById('show-languages-btn');
        const backBtn = document.getElementById('back-btn');
        const langButtons = document.querySelectorAll('[data-mobile-lang]');
        const streamBaseUrl = config.streamBaseUrl;

        function showLanguages() {
            if (viewInfo) viewInfo.style.display = 'none';
            if (viewLangs) viewLangs.style.display = 'block';
        }

        function goToStream(lang) {
            localStorage.setItem('spikia_mobile_lang', lang);
            window.location.href = streamBaseUrl;
        }

        if (showLanguagesBtn) {
            showLanguagesBtn.addEventListener('click', showLanguages);
        }

        if (backBtn) {
            backBtn.addEventListener('click', () => window.location.reload());
        }

        langButtons.forEach((button) => {
            button.addEventListener('click', () => goToStream(button.dataset.mobileLang));
        });
    });
}
