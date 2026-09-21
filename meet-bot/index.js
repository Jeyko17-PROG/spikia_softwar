// API interna diminuta que Laravel usa para orquestar el bot (ver app/Services/MeetingBotClient.php).
// Este worker corre en la MISMA maquina que Laravel (Laragon local expuesto por tunel), asi
// que escucha solo en 127.0.0.1: ni siquiera queda alcanzable desde la red local, mucho menos
// desde el tunel publico. Si en el futuro Laravel y este worker llegan a correr en maquinas o
// contenedores separados (ej. un despliegue en Render/Docker), hay que volver a 0.0.0.0 y
// asegurar la red por otro lado (ver PORT/HOST abajo).

const express = require('express');
const bot = require('./bot');

const PORT = process.env.PORT || 4200;
const HOST = process.env.HOST || '127.0.0.1';
const SECRET = process.env.SPIKIA_MEETBOT_SECRET || '';

const app = express();
app.use(express.json());

app.use((req, res, next) => {
    const auth = req.header('authorization') || '';
    const token = auth.startsWith('Bearer ') ? auth.slice(7) : '';
    if (!SECRET || token !== SECRET) {
        return res.status(401).json({ error: 'unauthorized' });
    }
    next();
});

app.post('/join', async (req, res) => {
    const { slug, meetUrl, ingestUrl, ingestToken } = req.body || {};
    if (!slug || !meetUrl || !ingestUrl || !ingestToken) {
        return res.status(422).json({ error: 'slug, meetUrl, ingestUrl e ingestToken son requeridos' });
    }

    try {
        const result = await bot.join({ slug, meetUrl, ingestUrl, ingestToken });
        res.json(result);
    } catch (error) {
        res.status(500).json({ error: error.message });
    }
});

app.post('/leave', async (req, res) => {
    const { slug } = req.body || {};
    if (!slug) {
        return res.status(422).json({ error: 'slug es requerido' });
    }

    try {
        const result = await bot.leave({ slug });
        res.json(result);
    } catch (error) {
        res.status(500).json({ error: error.message });
    }
});

app.get('/status/:slug', (req, res) => {
    res.json(bot.statusOf(req.params.slug));
});

app.listen(PORT, HOST, () => {
    console.log(`spikia-meet-bot escuchando en ${HOST}:${PORT}`);
});
