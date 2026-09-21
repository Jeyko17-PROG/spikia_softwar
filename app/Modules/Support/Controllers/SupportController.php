<?php

namespace App\Modules\Support\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SupportController extends Controller
{
    public function index(Request $request)
    {
        $messages = array_slice(session('support_chat.messages', [
            [
                'role' => 'assistant',
                'text' => 'Hola, soy el asistente de soporte de Spikia. Puedo ayudarte con creditos, sesiones, glosarios, logs y la demo.',
            ],
        ]), -20);

        session(['support_chat.messages' => $messages]);

        $quickQuestions = [
            'Como activo la licencia?',
            'Como elijo el plan gratis?',
            'Que incluye el plan media?',
            'Que incluye el plan premium?',
            'La demo cuanto tiempo dura?',
            'Como creo una sesion nueva?',
            'Como asigno un glosario?',
            'Como cambio el idioma activo?',
            'Como descargo el Excel de actividad?',
            'Como veo las transcripciones por sesion?',
            'Como descargo el texto TXT?',
            'Como descargo el audio MP4?',
            'Como elimino una sesion?',
            'Que hago si no carga el chat?',
            'Como vuelvo al panel principal?',
        ];

        return view('modules.support.index', compact('messages', 'quickQuestions'));
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
        ]);

        $messages = session('support_chat.messages', []);
        $messages[] = [
            'role' => 'user',
            'text' => trim($data['message']),
        ];

        $answer = $this->resolveAnswer($data['message']);

        $messages[] = [
            'role' => 'assistant',
            'text' => $answer,
        ];

        session(['support_chat.messages' => array_slice($messages, -20)]);

        return response()->json([
            'success' => true,
            'answer' => $answer,
        ]);
    }

    public function contact(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $body = "Nuevo mensaje desde el centro de soporte de Spikia.\n\n"
            . "Nombre: {$data['name']}\n"
            . "Correo: {$data['email']}\n"
            . "Usuario autenticado: " . (auth()->user()?->email ?? 'N/A') . "\n\n"
            . "Mensaje:\n{$data['message']}";

        try {
            Mail::raw($body, function ($message) use ($data) {
                $message->to('soporte.spikia@plataforma.com.co')
                    ->replyTo($data['email'], $data['name'])
                    ->subject('Solicitud de soporte Spikia');
            });
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->withErrors(['support_contact' => 'No se pudo enviar el correo de soporte: ' . $e->getMessage()]);
        }

        return back()->with('support_status', 'Tu solicitud fue enviada a soporte. Te responderemos al correo indicado.');
    }

    private function resolveAnswer(string $message): string
    {
        $text = Str::lower($message);

        $rules = [
            ['keywords' => ['credito', 'creditos', 'licencia'], 'answer' => 'La licencia suma tokens segun el plan que elijas. Gratis tiene 100, Media 250 y Premium 500.'],
            ['keywords' => ['gratis', 'plan gratis', 'free'], 'answer' => 'El plan Gratis te da 100 tokens para probar la plataforma.'],
            ['keywords' => ['media', 'plan medio', '250'], 'answer' => 'El plan Media te da 250 tokens para uso frecuente.'],
            ['keywords' => ['premium', 'plan premium', '500'], 'answer' => 'El plan Premium te da 500 tokens para sesiones mas intensivas.'],
            ['keywords' => ['demo', 'prueba', '20 minutos'], 'answer' => 'La demo crea una sesion lista para usar con Espanol e Ingles y dura 20 minutos.'],
            ['keywords' => ['sesion', 'sesiones', 'crear sesion'], 'answer' => 'Las sesiones se crean en el panel y luego puedes asignar glosarios, idiomas y fechas.'],
            ['keywords' => ['glosario', 'glosarios', 'tema'], 'answer' => 'En glosarios puedes usar plantillas por tema y tambien editar los terminos manualmente.'],
            ['keywords' => ['idioma', 'idiomas', 'cambiar idioma'], 'answer' => 'Desde la configuracion de la sesion puedes definir los idiomas activos y el idioma principal.'],
            ['keywords' => ['excel', 'exportar', 'archivo excel'], 'answer' => 'La seccion de actividad exporta un archivo Excel (.xlsx) con el historial de sesiones.'],
            ['keywords' => ['log', 'registro', 'actividad'], 'answer' => 'El registro de actividad muestra sesiones, tiempos, idiomas y transcripciones.'],
            ['keywords' => ['transcripcion', 'transcripciones', 'txt'], 'answer' => 'En transcripciones puedes descargar el texto TXT por sesion e idioma.'],
            ['keywords' => ['mp4', 'audio', 'video'], 'answer' => 'Cuando exista grabacion o enlace asociado, puedes descargar el archivo de audio o video desde la sesion.'],
            ['keywords' => ['eliminar', 'borrar', 'sesion'], 'answer' => 'Puedes eliminar una sesion desde el modulo de sesiones, solo si eres el propietario.'],
            ['keywords' => ['chat', 'soporte', 'no carga'], 'answer' => 'Si el chat no carga, recarga la pagina y verifica que tengas conexion y sesion activa.'],
            ['keywords' => ['panel', 'volver', 'dashboard'], 'answer' => 'El panel principal está en la portada del dashboard, desde alli accedes a todos los modulos.'],
        ];

        foreach ($rules as $rule) {
            foreach ($rule['keywords'] as $keyword) {
                if (Str::contains($text, $keyword)) {
                    return $rule['answer'];
                }
            }
        }

        return 'Puedo ayudarte con creditos, demo, glosarios, actividad, sesiones, transcripciones y soporte tecnico. Escribe una palabra clave y te respondo.';
    }
}
