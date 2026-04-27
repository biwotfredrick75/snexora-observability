<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

trait StatisticalAnalysisTrait
{
    // ── Statistical Helpers ───────────────────────────────────────────────────

    /** Simple linear regression on a numeric array. Returns slope, intercept, R². */
    protected function linearRegression(array $y): array
    {
        $n = count($y);
        if ($n < 2) {
            return ['slope' => 0, 'intercept' => $y[0] ?? 0, 'r2' => 0];
        }
        $x     = range(0, $n - 1);
        $sumX  = array_sum($x);
        $sumY  = array_sum($y);
        $sumXY = 0;
        $sumX2 = 0;
        foreach ($x as $i => $xi) {
            $sumXY += $xi * $y[$i];
            $sumX2 += $xi * $xi;
        }
        $denom     = $n * $sumX2 - $sumX * $sumX;
        $slope     = $denom != 0 ? ($n * $sumXY - $sumX * $sumY) / $denom : 0;
        $intercept = ($sumY - $slope * $sumX) / $n;

        $yMean = $sumY / $n;
        $ssTot = 0;
        $ssRes = 0;
        foreach ($y as $i => $yi) {
            $yHat   = $slope * $i + $intercept;
            $ssTot += ($yi - $yMean) ** 2;
            $ssRes += ($yi - $yHat) ** 2;
        }
        $r2 = $ssTot > 0 ? 1 - $ssRes / $ssTot : 0;

        return ['slope' => $slope, 'intercept' => $intercept, 'r2' => round($r2, 4)];
    }

    /**
     * Day-of-week seasonal factors (0=Sun … 6=Sat), normalised around 1.0.
     *
     * @param  array  $dailyRows  Array of assoc arrays, each with $dateField and $valueField keys.
     * @param  string $dateField  Key for the date string in each row (default: 'date').
     * @param  string $valueField Key for the numeric value in each row (default: 'qty').
     */
    protected function seasonalFactors(array $dailyRows, string $dateField = 'date', string $valueField = 'qty'): array
    {
        $buckets = array_fill(0, 7, ['sum' => 0, 'count' => 0]);
        foreach ($dailyRows as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $dow = Carbon::parse($row[$dateField])->dayOfWeek;
            $buckets[$dow]['sum']   += (float)($row[$valueField] ?? 0);
            $buckets[$dow]['count'] += 1;
        }
        $avgs  = [];
        $grand = 0;
        foreach ($buckets as $dow => $b) {
            $avgs[$dow] = $b['count'] > 0 ? $b['sum'] / $b['count'] : 0;
            $grand     += $avgs[$dow];
        }
        $mean    = $grand / 7;
        $factors = [];
        foreach ($avgs as $dow => $avg) {
            $factors[$dow] = $mean > 0 ? $avg / $mean : 1.0;
        }
        return $factors;
    }

    /** Mean Absolute Error of linear-fit residuals. */
    protected function mae(array $y, float $slope, float $intercept): float
    {
        $n = count($y);
        if ($n === 0) return 0;
        $sum = 0;
        foreach ($y as $i => $yi) {
            $sum += abs($yi - ($slope * $i + $intercept));
        }
        return $sum / $n;
    }

    /** Rolling average over an array of values (right-aligned window). */
    protected function movingAvg(array $values, int $window = 7): array
    {
        $result = [];
        foreach ($values as $i => $v) {
            $start    = max(0, $i - $window + 1);
            $slice    = array_slice($values, $start, $i - $start + 1);
            $result[] = count($slice) ? array_sum($slice) / count($slice) : 0;
        }
        return $result;
    }

    /**
     * Detect anomalies: rows whose value deviates > 2 std dev from the mean.
     *
     * @param  array  $values     Flat numeric array (same order as $rows).
     * @param  array  $rows       Assoc array rows corresponding to $values.
     * @param  string $dateField  Key for the date string.
     * @param  string $valueField Key for the numeric value.
     * @return array  Each entry: ['date', 'value', 'type' => 'spike'|'drop']
     */
    protected function detectAnomalies(
        array $values,
        array $rows,
        string $dateField = 'date',
        string $valueField = 'qty'
    ): array {
        if (count($values) < 3) return [];

        $mean = array_sum($values) / count($values);
        $var  = 0;
        foreach ($values as $v) { $var += ($v - $mean) ** 2; }
        $std = sqrt($var / count($values));

        $result = [];
        foreach ($rows as $i => $row) {
            $row = is_array($row) ? $row : (array) $row;
            $v   = (float)($row[$valueField] ?? 0);
            if ($std > 0 && abs($v - $mean) > 2 * $std) {
                $result[] = [
                    'date'  => $row[$dateField],
                    'value' => round($v, 2),
                    'type'  => $v > $mean ? 'spike' : 'drop',
                ];
            }
        }
        return $result;
    }

    // ── Shared Groq HTTP Helper ───────────────────────────────────────────────

    /**
     * Call Groq LLaMA and return decoded JSON advice array.
     * Returns ['error' => 'no_key'] when the API key is not configured.
     *
     * @throws \RuntimeException on HTTP or JSON parse errors.
     */
    protected function callGroq(string $prompt, int $maxTokens = 1200): array
    {
        $apiKey = config('services.groq.key');
        if (! $apiKey) {
            return ['error' => 'no_key'];
        }

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'       => 'llama-3.3-70b-versatile',
                'max_tokens'  => $maxTokens,
                'temperature' => 0.3,
                'messages'    => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Groq API error: ' . $response->status());
        }

        $raw = $response->json('choices.0.message.content', '{}');
        $raw = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $raw = preg_replace('/```\s*$/m', '', $raw);

        $decoded = json_decode(trim($raw), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Could not parse Groq response as JSON.');
        }

        return $decoded;
    }

    // ── Groq Conversational (plain-text response) ─────────────────────────────

    /**
     * Send a full messages array to Groq and return the raw text response.
     * Unlike callGroq(), this does NOT force JSON parsing — it returns a string.
     *
     * @param  array{role:string,content:string}[] $messages
     * @throws \RuntimeException on HTTP failure.
     */
    protected function callGroqConversation(array $messages, int $maxTokens = 700): string
    {
        $apiKey = config('services.groq.key');
        if (! $apiKey) {
            return '';
        }

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'       => 'llama-3.3-70b-versatile',
                'max_tokens'  => $maxTokens,
                'temperature' => 0.4,
                'messages'    => $messages,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Groq API error: ' . $response->status());
        }

        return trim($response->json('choices.0.message.content', ''));
    }

    // ── Shared Trend Classifier ───────────────────────────────────────────────

    /**
     * Classify trend direction from slope + base level.
     * Returns 'growing' | 'stable' | 'declining' and percentage per day.
     */
    protected function classifyTrend(float $slope, float $intercept, int $n): array
    {
        $baseLevel  = $intercept + $slope * ($n / 2);
        $trendPct   = $baseLevel > 0 ? round(($slope / $baseLevel) * 100, 2) : 0;
        $trendLabel = abs($trendPct) < 0.3 ? 'stable'
            : ($trendPct > 0 ? 'growing' : 'declining');

        return ['label' => $trendLabel, 'pct' => $trendPct];
    }
}
