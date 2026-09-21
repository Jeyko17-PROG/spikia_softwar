import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/activity.css',
                'resources/css/dashboard.css',
                'resources/css/glossary.css',
                'resources/css/support.css',
                'resources/css/sessions-edit.css',
                'resources/css/sessions-index.css',
                'resources/css/sessions-live.css',
                'resources/css/sessions-mobile.css',
                'resources/js/app.js',
                'resources/js/dashboard.js',
                'resources/js/glossary.js',
                'resources/js/listener.js',
                'resources/js/master.js',
                'resources/js/mobile.js',
                'resources/js/subtitulos.js',
                'resources/js/support.js',
                'resources/js/sessions-index.js',
                'resources/js/traduccion-simultanea.js',
                'resources/js/avatar-engine.js',
                'resources/js/avatar-video-player.js',
                'resources/js/avatar-interprete-broadcaster.js',
                'resources/js/avatar-interprete-viewer.js',
            ],
            refresh: true,
        }),
    ],
});
