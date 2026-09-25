/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: ['./index.html', './src/**/*.{ts,tsx}'],
    theme: {
        extend: {
            // Mismos tokens de marca que el resto de Spikia (ver tailwind.config.js de la
            // app principal en Laravel) - el Control Room de escritorio tiene que verse
            // como parte de la misma familia visual, no como una app aparte.
            colors: {
                spikiaPurple: '#7C3AED',
                neonBlue: '#4ffcff',
                neonPink: '#ff2fa0',
            },
            fontFamily: {
                sans: ['Figtree', 'ui-sans-serif', 'system-ui', 'sans-serif'],
            },
            boxShadow: {
                glow: '0 0 40px rgba(124,58,237,0.35)',
            },
        },
    },
    plugins: [],
};
