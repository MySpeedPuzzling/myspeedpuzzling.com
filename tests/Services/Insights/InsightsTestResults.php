<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Results\InsightsContext;
use SpeedPuzzling\Web\Results\InsightsSoloResult;
use SpeedPuzzling\Web\Services\Insights\InsightsRow;
use SpeedPuzzling\Web\Services\Insights\InsightsRowsPreparer;
use SpeedPuzzling\Web\Services\Insights\LearningFactors;
use SpeedPuzzling\Web\Value\TimePredictionMethod;

/**
 * Synthetic solo results for the pure builder tests - a fixed "now", one helper per field set that matters.
 */
final class InsightsTestResults
{
    public const string NOW = '2026-10-15 12:00:00';

    private static int $sequence = 0;

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $overrides InsightsSoloResult constructor arguments, plus `days_ago`
     */
    public static function result(array $overrides = []): InsightsSoloResult
    {
        self::$sequence++;
        $daysAgo = $overrides['days_ago'] ?? 10;
        unset($overrides['days_ago']);
        assert(is_int($daysAgo) || is_float($daysAgo));

        $solvedAt = self::now()->setTime(0, 0)->modify(sprintf('-%d days', (int) $daysAgo));

        $arguments = [
            'timeId' => sprintf('00000000-0000-7000-8000-%012d', self::$sequence),
            'puzzleId' => sprintf('00000000-0000-7000-9000-%012d', self::$sequence),
            'seconds' => 3000,
            'solvedAt' => $solvedAt,
            'trackedAt' => $solvedAt->setTime(18, 30),
            'finishedAt' => $solvedAt,
            'firstAttempt' => true,
            'unboxed' => false,
            'attempt' => 1,
            'atEvent' => false,
            'onShelf' => false,
            'piecesCount' => 500,
            'manufacturerId' => '00000000-0000-7000-a000-000000000001',
            'manufacturerName' => 'Brand One',
            'puzzleMedianSeconds' => 3000,
            'puzzleSoloTimesCount' => 20,
            'difficultyScore' => 1.0,
            'difficultyTier' => 3,
            'difficultyConfidence' => 'high',
            'difficultySampleSize' => 20,
            'sumLogIndices' => null,
            'memorabilityScore' => null,
            'skillSensitivityScore' => null,
            'predictabilityScore' => null,
            'boxDependenceScore' => null,
            'improvementCeilingScore' => null,
            'predictable' => null,
            'predictionMethod' => null,
            'predictedSeconds' => null,
            'predictedRangeLowSeconds' => null,
            'predictedRangeHighSeconds' => null,
            'predictedAttemptNumber' => null,
            'predictionModelVersion' => null,
            'imageTagKeys' => [],
            'predictedRange80LowSeconds' => null,
            'predictedRange80HighSeconds' => null,
        ];

        foreach ($overrides as $key => $value) {
            assert(array_key_exists($key, $arguments), "Unknown field {$key}");
            $arguments[$key] = $value;
        }

        /**
         * @var array{
         *     timeId: string, puzzleId: string, seconds: int, solvedAt: DateTimeImmutable, trackedAt: DateTimeImmutable,
         *     finishedAt: null|DateTimeImmutable, firstAttempt: bool, unboxed: bool, attempt: int, atEvent: bool, onShelf: bool,
         *     piecesCount: int, manufacturerId: null|string, manufacturerName: null|string, puzzleMedianSeconds: null|int,
         *     puzzleSoloTimesCount: int, difficultyScore: null|float, difficultyTier: null|int, difficultyConfidence: null|string,
         *     difficultySampleSize: int, sumLogIndices: null|float, memorabilityScore: null|float, skillSensitivityScore: null|float,
         *     predictabilityScore: null|float, boxDependenceScore: null|float, improvementCeilingScore: null|float,
         *     predictable: null|bool, predictionMethod: null|TimePredictionMethod, predictedSeconds: null|int,
         *     predictedRangeLowSeconds: null|int, predictedRangeHighSeconds: null|int, predictedAttemptNumber: null|int,
         *     predictionModelVersion: null|int, imageTagKeys: list<string>, predictedRange80LowSeconds: null|int,
         *     predictedRange80HighSeconds: null|int,
         * } $arguments
         */
        return new InsightsSoloResult(...$arguments);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function predicted(int $seconds, int $predicted, int $modelVersion, array $overrides = [], null|int $low = null, null|int $high = null): InsightsSoloResult
    {
        return self::result([
            'seconds' => $seconds,
            'predictable' => true,
            'predictionMethod' => TimePredictionMethod::Statistical,
            'predictedSeconds' => $predicted,
            'predictedRangeLowSeconds' => $low ?? (int) round($predicted * 0.95),
            'predictedRangeHighSeconds' => $high ?? (int) round($predicted * 1.05),
            'predictionModelVersion' => $modelVersion,
            ...$overrides,
        ]);
    }

    /**
     * @param list<InsightsSoloResult> $results oldest first
     * @return list<InsightsRow>
     */
    public static function rows(array $results, null|DateTimeZone $timezone = null): array
    {
        usort($results, static fn (InsightsSoloResult $a, InsightsSoloResult $b): int => [$a->solvedAt, $a->trackedAt, $a->timeId] <=> [$b->solvedAt, $b->trackedAt, $b->timeId]);

        return (new InsightsRowsPreparer())->prepare($results, LearningFactors::none(), $timezone);
    }

    /**
     * Results whose ratio equals `ratio` on puzzles with the median 3000 s, first tries spread over the last `days`.
     *
     * @param array<string, mixed> $overrides
     * @return list<InsightsSoloResult>
     */
    public static function series(int $count, float $ratio, int $days = 300, array $overrides = []): array
    {
        $results = [];

        for ($i = 0; $i < $count; $i++) {
            $results[] = self::result([
                'seconds' => (int) round(3000 * $ratio),
                'days_ago' => (int) round($days - $i * $days / max(1, $count)),
                ...$overrides,
            ]);
        }

        return $results;
    }

    public static function context(): InsightsContext
    {
        return InsightsContext::empty();
    }
}
