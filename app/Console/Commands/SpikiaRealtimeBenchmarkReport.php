<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * PoC/benchmark (ver informe "menor latencia posible"): lee las entradas
 * "spikia.realtime_benchmark" del log del dia (escritas por
 * TraduccionController::storeRealtimeBenchmark) y arma el reporte por brazo:
 * Frase | STT | Realtime | Pusher | 1er resultado | Total, con promedio/minimo/maximo,
 * y el criterio de exito (Excelente/Objetivo/Aceptable/No cumple).
 *
 * IMPORTANTE (correccion frente al plan original): el STT en vivo de Spikia hoy NO usa
 * Deepgram — master.blade.php trae 'useDeepgram' => false y usa el reconocimiento nativo
 * del navegador (Web Speech API). El codigo de Deepgram existe en master.js pero esta
 * deshabilitado en produccion. Por eso la columna se llama "STT" y no "Deepgram".
 */
class SpikiaRealtimeBenchmarkReport extends Command
{
    protected $signature = 'spikia:realtime-benchmark:report {--date= : YYYY-MM-DD, por defecto hoy} {--limit=50 : Maximo de frases a listar por brazo}';

    protected $description = 'Reporte del PoC de traduccion ES->EN via OpenAI Realtime (brazo texto vs. brazo audio): STT | Realtime | Pusher | 1er resultado | Total';

    private const ARM_LABELS = [
        'text' => 'Texto — Web Speech API -> Realtime texto',
        'audio' => 'Brazo A — Audio -> gpt-realtime-2.1 (VAD configurable)',
        'translate_dedicated' => 'Brazo B — Audio -> gpt-realtime-translate (streaming continuo)',
    ];

    public function handle(): int
    {
        $date = $this->option('date') ?: now()->format('Y-m-d');
        $limit = (int) $this->option('limit');
        $logPath = storage_path("logs/laravel-{$date}.log");

        if (! is_file($logPath)) {
            $this->error("No existe el log de ese dia: {$logPath}");

            return self::FAILURE;
        }

        $entries = $this->parseBenchmarkEntries($logPath);

        if ($entries === []) {
            $this->warn("No hay entradas 'spikia.realtime_benchmark' en {$date}. ¿Corriste una sesion Master con SPIKIA_TRANSLATION_ENGINE=realtime_experimental o realtime_audio_experimental?");

            return self::SUCCESS;
        }

        $byArm = [];
        foreach ($entries as $entry) {
            $arm = $entry['arm'] ?? 'text';
            $byArm[$arm][] = $entry;
        }

        foreach ($byArm as $arm => $armEntries) {
            $this->newLine();
            $this->info((self::ARM_LABELS[$arm] ?? $arm) . " — {$date} (" . count($armEntries) . ' frases)');
            $this->renderArmReport($armEntries, $limit);
        }

        if (count($byArm) > 1) {
            $this->newLine();
            $this->comparePromedios($byArm);
        }

        if (isset($byArm['audio'], $byArm['translate_dedicated'])) {
            $this->newLine();
            $this->recomendarAvsB($byArm['audio'], $byArm['translate_dedicated']);
        }

        return self::SUCCESS;
    }

    private function renderArmReport(array $entries, int $limit): void
    {
        $isDedicated = ($entries[0]['arm'] ?? null) === 'translate_dedicated';
        if ($isDedicated) {
            $this->comment('OJO: en este brazo "t_speech_start" es una APROXIMACION (primer delta observado) — gpt-realtime-translate no expone VAD, no hay señal real de inicio de audio. Ver informe.');
        }

        $rows = [];
        foreach (array_slice($entries, -$limit) as $entry) {
            $rows[] = [
                $entry['texto'] ?? '',
                $this->fmt($entry['ms_speech_to_first_transcript'] ?? null),
                $this->fmt($entry['ms_speech_to_first_result'] ?? null),
                $this->fmt($entry['ms_server_to_pusher'] ?? null),
                $this->fmt($entry['ms_total_speech_to_pusher'] ?? null),
            ];
        }

        $this->table(['Frase', '1er transcript', '1er traduccion', 'Pusher', 'Total'], $rows);

        $totals = $this->extractValues($entries, 'ms_total_speech_to_pusher');
        $firstResults = $this->extractValues($entries, 'ms_speech_to_first_result');

        if ($totals !== []) {
            $avg = array_sum($totals) / count($totals);
            $this->line(sprintf(
                'Delay TOTAL (habla -> Pusher):        promedio %sms   minimo %sms   maximo %sms   %s',
                number_format($avg, 0),
                number_format(min($totals), 0),
                number_format(max($totals), 0),
                $this->criterio($avg)
            ));
        }

        if ($firstResults !== []) {
            $avgFirst = array_sum($firstResults) / count($firstResults);
            $this->line(sprintf(
                'FIRST RESULT LATENCY (habla -> 1er delta): promedio %sms   minimo %sms   maximo %sms   %s',
                number_format($avgFirst, 0),
                number_format(min($firstResults), 0),
                number_format(max($firstResults), 0),
                $this->criterio($avgFirst)
            ));
        } else {
            $this->comment('(Este brazo no reporto first-result-latency — solo aplica cuando el cliente manda t_first_result.)');
        }

        $this->comment('Desglose promedio por etapa (ms):');
        foreach ([
            'ms_speech_to_stt_final' => 'Habla -> STT/transcript final',
            'ms_speech_to_first_transcript' => 'Habla -> primer delta de TRANSCRIPCION',
            'ms_stt_to_realtime_sent' => 'STT final -> enviado a Realtime',
            'ms_realtime_translate' => 'Traduccion en Realtime (envio -> resultado final)',
            'ms_speech_to_first_result' => 'Habla -> primer delta de TRADUCCION',
            'ms_first_to_final_result' => 'Primer resultado -> resultado final',
            'ms_network_to_server' => 'Navegador -> servidor (POST benchmark)',
            'ms_server_to_pusher' => 'Servidor -> publicado en Pusher/Echo',
        ] as $key => $label) {
            $values = $this->extractValues($entries, $key);
            if ($values === []) {
                continue;
            }
            $this->line(sprintf('  %-45s %sms', $label, number_format(array_sum($values) / count($values), 1)));
        }
    }

    private function comparePromedios(array $byArm): void
    {
        $this->info('Comparacion entre brazos (promedio de delay total):');
        $summary = [];
        foreach ($byArm as $arm => $entries) {
            $totals = $this->extractValues($entries, 'ms_total_speech_to_pusher');
            $firsts = $this->extractValues($entries, 'ms_speech_to_first_result');
            $summary[] = [
                self::ARM_LABELS[$arm] ?? $arm,
                $totals !== [] ? number_format(array_sum($totals) / count($totals), 0) . 'ms' : '-',
                $firsts !== [] ? number_format(array_sum($firsts) / count($firsts), 0) . 'ms' : '-',
            ];
        }
        $this->table(['Brazo', 'Total promedio', 'First result promedio'], $summary);
    }

    /**
     * Recomendacion automatica pedida: compara A (gpt-realtime-2.1 + VAD) vs B
     * (gpt-realtime-translate) por first-result-latency (la metrica que mas te importa) y
     * por latencia total, y dice cual gana en cada una.
     */
    private function recomendarAvsB(array $armA, array $armB): void
    {
        $this->info('=== Recomendacion A vs B ===');

        $totalsA = $this->extractValues($armA, 'ms_total_speech_to_pusher');
        $totalsB = $this->extractValues($armB, 'ms_total_speech_to_pusher');
        $firstA = $this->extractValues($armA, 'ms_speech_to_first_result');
        $firstB = $this->extractValues($armB, 'ms_speech_to_first_result');

        if ($totalsA === [] || $totalsB === []) {
            $this->warn('Faltan datos de alguno de los dos brazos para comparar.');

            return;
        }

        $avgTotalA = array_sum($totalsA) / count($totalsA);
        $avgTotalB = array_sum($totalsB) / count($totalsB);
        $avgFirstA = $firstA !== [] ? array_sum($firstA) / count($firstA) : null;
        $avgFirstB = $firstB !== [] ? array_sum($firstB) / count($firstB) : null;

        $this->line(sprintf('Latencia TOTAL promedio:          A = %sms   B = %sms   -> %s',
            number_format($avgTotalA, 0), number_format($avgTotalB, 0),
            $avgTotalA < $avgTotalB ? 'gana A' : 'gana B'));

        if ($avgFirstA !== null && $avgFirstB !== null) {
            $this->line(sprintf('FIRST RESULT LATENCY promedio:    A = %sms   B = %sms   -> %s',
                number_format($avgFirstA, 0), number_format($avgFirstB, 0),
                $avgFirstA < $avgFirstB ? 'gana A' : 'gana B'));
        }

        $this->newLine();
        $this->comment('Recordatorio de las limitaciones de cada brazo (no solo mires el numero):');
        $this->line('  A: VAD configurable, resultado final limpio (response.done), soporta glosario/prompt.');
        $this->line('  B: sin VAD (segmentacion interna del modelo), sin evento de "fin" real (heuristica propia de Spikia), NO soporta glosario/prompt, y su "inicio de audio" es una aproximacion (ver arriba).');
    }

    /**
     * Criterio de exito pedido: Excelente <1.5s, Objetivo 1.5-2.0s, Aceptable 2-3s, No cumple >3s.
     */
    private function criterio(float $ms): string
    {
        return match (true) {
            $ms < 1500 => '✅ Excelente (<1.5s)',
            $ms <= 2000 => '🟢 Objetivo (1.5-2.0s)',
            $ms <= 3000 => '🟡 Aceptable (2-3s)',
            default => '🔴 No cumple (>3s)',
        };
    }

    private function extractValues(array $entries, string $key): array
    {
        return array_values(array_filter(
            array_map(fn ($e) => $e[$key] ?? null, $entries),
            fn ($v) => $v !== null
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseBenchmarkEntries(string $logPath): array
    {
        $entries = [];
        $handle = fopen($logPath, 'r');

        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $marker = 'spikia.realtime_benchmark: ';
            $pos = strpos($line, $marker);
            if ($pos === false) {
                continue;
            }

            $jsonPart = substr($line, $pos + strlen($marker));
            $decoded = json_decode(trim($jsonPart), true);

            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        fclose($handle);

        return $entries;
    }

    private function fmt(?float $ms): string
    {
        return $ms === null ? '-' : number_format($ms, 0) . 'ms';
    }
}
