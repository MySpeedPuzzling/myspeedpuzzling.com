<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation;

use SpeedPuzzling\Web\Value\StoredPredictionWindows;
use SpeedPuzzling\Web\Value\WindowRefit;

/**
 * The gate v2 numbers of one group of stored predictions, whatever predictor and model version made them
 * (docs/features/insights/image-estimate-gates.md): the windows' out-of-period honesty, the skill against a reference
 * per k bucket, and slices of the same rows (live vs reconstructed, …) with their errors and in-sample hits.
 */
final class PredictionGroupAccumulator
{
    public const string REPEAT = 'repeat';

    private const int DECIMALS = 4;

    public readonly OutOfPeriodCalibration $calibration;

    /** @var array<string, PairedSkill> */
    private array $skill = [];

    /** @var array<string, array{0: PairedSkill, 1: int, 2: int, 3: int}> per slice: paired errors, rows, 50 % hits, 80 % hits */
    private array $slices = [];

    /**
     * @param null|WindowRefit $refit how the honesty test refits the windows - the subject's own fit (default: equal weight)
     */
    public function __construct(null|WindowRefit $refit = null)
    {
        $this->calibration = new OutOfPeriodCalibration($refit);
    }

    /**
     * @param StoredPredictionWindows $shown the prediction whose windows are judged for honesty
     * @param null|StoredPredictionWindows $candidate the prediction whose error is compared with the reference (null = none)
     * @param list<string> $slices the slices the solve belongs to
     */
    public function add(
        null|int $kIndices,
        float $solvedAt,
        int $actualSeconds,
        StoredPredictionWindows $shown,
        null|StoredPredictionWindows $candidate,
        null|StoredPredictionWindows $reference,
        array $slices = [],
    ): void {
        $this->calibration->add($solvedAt, $actualSeconds, $shown, $kIndices);

        $paired = $candidate !== null && $reference !== null;

        if ($paired) {
            ($this->skill[self::kBucket($kIndices)] ??= new PairedSkill(withWidths: true))->add($actualSeconds, $candidate, $reference);
        }

        foreach ($slices as $slice) {
            $this->slices[$slice] ??= [new PairedSkill(), 0, 0, 0];
            $this->slices[$slice][1]++;
            $this->slices[$slice][2] += self::inside($actualSeconds, $shown->range50Low, $shown->range50High);
            $this->slices[$slice][3] += self::inside($actualSeconds, $shown->range80Low, $shown->range80High);

            if ($paired) {
                $this->slices[$slice][0]->add($actualSeconds, $candidate, $reference);
            }
        }
    }

    /**
     * `skill` is a list of k buckets ({"k": "0", …} - a JSON object keyed "0" would come back as a list)
     *
     * @return array{calibration: array<string, mixed>, skill: list<array<string, mixed>>, slices: array<string, array<string, mixed>>}
     */
    public function metrics(null|float $splitAt): array
    {
        $skill = [];

        foreach (self::K_BUCKETS as $bucket) {
            if (isset($this->skill[$bucket])) {
                $skill[] = ['k' => $bucket] + $this->skill[$bucket]->metrics();
            }
        }

        $slices = [];

        foreach ($this->slices as $name => [$errors, $rows, $hit50, $hit80]) {
            $paired = $errors->metrics();
            $slices[$name] = [
                'rows' => $rows,
                'window50Hit' => $rows > 0 ? round($hit50 / $rows, self::DECIMALS) : null,
                'window80Hit' => $rows > 0 ? round($hit80 / $rows, self::DECIMALS) : null,
                'pairedRows' => $paired['rows'],
                'maeCandidate' => $paired['maeCandidate'],
                'maeReference' => $paired['maeReference'],
            ];
        }

        return [
            'calibration' => $splitAt !== null ? $this->calibration->metrics($splitAt) : [],
            'skill' => $skill,
            'slices' => $slices,
        ];
    }

    /** The k buckets of every evaluation, in order (k = the puzzle's difficulty index count when predicted) */
    public const array K_BUCKETS = ['0', '1-2', '3-4', '5-9', '10-19', '20+', self::REPEAT];

    /**
     * @param null|int $kIndices null = a repeat (the player's own times, no puzzle difficulty)
     */
    public static function kBucket(null|int $kIndices): string
    {
        return match (true) {
            $kIndices === null => self::REPEAT,
            $kIndices <= 0 => '0',
            $kIndices <= 2 => '1-2',
            $kIndices <= 4 => '3-4',
            $kIndices <= 9 => '5-9',
            $kIndices <= 19 => '10-19',
            default => '20+',
        };
    }

    /**
     * One k bucket's skill numbers from stored metrics (metrics()['skill']), empty when the bucket had no paired solves
     *
     * @return array<string, mixed>
     */
    public static function skillBucket(mixed $skill, string $bucket): array
    {
        if (!is_array($skill)) {
            return [];
        }

        foreach ($skill as $entry) {
            if (is_array($entry) && ($entry['k'] ?? null) === $bucket) {
                /** @var array<string, mixed> $entry */
                return $entry;
            }
        }

        return [];
    }

    private static function inside(int $actual, int $low, int $high): int
    {
        return $actual >= $low && $actual <= $high ? 1 : 0;
    }
}
