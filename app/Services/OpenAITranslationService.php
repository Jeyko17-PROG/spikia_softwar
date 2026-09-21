<?php

namespace App\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI;

class OpenAITranslationService
{
    private \OpenAI\Client $client;
    private GuzzleClient $http;

    public function __construct()
    {
        $apiKey = (string) config('services.openai.key');
        $this->http = new GuzzleClient([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
            ],
            'timeout' => 10,
            'connect_timeout' => 3,
        ]);

        // El cliente oficial no tenia timeout: si OpenAI se degradaba, transcribe()/translate()
        // podian colgar indefinidamente y arrastrar todo el evento de voz con ellos. Le pasamos
        // el mismo Guzzle acotado (10s/3s) que ya usan translateBatch()/synthesizeBatch().
        $this->client = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withHttpClient($this->http)
            ->make();
    }

    /**
     * Transcribe audio file to text using OpenAI STT.
     * Returns the original transcript string.
     */
    public function transcribe(string $audioPath, string $model, string $language): string
    {
        $handle = fopen($audioPath, 'r');

        try {
            $response = $this->client->audio()->transcribe([
                'model'           => $model,
                'file'            => $handle,
                'language'        => $language,
                'response_format' => 'text',
            ]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return trim((string) $response->text);
    }

    /**
     * Translate text using OpenAI Chat with the master prompt.
     * Returns the translated text string.
     */
    public function translate(
        string $text,
        string $fromLanguage,
        string $toLanguage,
        string $model,
        string $masterPrompt,
        array $glossaryTerms = [],
        array $glossaryPairs = []
    ): string {
        $systemPrompt = $masterPrompt
            . "\n\nIdioma de entrada: {$fromLanguage}. Idioma de salida: {$toLanguage}."
            . "\n\nREGLAS OBLIGATORIAS:"
            . "\n- Traduce TODO el texto proporcionado de forma literal y completa."
            . "\n- NO resumas, NO omitas palabras y NO acortes oraciones."
            . "\n- Mantén todas las muletillas, repeticiones, pausas verbales y rellenos del hablante."
            . "\n- Conserva nombres propios, tecnicismos médicos, números y unidades sin cambiar."
            . "\n- Si el texto tiene errores de transcripción, tradúcelo igual con la mejor interpretación posible."
            . "\n- Responde SOLO con la traducción, sin prefijos, comentarios ni explicaciones.";

        $systemPrompt .= $this->buildGlossarySection($glossaryTerms, $glossaryPairs);

        $response = $this->client->chat()->create([
            'model'    => $this->resolveFastModel($model),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $text],
            ],
            'temperature' => 0,
            'max_tokens' => 500,
        ]);

        return trim($response->choices[0]->message->content ?? '');
    }

    /**
     * Mapea nombres de modelos inexistentes/lentos al modelo rapido real.
     * El sistema venia configurado con "gpt-5.4-mini" que NO EXISTE en la API real
     * y causaba 404s silenciosos. Forzamos gpt-4o-mini que es 3-5x mas rapido.
     */
    private function resolveFastModel(string $requested): string
    {
        $r = strtolower(trim($requested));

        if ($r === '' || str_contains($r, 'gpt-5') || str_contains($r, 'mini')) {
            return 'gpt-4o-mini';
        }

        $whitelist = [
            'gpt-4o-mini',
            'gpt-4o',
            'gpt-4-turbo',
            'gpt-3.5-turbo',
        ];

        return in_array($r, $whitelist, true) ? $r : 'gpt-4o-mini';
    }

    private function buildGlossarySection(array $terms, array $pairs): string
    {
        $sections = [];

        if (! empty($pairs)) {
            $lines = [];
            foreach ($pairs as $pair) {
                $src = trim((string) ($pair['source'] ?? ''));
                $tgt = trim((string) ($pair['target'] ?? ''));
                if ($src !== '' && $tgt !== '') {
                    $lines[] = "{$src}={$tgt}";
                }
            }
            if ($lines) {
                $sections[] = "\nPairs: " . implode(' | ', $lines);
            }
        }

        if (! empty($terms)) {
            $clean = array_slice(
                array_values(array_unique(array_filter($terms, fn ($t) => is_string($t) && trim($t) !== ''))),
                0,
                80
            );
            if ($clean) {
                $sections[] = "\nGlossary (preserve proper nouns, translate medical terms): " . implode(', ', $clean);
            }
        }

        return implode('', $sections);
    }

    /**
     * Convert translated text to speech using OpenAI TTS.
     * Saves the audio file and returns its storage path.
     */
    public function synthesize(string $text, string $model, string $voice): string
    {
        $response = $this->client->audio()->speech([
            'model'           => $model,
            'input'           => $text,
            'voice'           => $voice,
            'response_format' => 'mp3',
        ]);

        $filename = 'traducciones/audio_' . uniqid() . '.mp3';
        Storage::disk('public')->put($filename, $response);

        return $filename;
    }

    /**
     * Translate multiple texts concurrently against /v1/chat/completions.
     *
     * @param array<string,array{text:string,from:string,to:string}> $items keyed by caller-defined identifier
     * @return array<string,string|null> map of id => translated text (null if that translation failed)
     */
    public function translateBatch(array $items, string $model, string $masterPrompt, array $glossaryTerms = [], array $glossaryPairs = []): array
    {
        if ($items === []) {
            return [];
        }

        // Se calcula una sola vez fuera del loop: el mismo bloque de glosario aplica a
        // todos los idiomas destino de este lote.
        $glossarySection = $this->buildGlossarySection($glossaryTerms, $glossaryPairs);

        $promises = [];
        foreach ($items as $key => $item) {
            $systemPrompt = $masterPrompt
                . "\n\nIdioma de entrada: {$item['from']}. Idioma de salida: {$item['to']}."
                . "\n\nREGLAS OBLIGATORIAS:"
                . "\n- Traduce TODO el texto proporcionado de forma literal y completa."
                . "\n- NO resumas, NO omitas palabras y NO acortes oraciones."
                . "\n- Mantén nombres propios, tecnicismos y números sin cambiar."
                . "\n- Responde SOLO con la traducción, sin prefijos ni explicaciones."
                . $glossarySection;

            $promises[$key] = $this->http->postAsync('chat/completions', [
                'json' => [
                    'model' => $this->resolveFastModel($model),
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $item['text']],
                    ],
                    'temperature' => 0,
                    'max_tokens' => 500,
                ],
            ]);
        }

        $results = Utils::settle($promises)->wait();
        $translations = [];

        foreach ($results as $key => $result) {
            if (($result['state'] ?? '') !== 'fulfilled') {
                $reason = $result['reason'] ?? null;
                Log::warning('OpenAI translate batch entry failed.', [
                    'key' => $key,
                    'message' => $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason,
                ]);
                $translations[$key] = null;
                continue;
            }

            $body = json_decode((string) $result['value']->getBody(), true);
            $text = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
            $translations[$key] = $text !== '' ? $text : null;
        }

        return $translations;
    }

    /**
     * Synthesize multiple texts to MP3 concurrently. Saves each successful one to disk public.
     *
     * @param array<string,array{text:string,voice:string}> $items keyed by caller-defined identifier
     * @return array<string,string|null> map of id => storage path (null on failure)
     */
    public function synthesizeBatch(array $items, string $model): array
    {
        if ($items === []) {
            return [];
        }

        $promises = [];
        foreach ($items as $key => $item) {
            $promises[$key] = $this->http->postAsync('audio/speech', [
                'json' => [
                    'model' => $model,
                    'voice' => $item['voice'],
                    'input' => $item['text'],
                    'response_format' => 'mp3',
                ],
            ]);
        }

        $results = Utils::settle($promises)->wait();
        $paths = [];

        foreach ($results as $key => $result) {
            if (($result['state'] ?? '') !== 'fulfilled') {
                $reason = $result['reason'] ?? null;
                Log::warning('OpenAI synthesize batch entry failed.', [
                    'key' => $key,
                    'message' => $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason,
                ]);
                $paths[$key] = null;
                continue;
            }

            $audioBytes = (string) $result['value']->getBody();
            if ($audioBytes === '') {
                $paths[$key] = null;
                continue;
            }

            $filename = 'traducciones/audio_' . uniqid('', true) . '.mp3';
            Storage::disk('public')->put($filename, $audioBytes);
            $paths[$key] = $filename;
        }

        return $paths;
    }
}
