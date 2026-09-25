import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// Base relativa ("./"): imprescindible para Electron/Tauri, que sirven el build empaquetado
// desde file:// o un esquema custom, no desde la raiz "/" de un dominio como una SPA web
// normal - con base "/" los assets no cargarian dentro del ejecutable.
export default defineConfig({
    base: './',
    plugins: [react()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './src'),
        },
    },
    server: {
        port: 5183,
    },
});
