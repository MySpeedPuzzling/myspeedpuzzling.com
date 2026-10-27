<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\ImageEstimates;

/**
 * One size group × image kind of a gate v2 record whose numbers pass every rule of ImageEstimateGate - for tests that
 * store a record by hand (the evaluation itself: ImageEstimateEvaluatorTest).
 */
final class GateV2Numbers
{
    /**
     * @return array<string, mixed>
     */
    public static function passing(string $sizeGroup, string $imageKind, string $generation, int $measuredPuzzles = 120): array
    {
        return [
            'sizeGroup' => $sizeGroup,
            'imageKind' => $imageKind,
            'rows' => 4000,
            'measuredPuzzles' => $measuredPuzzles,
            'spearman' => 0.72,
            // Exact tier is reported, never gated
            'tierExact' => 0.41,
            'tierWithinOne' => 0.9,
            'alwaysAverage' => 0.46,
            'estimateModels' => [$generation => 4000],
            'calibration' => self::calibration(),
            'skill' => self::skill(),
        ];
    }

    /**
     * Honest windows out of period, not open before
     *
     * @param array<string, mixed> $change
     * @return array<string, mixed>
     */
    public static function calibration(array $change = []): array
    {
        return [
            'fitRows' => 2000,
            'testRows' => 2000,
            'blocks' => 6,
            'hit50' => 0.505,
            'hit50Low' => 0.487,
            'hit50High' => 0.523,
            'hit80' => 0.80,
            'hit80Low' => 0.785,
            'hit80High' => 0.814,
            'previouslyOpen' => false,
            ...$change,
        ];
    }

    /**
     * Skill passing at k = 0, 1-2 and 3-4 - one bucket changed by $change
     *
     * @param array<string, mixed> $change
     * @return list<array<string, mixed>>
     */
    public static function skill(string $bucket = '', array $change = []): array
    {
        $skill = [
            ['k' => '0', 'rows' => 1500, 'maeCandidate' => 0.15, 'maeReference' => 0.19, 'errorRatio' => 0.7895, 'meanDifference' => -0.04, 'differenceSe' => 0.004, 'width80Candidate' => 0.46, 'width80Reference' => 0.57],
            ['k' => '1-2', 'rows' => 1000, 'maeCandidate' => 0.13, 'maeReference' => 0.135, 'meanDifference' => -0.005, 'differenceSe' => 0.001],
            ['k' => '3-4', 'rows' => 400, 'maeCandidate' => 0.11, 'maeReference' => 0.111, 'meanDifference' => -0.001, 'differenceSe' => 0.001],
        ];

        foreach ($skill as $i => $entry) {
            if ($entry['k'] === $bucket) {
                $skill[$i] = [...$entry, ...$change];
            }
        }

        return $skill;
    }
}
