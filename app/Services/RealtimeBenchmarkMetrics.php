<?php

namespace App\Services;

final class RealtimeBenchmarkMetrics
{
    /**
     * @param array<int, array{name:string, ts:int|float}> $timeline
     * @return array<string, float|null>
     */
    public static function fromTimeline(array $timeline): array
    {
        $events = [];
        foreach ($timeline as $entry) {
            if (! isset($entry['name'], $entry['ts'])) {
                continue;
            }

            $events[(string) $entry['name']] = (float) $entry['ts'];
        }

        $speechStart = self::firstDefined(
            $events['vad_speech_started'] ?? null,
            $events['audio_start'] ?? null,
            $events['first_audio_sent'] ?? null,
            $events['input_committed'] ?? null,
        );

        $firstTranscript = $events['first_input_transcript_delta'] ?? null;
        $firstTranslation = $events['first_output_translation_delta'] ?? null;
        $translationComplete = self::firstDefined(
            $events['output_translation_completed'] ?? null,
            $events['response_completed'] ?? null,
        );

        $speechStopped = $events['vad_speech_stopped'] ?? null;
        $inputCommitted = $events['input_committed'] ?? null;
        $audioStart = $events['audio_start'] ?? null;
        $firstAudioSent = $events['first_audio_sent'] ?? null;

        return [
            'vad_duration' => $speechStopped !== null && $speechStart !== null
                ? $speechStopped - $speechStart
                : null,
            'audio_upload_duration' => $firstAudioSent !== null && $audioStart !== null
                ? $firstAudioSent - $audioStart
                : null,
            'time_speech_start_to_first_transcript' => $firstTranscript !== null && $speechStart !== null
                ? $firstTranscript - $speechStart
                : null,
            'time_speech_start_to_first_translation' => $firstTranslation !== null && $speechStart !== null
                ? $firstTranslation - $speechStart
                : null,
            'time_first_transcript_to_first_translation' => $firstTranscript !== null && $firstTranslation !== null
                ? $firstTranslation - $firstTranscript
                : null,
            'time_speech_start_to_translation_complete' => $translationComplete !== null && $speechStart !== null
                ? $translationComplete - $speechStart
                : null,
            'time_speech_start_to_speech_stopped' => $speechStopped !== null && $speechStart !== null
                ? $speechStopped - $speechStart
                : null,
            'time_speech_stop_to_input_commit' => $inputCommitted !== null && $speechStopped !== null
                ? $inputCommitted - $speechStopped
                : null,
        ];
    }

    private static function firstDefined(?float ...$values): ?float
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
