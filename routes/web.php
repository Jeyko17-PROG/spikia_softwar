<?php

use App\Modules\Activity\Controllers\ActividadController;
use App\Modules\Billing\Controllers\CreditoController;
use App\Modules\Dashboard\Controllers\DashboardController;
use App\Modules\Dashboard\Controllers\HealthController;
use App\Modules\Glossary\Controllers\GlosarioController;
use App\Modules\Media\Controllers\VideoController;
use App\Modules\Profile\Controllers\ProfileController;
use App\Modules\Sessions\Controllers\SesionController;
use App\Modules\Support\Controllers\SupportController;
use App\Modules\Transcriptions\Controllers\TranscripcionController;
use App\Modules\Traduccion\Controllers\TraduccionController;
use App\Modules\Traduccion\Controllers\TraduccionSimultaneaController;
use Illuminate\Support\Facades\Route;

// --- RUTAS PÚBLICAS O SEMI-PÚBLICAS ---
Route::get('/', fn () => view('welcome'))->name('home');

// Estas rutas deben ser accesibles para que el Listener no falle
Route::get('/sesiones/{slug}/transmision', [SesionController::class, 'transmision'])->name('sesion.transmision');
Route::get('/sesiones/{slug}/movil', [SesionController::class, 'movil'])->name('sesion.movil');
Route::get('/sesiones/{slug}/avatar', [SesionController::class, 'avatar'])->name('sesion.avatar');
Route::get('/sesiones/{slug}/subtitulos', [SesionController::class, 'subtitulos'])->name('sesion.subtitulos');
Route::get('/sesiones/{slug}/mensajes', [SesionController::class, 'feed'])->name('sesiones.mensajes.feed');
// Token de LiveKit para OYENTES (transmision/movil): publico, solo-suscripcion, sin
// publicar nada. Necesario para que el oyente pueda recibir el video del interprete.
Route::get('/sesiones/{slug}/livekit-token/viewer', [SesionController::class, 'liveKitViewerToken'])
    ->middleware('throttle:60,1')
    ->name('sesiones.livekit-token.viewer');
// Ingesta de audio del worker del bot de reunion (Node, sin sesion de navegador ni CSRF):
// la autorizacion es el header X-Spikia-Bot-Token, ver ingestBotAudio().
Route::post('/sesiones/{slug}/bot-audio', [SesionController::class, 'ingestBotAudio'])
    ->middleware('throttle:30,1')
    ->name('sesiones.bot-audio.ingest');
// Rutas públicas de voz: con throttle para evitar que un tercero queme la cuota de ElevenLabs.
Route::post('/voz/elevenlabs', [SesionController::class, 'elevenlabs'])
    ->middleware('throttle:120,1')
    ->name('voz.elevenlabs');
Route::get('/voz/elevenlabs/stream', [SesionController::class, 'elevenlabsStream'])
    ->middleware('throttle:120,1')
    ->name('voz.elevenlabs.stream');

// Ruta de traducciones accesible sin login (el listener/master la consumen).
// Con throttle para evitar que un tercero queme la cuota de OpenAI/DeepL/Google:
// max 120 peticiones por minuto por IP.
Route::post('/traducciones', [TraduccionController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('traducciones.store');
// Version por lotes: resuelve todos los idiomas destino de una frase en una sola
// peticion HTTP, con las llamadas a OpenAI corriendo concurrentes dentro del mismo
// request (ver TraduccionController::storeBatch).
Route::post('/traducciones/batch', [TraduccionController::class, 'storeBatch'])
    ->middleware('throttle:120,1')
    ->name('traducciones.batch');

// --- RUTAS PROTEGIDAS ---
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/metricas', [DashboardController::class, 'metrics'])->name('dashboard.metrics');
    Route::get('/dashboard/health', [HealthController::class, 'index'])->name('dashboard.health');
    Route::post('/dashboard/licencia/activar', [DashboardController::class, 'activateLicense'])->name('dashboard.license.activate');
    Route::post('/dashboard/demo/activar', [DashboardController::class, 'activateDemo'])->name('dashboard.demo.activate');
    
    // Sesión Master y Control
    Route::get('/sesiones/{slug}/master', [SesionController::class, 'master'])->name('sesion.master');
    Route::get('/sesiones/{slug}/reunion', [SesionController::class, 'reunion'])->name('sesion.reunion');
    // Modo 'human_live' del avatar: el dueño de la sesión transmite su propia cámara.
    // Scaffold sin proveedor WebRTC real conectado todavía (ver avatar-interprete-broadcaster.js).
    Route::get('/sesiones/{slug}/interprete', [SesionController::class, 'interprete'])->name('sesion.interprete');
    Route::get('/sesiones/{slug}/livekit-token/publisher', [SesionController::class, 'liveKitPublisherToken'])->name('sesiones.livekit-token.publisher');
    
    // Transcripciones
    Route::get('/transcripciones-historial', [TranscripcionController::class, 'listado'])->name('transcripciones.listado');
    Route::get('/transcripcion/{slug}', [TranscripcionController::class, 'index'])->name('transcripcion.index');
    Route::post('/transcripciones/guardar', [TranscripcionController::class, 'store'])->name('transcripciones.store');
    Route::post('/transcripciones/grabacion', [TranscripcionController::class, 'grabarSesion'])->name('transcripciones.grabacion');
    Route::get('/transcripcion/descargar/{slug}/{tipo}/{idioma?}', [TranscripcionController::class, 'descargar'])->name('transcripcion.descargar');

    // Gestión de Sesiones
    Route::resource('sesiones', SesionController::class)->except(['create', 'show']);
    Route::post('/sesiones/{id}/extender-tiempo', [SesionController::class, 'extendTime'])->name('sesiones.extend-time');
    Route::post('/sesiones/{id}/activar-idioma', [SesionController::class, 'activarIdioma'])->name('sesiones.activarIdioma');
    Route::post('/sesiones/{slug}/mensajes', [SesionController::class, 'publicarMensaje'])->name('sesiones.mensajes.store');
    Route::post('/sesiones/{slug}/procesar-audio', [SesionController::class, 'processAudio'])->name('sesiones.audio.process');
    Route::post('/sesiones/{slug}/interim', [SesionController::class, 'updateInterim'])->name('sesiones.interim.update');
    Route::patch('/sesiones/{slug}/translation-settings', [SesionController::class, 'updateTranslationSettings'])->name('sesiones.translation.update');
    Route::post('/sesiones/{slug}/voice-clone', [SesionController::class, 'cloneVoice'])->name('sesiones.voice-clone.store');
    Route::delete('/sesiones/{slug}/voice-clone', [SesionController::class, 'removeVoiceClone'])->name('sesiones.voice-clone.destroy');
    Route::post('/sesiones/{slug}/live-timer/start', [SesionController::class, 'startLiveTimer'])->name('sesiones.live-timer.start');
    Route::post('/sesiones/{slug}/live-timer/stop', [SesionController::class, 'stopLiveTimer'])->name('sesiones.live-timer.stop');
    Route::post('/sesiones/{slug}/meeting-bot/start', [SesionController::class, 'startMeetingBot'])->name('sesiones.meeting-bot.start');
    Route::post('/sesiones/{slug}/meeting-bot/stop', [SesionController::class, 'stopMeetingBot'])->name('sesiones.meeting-bot.stop');
    Route::get('/sesiones/{slug}/meeting-bot/status', [SesionController::class, 'meetingBotStatus'])->name('sesiones.meeting-bot.status');

    // Voz / STT externos
    Route::post('/deepgram/token', [SesionController::class, 'deepgramToken'])->name('deepgram.token');

    // Otros Módulos
    Route::post('/creditos/consumir', [CreditoController::class, 'consume'])->name('creditos.consume');
    Route::get('/glosarios', [GlosarioController::class, 'index'])->name('glosarios');
    Route::post('/glosarios/guardar', [GlosarioController::class, 'store'])->name('glosarios.store');
    Route::delete('/glosarios/{id}', [GlosarioController::class, 'destroy'])->name('glosarios.destroy');
    Route::put('/glosarios/{id}', [GlosarioController::class, 'update'])->name('glosarios.update');
    Route::post('/videos', [VideoController::class, 'store'])->name('videos.store');
    Route::delete('/videos/{id}', [VideoController::class, 'destroy'])->name('videos.destroy');

    // Perfil y Actividad
    Route::get('/actividad/acceso', [ActividadController::class, 'promptPin'])->name('actividad.pin');
    Route::post('/actividad/acceso', [ActividadController::class, 'verifyPin'])->name('actividad.pin.verify');
    Route::get('/actividad', [ActividadController::class, 'index'])->name('actividad.index');
    Route::get('/actividad/exportar', [ActividadController::class, 'exportarExcel'])->name('actividad.export');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/soporte', [SupportController::class, 'index'])->name('soporte');
    Route::post('/soporte/chat', [SupportController::class, 'chat'])->name('soporte.chat');
    Route::post('/soporte/contacto', [SupportController::class, 'contact'])->name('soporte.contact');

    // Traducción simultánea
    Route::get('/traduccion-simultanea', [TraduccionSimultaneaController::class, 'create'])->name('traduccion.simultanea');
    Route::post('/traduccion-simultanea', [TraduccionSimultaneaController::class, 'store'])->name('traduccion.simultanea.store');
    Route::get('/traduccion-simultanea/historial', [TraduccionSimultaneaController::class, 'index'])->name('traduccion.simultanea.historial');
});

require __DIR__ . '/auth.php';
