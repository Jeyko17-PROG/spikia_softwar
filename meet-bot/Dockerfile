FROM node:20-bookworm-slim

# Chrome real (no el Chromium que trae puppeteer por defecto) + Xvfb: puppeteer-stream
# necesita un display para poder capturar audio/video de una pestaña via chrome.tabCapture,
# eso no funciona con --headless puro en todos los casos, por eso se lanza bajo xvfb-run.
RUN apt-get update && apt-get install -y --no-install-recommends \
    wget gnupg ca-certificates xvfb \
    libnss3 libatk1.0-0 libatk-bridge2.0-0 libgbm1 libasound2 \
    libxkbcommon0 libxcomposite1 libxdamage1 libxrandr2 libxfixes3 libpango-1.0-0 \
    fonts-liberation \
    && wget -q -O /tmp/chrome.deb https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb \
    && apt-get install -y --no-install-recommends /tmp/chrome.deb \
    && rm /tmp/chrome.deb \
    && rm -rf /var/lib/apt/lists/*

ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/google-chrome-stable

WORKDIR /app
COPY package*.json ./
RUN npm ci --omit=dev
COPY . .

ENV PORT=4100
EXPOSE 4100

CMD ["xvfb-run", "-a", "--server-args=-screen 0 1280x720x24", "node", "index.js"]
