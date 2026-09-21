import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            colors: {
                spikiaPurple: '#7C3AED',
                neonBlue: '#4ffcff',
                neonPink: '#ff2fa0',
            },
            boxShadow: {
                glow: '0 0 40px rgba(124,58,237,0.8)',
            },
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            animation: {
                glow: 'glow 2s ease-in-out infinite alternate',
                spinSlow: 'spin 8s linear infinite',
            },
            keyframes: {
                glow: {
                    '0%': { boxShadow: '0 0 15px #7C3AED' },
                    '50%': { boxShadow: '0 0 35px #ff2fa0' },
                    '100%': { boxShadow: '0 0 55px #4ffcff' },
                },
            },
        },
    },

    plugins: [forms],
};
