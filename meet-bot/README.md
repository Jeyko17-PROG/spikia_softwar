# spikia-meet-bot

Worker Node.js separado de Laravel. Entra a una reunión de Google Meet como invitado (usando
un navegador Chrome automatizado) y sube el audio capturado al endpoint de ingesta de Spikia
(`POST /sesiones/{slug}/bot-audio`).

No corre dentro de PHP: necesita un proceso persistente con Chrome instalado, algo que la
mayoría de hosting compartido no permite.

## Desplegar en Render (este proyecto usa Render)

Render **no** detecta ni levanta este worker solo porque el código llegó al repo — hay que
crear un servicio nuevo a mano, aparte del servicio web de Laravel:

1. En el dashboard de Render: **New +** → **Web Service** (o **Private Service** si tu plan lo
   ofrece — así no queda accesible desde internet, solo desde otros servicios del mismo
   proyecto/red privada de Render. Si no aparece esa opción, usa Web Service normal y confía en
   el secreto compartido para la seguridad).
2. Conecta el mismo repo de GitHub (`ProyectoLogix`), pero indica **Root Directory: `meet-bot`**
   y **Runtime: Docker** (ya dejé un `Dockerfile` en esta carpeta con Chrome + Xvfb
   preinstalados, no hace falta configurar build command a mano).
3. Variables de entorno de este servicio: `SPIKIA_MEETBOT_SECRET` = un valor largo y aleatorio
   que tú elijas (guárdalo, lo necesitas también del lado de Laravel).
4. Deploy. Cuando termine, Render te da la URL interna del servicio (o la URL pública si usaste
   Web Service normal) — algo como `https://spikia-meet-bot.onrender.com` o, si es un Private
   Service, un host interno tipo `spikia-meet-bot:4100` alcanzable solo desde otros servicios
   Render del mismo proyecto.
5. En el servicio **web de Laravel** (el que ya tienes), agrega/edita estas variables de
   entorno y vuelve a desplegar (o usa "Manual Deploy" para que las tome):
   ```
   ENABLE_MEETING_BOT=true
   SPIKIA_MEETBOT_URL=<la URL del paso 4>
   SPIKIA_MEETBOT_SECRET=<el mismo valor del paso 3>
   ```

## Instalación manual en un VPS propio (alternativa a Render)

```bash
# Dependencias de sistema para Chrome headless (Debian/Ubuntu)
sudo apt-get update && sudo apt-get install -y \
    libnss3 libatk1.0-0 libatk-bridge2.0-0 libgbm1 libasound2 \
    libxkbcommon0 libxcomposite1 libxdamage1 libxrandr2 libxfixes3 libpango-1.0-0

cd meet-bot
npm ci
```

## Variables de entorno

- `PORT` (opcional, default `4100`) — puerto donde escucha la API interna.
- `SPIKIA_MEETBOT_SECRET` — mismo valor que `SPIKIA_MEETBOT_SECRET` en el `.env` de Laravel.

## Arrancar el proceso (con PM2)

```bash
npm install -g pm2
SPIKIA_MEETBOT_SECRET=el-mismo-secreto-que-en-laravel pm2 start index.js --name spikia-meet-bot
pm2 save
```

Si corres esta variante en un VPS propio, deja el puerto (`4100` por defecto) bloqueado por
firewall para que solo el propio servidor/red privada pueda alcanzarlo — el proceso escucha en
`0.0.0.0` (necesario para la variante Docker/Render), así que la seguridad de red la tiene que
poner el firewall del VPS, no el binding del proceso.

## Advertencias

- Google Meet no tiene una API oficial para esto: se automatiza el navegador. Los selectores
  usados en `bot.js` (botón "Pedir unirse", detección de admisión, etc.) dependen del HTML
  actual de Meet y **pueden romperse sin aviso** cuando Google cambia su interfaz. Si el bot
  deja de poder unirse, ese es el primer lugar a revisar.
- El anfitrión de la reunión debe admitir manualmente al bot ("Spikia (traduciendo en vivo)")
  cada vez, igual que a cualquier otro invitado nuevo.
- El bot nunca envía audio/video propio a la llamada (Chrome arranca con
  `--use-fake-ui-for-media-stream` y sin dispositivos reales de cámara/micrófono).
