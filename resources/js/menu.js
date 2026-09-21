const btn = document.getElementById('hamburgerBtn');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('overlay');

function openMenu() {
    sidebar.classList.remove('-translate-x-full');
    overlay.classList.remove('hidden');
}

function closeMenu() {
    sidebar.classList.add('-translate-x-full');
    overlay.classList.add('hidden');
}

btn.addEventListener('click', () => {
    const isOpen = !sidebar.classList.contains('-translate-x-full');
    isOpen ? closeMenu() : openMenu();
});

overlay.addEventListener('click', closeMenu);