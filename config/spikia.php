<?php

return [
    // Interruptores de modulos opcionales: cada uno debe poder apagarse sin tocar
    // el resto del pipeline. Si esta en false, el codigo que depende de el ni
    // siquiera debe evaluarse (ver guards con config('spikia.features.x') && ...).
    'features' => [
        'sign_avatar' => (bool) env('ENABLE_SIGN_AVATAR', false),
        'meeting_bot' => (bool) env('ENABLE_MEETING_BOT', false),
    ],

    // Pipeline de avatar en Lengua de Señas con MetaHuman (Unreal Engine 5) - ver
    // ProcessSignGlossesJob y las carpetas sign-nlp-service/ y sign-avatar-orchestrator/ en
    // la raiz del proyecto. Ambos servicios son independientes del backend de Laravel (Python
    // + Node.js respectivamente) y se corren aparte; estas URLs son como Laravel los alcanza.
    'sign_avatar_pipeline' => [
        // Microservicio Python/FastAPI: texto -> secuencia de glosas (sign-nlp-service).
        'nlp_service_url' => env('SIGN_NLP_SERVICE_URL', 'http://127.0.0.1:8100'),
        'nlp_service_token' => env('SIGN_NLP_SERVICE_TOKEN', ''),
        'nlp_service_timeout' => (float) env('SIGN_NLP_SERVICE_TIMEOUT', 6.0),

        // Orquestador Node.js/WebSocket: recibe la secuencia y la reenvia, en cola, a la
        // instancia de Unreal Engine conectada para esa sesion (sign-avatar-orchestrator).
        'orchestrator_url' => env('SIGN_ORCHESTRATOR_URL', 'http://127.0.0.1:4100'),
        'orchestrator_token' => env('SIGN_ORCHESTRATOR_TOKEN', ''),
        'orchestrator_timeout' => (float) env('SIGN_ORCHESTRATOR_TIMEOUT', 3.0),
    ],

    'demo_duration_minutes' => 20,
    // Ruta al binario de ffmpeg, usado para unir los fragmentos de audio de una sesion
    // (voz original en WebM/Opus, traduccion en MP3) en un solo archivo descargable.
    // Por defecto asume que "ffmpeg" esta en el PATH; en este entorno local se fija la
    // ruta exacta porque el PATH recien se actualizo (requiere reiniciar la terminal/
    // servicio para que "ffmpeg" solo alcance sin ruta completa).
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'master_base_url' => env('SPIKIA_MASTER_BASE_URL', env('APP_URL', 'http://localhost:8000')),
    'public_base_url' => env('SPIKIA_PUBLIC_BASE_URL', ''),
    'voice_provider' => env('SPIKIA_VOICE_PROVIDER', 'elevenlabs'),
    'socket_enabled' => (bool) env('SPIKIA_SOCKET_ENABLED', false),
    'socket_url' => env('SPIKIA_SOCKET_URL'),
    // Worker Node.js separado (ver /meet-bot) que entra a una reunion de Meet/Zoom como
    // invitado y captura su audio. Vive fuera de PHP porque necesita un navegador Chrome
    // persistente por reunion; Laravel solo lo orquesta via esta URL interna.
    'meeting_bot' => [
        'url' => env('SPIKIA_MEETBOT_URL', 'http://127.0.0.1:4100'),
        'secret' => env('SPIKIA_MEETBOT_SECRET'),
    ],
    // Video en tiempo real para el modo "Interprete en vivo" (avatar_mode = human_live):
    // sin esto configurado, la vista previa del interprete sigue siendo solo local (no llega
    // a transmision ni a los oyentes) - ver LiveKitTokenService.
    'livekit' => [
        'url' => env('LIVEKIT_URL', ''),
        'api_key' => env('LIVEKIT_API_KEY', ''),
        'api_secret' => env('LIVEKIT_API_SECRET', ''),
    ],
    'elevenlabs' => [
        'enabled' => (bool) env('ELEVENLABS_ENABLED', false),
        'api_key' => env('ELEVENLABS_API_KEY'),
        'voice_id' => env('ELEVENLABS_VOICE_ID'),
        'male_voice_id' => env('ELEVENLABS_VOICE_ID_MALE'),
        'female_voice_id' => env('ELEVENLABS_VOICE_ID_FEMALE'),
        'model_id' => env('ELEVENLABS_MODEL_ID', 'eleven_turbo_v2'),
        'stability' => (float) env('ELEVENLABS_STABILITY', 0.45),
        'similarity_boost' => (float) env('ELEVENLABS_SIMILARITY_BOOST', 0.75),
        'style' => (float) env('ELEVENLABS_STYLE', 0),
        'use_speaker_boost' => (bool) env('ELEVENLABS_USE_SPEAKER_BOOST', true),
    ],
    // PoC/benchmark (ver informe "reduccion de delay"): permite comparar el motor de
    // traduccion actual (Chat Completions, translateBatch) contra OpenAI Realtime API sin
    // tocar el flujo de produccion. 'current' dejar TODO igual; 'realtime_experimental'
    // activa Realtime SOLO para el idioma de poc_target_language, con fallback automatico
    // al motor actual si la conexion Realtime falla.
    'realtime_translation' => [
        // current | realtime_experimental (texto, brazo 1) | realtime_audio_experimental
        // (audio + gpt-realtime-2.1 con VAD, brazo A) | realtime_translate_dedicated_experimental
        // (audio + gpt-realtime-translate, streaming continuo, brazo B). Ver informe
        // "A vs B: menor latencia real".
        'engine' => env('SPIKIA_TRANSLATION_ENGINE', 'realtime_audio_experimental'),
        'model' => env('OPENAI_REALTIME_MODEL', 'gpt-realtime-2.1'),
        // Brazo B: modelo de traduccion dedicado. OJO (verificado en la doc de OpenAI): NO
        // soporta instructions/prompt personalizado - el glosario/master_translation_prompt
        // que SI aplica en los otros brazos, aca no aplica. Solo se puede fijar el idioma
        // de salida.
        'translation_dedicated_model' => env('OPENAI_REALTIME_TRANSLATION_MODEL', 'gpt-realtime-translate'),
        // Solo brazo B: esta sesion no tiene un evento de "fin de frase" (transmite
        // continuo). Este gap (ms sin deltas nuevos) es una heuristica que Spikia inventa
        // UNICAMENTE para poder medir el benchmark - no cambia el comportamiento real del
        // modelo ni se usa para nada mas.
        'segment_gap_ms' => (int) env('OPENAI_REALTIME_SEGMENT_GAP_MS', 900),
        'poc_target_language' => 'en',
        // PCM16 24kHz: formato que exige la Realtime API para audio de entrada. OJO: es
        // distinto de los 16kHz que usa Deepgram hoy (downsampleAndConvert de master.js no
        // se reusa tal cual, ver realtime-audio-poc.js).
        'audio_sample_rate' => 24000,
        // VAD/turn detection del brazo de audio (session.audio.input.turn_detection). El
        // modelo dedicado de traduccion de OpenAI NO expone esto (segmentacion interna,
        // ver informe); por eso el brazo de audio usa el modelo Realtime general, que si
        // lo permite. semantic_vad+high = prioriza latencia baja sobre esperar frases
        // "completas" segun el propio juicio del modelo.
        'vad' => [
            'type' => env('OPENAI_REALTIME_VAD_TYPE', 'semantic_vad'), // semantic_vad | server_vad
            'eagerness' => env('OPENAI_REALTIME_VAD_EAGERNESS', 'high'), // low|medium|high|auto (solo semantic_vad)
            'silence_duration_ms' => (int) env('OPENAI_REALTIME_VAD_SILENCE_MS', 400), // solo server_vad
        ],
    ],

    'default_license_plan' => 'free',
    'license_plans' => [
        'free' => [
            'label' => 'Gratis',
            'credits' => 100,
            'badge' => '100 tokens',
            'description' => 'Acceso base para probar la plataforma.',
        ],
        'medium' => [
            'label' => 'Media',
            'credits' => 250,
            'badge' => '250 tokens',
            'description' => 'Para uso frecuente con más capacidad.',
        ],
        'premium' => [
            'label' => 'Premium',
            'credits' => 500,
            'badge' => '500 tokens',
            'description' => 'Para operación intensiva y sesiones largas.',
        ],
    ],
    'master_languages' => [
        ['id' => 'en-US', 'base' => 'en', 'speech' => 'en-US', 'name' => 'English'],
        ['id' => 'pt-BR', 'base' => 'pt', 'speech' => 'pt-BR', 'name' => 'Portugués'],
        ['id' => 'it-IT', 'base' => 'it', 'speech' => 'it-IT', 'name' => 'Italiano'],
        ['id' => 'fr-FR', 'base' => 'fr', 'speech' => 'fr-FR', 'name' => 'Francés'],
        ['id' => 'es-ES', 'base' => 'es', 'speech' => 'es-ES', 'name' => 'Español España'],
        ['id' => 'es-419', 'base' => 'es', 'speech' => 'es-MX', 'name' => 'Español LatAm'],
    ],
    'listener_languages' => [
        ['id' => 'en', 'label' => 'ENG'],
        ['id' => 'es-ES', 'label' => 'ESP-ES'],
        ['id' => 'es-419', 'label' => 'ESP-LAT'],
        ['id' => 'pt', 'label' => 'POR'],
        ['id' => 'it', 'label' => 'ITA'],
        ['id' => 'fr', 'label' => 'FRA'],
    ],
    'default_targets' => ['en', 'pt', 'it', 'fr'],

    'translation_simultaneous' => [
        'speech_to_text_model'     => 'gpt-4o-mini-transcribe',
        'translation_model'        => 'gpt-4o-mini',
        'translation_premium_model' => 'gpt-4o',
        'text_to_speech_model'     => 'gpt-4o-mini-tts',
        'voice_provider'           => env('SPIKIA_VOICE_PROVIDER', 'elevenlabs'),
        'voice_gender_profile'     => 'female',
        'voice'                    => 'marin',
        'audio_delivery_mode'      => env('SPIKIA_AUDIO_DELIVERY_MODE', 'ultra_fast'),
        'master_translation_prompt' => 'Eres un traductor médico simultáneo. Traduce TODO el texto recibido de forma literal y COMPLETA, sin resumir, sin omitir palabras, sin acortar oraciones. Conserva muletillas, nombres propios, tecnicismos médicos, números, dosis y unidades exactamente como aparecen. Si el texto tiene errores de transcripción interpreta lo más fielmente posible. Responde SOLO con la traducción, sin comentarios, sin explicaciones, sin prefijos como "Traducción:".',
        'voice_profiles' => [
            ['value' => 'marin', 'label' => 'Marin', 'gender' => 'female', 'voice_id' => env('ELEVENLABS_VOICE_ID_MARIN', env('ELEVENLABS_VOICE_ID_FEMALE'))],
            ['value' => 'coral', 'label' => 'Coral', 'gender' => 'female', 'voice_id' => env('ELEVENLABS_VOICE_ID_CORAL', env('ELEVENLABS_VOICE_ID_FEMALE'))],
            ['value' => 'shimmer', 'label' => 'Shimmer', 'gender' => 'female', 'voice_id' => env('ELEVENLABS_VOICE_ID_SHIMMER', env('ELEVENLABS_VOICE_ID_FEMALE'))],
            ['value' => 'cedar', 'label' => 'Cedar', 'gender' => 'male', 'voice_id' => env('ELEVENLABS_VOICE_ID_CEDAR', env('ELEVENLABS_VOICE_ID_MALE'))],
            ['value' => 'alloy', 'label' => 'Alloy', 'gender' => 'male', 'voice_id' => env('ELEVENLABS_VOICE_ID_ALLOY', env('ELEVENLABS_VOICE_ID_MALE'))],
            ['value' => 'sage', 'label' => 'Sage', 'gender' => 'male', 'voice_id' => env('ELEVENLABS_VOICE_ID_SAGE', env('ELEVENLABS_VOICE_ID_MALE'))],
        ],
        'available_stt_models' => [
            ['label' => 'Estándar — gpt-4o-mini-transcribe',       'value' => 'gpt-4o-mini-transcribe'],
            ['label' => 'Premium — gpt-4o-transcribe',              'value' => 'gpt-4o-transcribe'],
            ['label' => 'Varios hablantes — gpt-4o-transcribe-diarize', 'value' => 'gpt-4o-transcribe-diarize'],
            ['label' => 'Compatibilidad — whisper-1',               'value' => 'whisper-1'],
        ],
        'available_translation_models' => [
            ['label' => 'Estándar — gpt-4o-mini', 'value' => 'gpt-4o-mini'],
            ['label' => 'Premium — gpt-4o',        'value' => 'gpt-4o'],
        ],
        'available_voices' => ['marin', 'coral', 'shimmer', 'cedar', 'alloy', 'sage'],
        'available_audio_delivery_modes' => [
            ['label' => 'Ultra rapido — voz inmediata del navegador', 'value' => 'ultra_fast'],
            ['label' => 'Premium — prioriza voz remota de mayor calidad', 'value' => 'premium'],
        ],
        'available_languages' => [
            ['label' => 'Español',   'value' => 'es'],
            ['label' => 'Inglés',    'value' => 'en'],
            ['label' => 'Portugués', 'value' => 'pt'],
            ['label' => 'Francés',   'value' => 'fr'],
            ['label' => 'Italiano',  'value' => 'it'],
            ['label' => 'Alemán',    'value' => 'de'],
        ],
    ],
];
