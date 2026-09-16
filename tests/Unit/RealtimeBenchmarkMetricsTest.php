<?php

namespace Tests\Unit;

use App\Services\RealtimeBenchmarkMetrics;
use PHPUnit\Framework\TestCase;

class RealtimeBenchmarkMetricsTest extends TestCase
{
    public function test_it_computes_latency_for_audio_pipeline(): void
    {
        $timeline = [
            ['name' => 'audio_start', 'ts' => 1000],
            ['name' => 'first_audio_sent', 'ts' => 1100],
            ['name' => 'vad_speech_started', 'ts' => 1800],
            ['name' => 'first_input_transcript_delta', 'ts' => 2400],
            ['name' => 'input_transcript_completed', 'ts' => 2900],
            ['name' => 'first_output_translation_delta', 'ts' => 3200],
            ['name' => 'output_translation_completed', 'ts' => 4100],
            ['name' => 'response_completed', 'ts' => 4200],
            ['name' => 'vad_speech_stopped', 'ts' => 5000],
        ];

        $metrics = RealtimeBenchmarkMetrics::fromTimeline($timeline);

        $this->assertSame(600.0, $metrics['time_speech_start_to_first_transcript']);
        $this->assertSame(1400.0, $metrics['time_speech_start_to_first_translation']);
        $this->assertSame(800.0, $metrics['time_first_transcript_to_first_translation']);
        $this->assertSame(2300.0, $metrics['time_speech_start_to_translation_complete']);
        $this->assertSame(100.0, $metrics['audio_upload_duration']);
        $this->assertSame(3200.0, $metrics['vad_duration']);
    }

    public function test_it_handles_two_short_phrases_with_small_gap(): void
    {
        $timeline = [
            ['name' => 'audio_start', 'ts' => 0],
            ['name' => 'first_audio_sent', 'ts' => 40],
            ['name' => 'vad_speech_started', 'ts' => 900],
            ['name' => 'first_input_transcript_delta', 'ts' => 1500],
            ['name' => 'first_output_translation_delta', 'ts' => 1800],
            ['name' => 'vad_speech_stopped', 'ts' => 2500],
            ['name' => 'input_committed', 'ts' => 2600],
            ['name' => 'output_translation_completed', 'ts' => 3400],
        ];

        $metrics = RealtimeBenchmarkMetrics::fromTimeline($timeline);

        $this->assertSame(900.0, $metrics['time_speech_start_to_first_translation']);
        $this->assertSame(2500.0, $metrics['time_speech_start_to_translation_complete']);
        $this->assertSame(40.0, $metrics['audio_upload_duration']);
    }
}
