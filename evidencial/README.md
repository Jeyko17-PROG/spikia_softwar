# Carpeta evidencial

Este directorio sirve como evidencia y guía rápida para entender la estructura del repositorio y evitar confusiones sobre dónde está el backend y el frontend.

- **Backend (Laravel - PHP):** código servidor está en la raíz del proyecto bajo carpetas como `app/`, `routes/`, `config/`, `resources/views/`.
  - Ejemplos: `app/Models`, `routes/web.php`.

- **Frontend (Assets JS/CSS):** no hay una carpeta `frontend/` separada; los assets están en `resources/js` y se construyen con Vite.
  - Archivo principal: `resources/js/app.js`.
  - Comandos: `npm run dev` / `npm run build` (ver `package.json`).

- **Servicios Node (proyectos independientes):** hay microservicios o workers en carpetas separadas:
  - `meet-bot/` — worker Node que captura audio (ver `meet-bot/package.json`).
  - `spikia-socket/` — servidor socket (ver `spikia-socket/package.json`).

- **Cómo ejecutar (rápido):**

```bash
# Frontend (assets)
cd <repo-root>
npm install
npm run dev

# Backend (Laravel)
composer install
php artisan serve

# Servicios Node (en nuevas terminales)
cd meet-bot
npm install
npm start

cd ../spikia-socket
npm install
npm start
```

- **Recomendación:** si prefieres ver una separación clara en el árbol del repo, puedo:
  1. Añadir esta carpeta `evidencial/` (ya creada) con ejemplos (hecho).
  2. Crear un `README.md` en la raíz explicando la convención (opcional).
  3. Crear scripts `start-all` que arranquen backend + frontend + servicios en paralelo (opcional).

Dime cuál opción quieres que implemente: `readme-root`, `start-all` o `nada`.
