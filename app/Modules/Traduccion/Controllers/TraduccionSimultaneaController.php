<?php

namespace App\Modules\Traduccion\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TraduccionSimultanea;
use App\Services\OpenAITranslationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class TraduccionSimultaneaController extends Controller
{
    public function __construct(private OpenAITranslationService $openai) {}

    public function create()
    {
        $config = config('spikia.translation_simultaneous');

        return view('modules.traduccion.simultanea', compact('config'));
    }

    public function store(Request $request)
    {
        $config = config('spikia.translation_simultaneous');
        $languageValues = collect($config['available_languages'] ?? [])->pluck('value')->all();
        $sttValues = collect($config['available_stt_models'] ?? [])->pluck('value')->all();
        $translationValues = collect($config['available_translation_models'] ?? [])->pluck('value')->all();
        $voiceValues = $config['available_voices'] ?? [];

        $isMultipleSpeakerMode = $request->input('speaker_mode') === 'multiple';
        $normalizedSpeakers = collect($request->input('speakers', []))
            ->map(function ($speaker, int $index): array {
                return [
                    'speaker_id' => trim((string) ($speaker['speaker_id'] ?? 'speaker_' . ($index + 1))),
                    'name' => trim((string) ($speaker['name'] ?? '')),
                ];
            })
            ->filter(fn (array $speaker): bool => $speaker['speaker_id'] !== '' || $speaker['name'] !== '')
            ->values()
            ->all();

        $request->merge([
            'speakers' => $isMultipleSpeakerMode ? $normalizedSpeakers : null,
        ]);

        $data = $request->validate([
            'translation_mode'          => ['required', Rule::in(['voice_to_text', 'voice_to_voice'])],
            'speaker_mode'              => ['required', Rule::in(['single', 'multiple'])],
            'speakers'                  => ['nullable', 'array', 'required_if:speaker_mode,multiple', 'min:1'],
            'speakers.*.speaker_id'     => ['required_with:speakers', 'string', 'max:100'],
            'speakers.*.name'           => ['required_with:speakers', 'string', 'max:255'],
            'input_language'            => ['required', Rule::in($languageValues)],
            'output_language'           => ['required', Rule::in($languageValues), 'different:input_language'],
            'speech_to_text_model'      => ['required', Rule::in($sttValues)],
            'translation_model'         => ['required', Rule::in($translationValues)],
            'text_to_speech_model'      => ['nullable', 'required_if:translation_mode,voice_to_voice', Rule::in([$config['text_to_speech_model']])],
            'voice'                     => ['nullable', 'required_if:translation_mode,voice_to_voice', Rule::in($voiceValues)],
            'master_translation_prompt' => ['required', 'string'],
            'audio'                     => ['required', 'file', 'mimes:webm,ogg,mp3,wav,m4a', 'max:25600'],
        ]);

        $audioFile = $request->file('audio');
        $audioPath = $audioFile->store('traducciones/audio', 'public');
        $audioFullPath = Storage::disk('public')->path($audioPath);

        $resolvedTtsModel = $data['translation_mode'] === 'voice_to_voice'
            ? ($data['text_to_speech_model'] ?? $config['text_to_speech_model'])
            : null;
        $resolvedVoice = $data['translation_mode'] === 'voice_to_voice'
            ? ($data['voice'] ?? $config['voice'])
            : null;

        try {
            $originalTranscript = $this->openai->transcribe(
                $audioFullPath,
                $data['speech_to_text_model'],
                $data['input_language']
            );

            $translatedText = $this->openai->translate(
                $originalTranscript,
                $data['input_language'],
                $data['output_language'],
                $data['translation_model'],
                $data['master_translation_prompt']
            );

            $outputAudioPath = null;
            if ($data['translation_mode'] === 'voice_to_voice') {
                $outputAudioPath = $this->openai->synthesize(
                    $translatedText,
                    $resolvedTtsModel,
                    $resolvedVoice
                );
            }
        } catch (Throwable $exception) {
            Log::error('Error procesando traduccion simultanea con OpenAI', [
                'user_id' => Auth::id(),
                'message' => $exception->getMessage(),
                'translation_mode' => $data['translation_mode'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo procesar la traduccion con OpenAI. Verifica el audio, los modelos configurados e intenta de nuevo.',
            ], 502);
        }

        $configJson = [
            'translation_mode' => $data['translation_mode'],
            'speaker_mode'     => $data['speaker_mode'],
            'speakers'         => $data['speakers'] ?? [],
            'languages'        => [
                'input_language'  => $data['input_language'],
                'output_language' => $data['output_language'],
            ],
            'models'           => [
                'speech_to_text_model' => $data['speech_to_text_model'],
                'translation_model'    => $data['translation_model'],
                'text_to_speech_model' => $resolvedTtsModel,
                'voice'                => $resolvedVoice,
            ],
            'prompt'           => [
                'master_translation_prompt' => $data['master_translation_prompt'],
            ],
            'storage'          => [
                'save_original_audio'      => true,
                'save_original_transcript' => true,
                'save_translated_text'     => true,
                'save_translated_audio'    => $data['translation_mode'] === 'voice_to_voice',
            ],
            'providers'        => [
                'speech_to_text' => 'openai',
                'translation'    => 'openai',
                'text_to_speech' => 'openai',
            ],
        ];

        $record = TraduccionSimultanea::create([
            'user_id'                   => Auth::id(),
            'translation_mode'          => $data['translation_mode'],
            'speaker_mode'              => $data['speaker_mode'],
            'speakers'                  => $data['speakers'] ?? null,
            'input_language'            => $data['input_language'],
            'output_language'           => $data['output_language'],
            'speech_to_text_model'      => $data['speech_to_text_model'],
            'translation_model'         => $data['translation_model'],
            'text_to_speech_model'      => $resolvedTtsModel,
            'voice'                     => $resolvedVoice,
            'master_translation_prompt' => $data['master_translation_prompt'],
            'input_audio_url'           => Storage::url($audioPath),
            'original_transcript'       => $originalTranscript,
            'translated_text'           => $translatedText,
            'output_audio_url'          => $outputAudioPath ? Storage::url($outputAudioPath) : null,
            'config_json'               => $configJson,
            'providers_json'            => $configJson['providers'],
        ]);

        return response()->json([
            'success'             => true,
            'message'             => 'Traduccion completada correctamente.',
            'original_transcript' => $originalTranscript,
            'translated_text'     => $translatedText,
            'output_audio_url'    => $record->output_audio_url,
            'translation_mode'    => $data['translation_mode'],
            'config_json'         => $configJson,
        ]);
    }

    public function index()
    {
        $historial = TraduccionSimultanea::where('user_id', Auth::id())
            ->latest()
            ->paginate(20);

        return view('modules.traduccion.historial', compact('historial'));
    }
}
