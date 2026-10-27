<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\AdjustedPace;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePaceOffsets;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\FirstAttemptSet;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimeline;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SplFixedArray;
use Symfony\Component\Clock\MockClock;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\TestClock;

/**
 * The candidate's difficulty-adjusted pace (v7, README "Candidate v7"): the median of ln seconds - log difficulty and
 * its own drift instead of the raw median and the shown drift - a player who picks harder puzzles is as fast as they
 * are on the average puzzle.
 */
final class AdjustedPaceTest extends TestCase
{
    private const float DAY = 86400.0;

    /**
     * Ten first tries, each exactly as long as the puzzle's difficulty says from a 3000 s level: the raw median carries
     * the puzzles' difficulty (+0.2), the adjusted one does not - the offset takes it back; every time is exactly on the
     * adjusted pace, so its own drift is 0.
     */
    public function testAPlayerWhoPicksHarderPuzzlesIsAsFastAsTheirLevel(): void
    {
        [$set, $difficulty] = $this->pair(array_fill(0, 10, 0.2), static fn (float $d): int => (int) round(3000 * exp($d)));
        $adjusted = AdjustedPace::compute($set, $difficulty, array_fill(0, $set->count(), 0.0));

        for ($i = 0; $i < 4; $i++) {
            self::assertNull($adjusted->offsetAfter($i), 'No pace before the 5th first try');
            self::assertNull($adjusted->selectionAfter($i));
        }

        for ($i = 4; $i < 10; $i++) {
            self::assertEqualsWithDelta(-0.2, (float) $adjusted->offsetAfter($i), 0.001);
            self::assertEqualsWithDelta(0.2, (float) $adjusted->selectionAfter($i), 1e-9);
        }
    }

    /**
     * The shown drift has taken part of the selection already (its residuals against the raw pace): the offset replaces
     * it with the drift against the adjusted pace, so the level used - ln raw pace + shown drift + offset - is the
     * player's level once.
     */
    public function testTheShownDriftIsReplacedNotAddedTo(): void
    {
        [$set, $difficulty] = $this->pair(array_fill(0, 10, 0.2), static fn (float $d): int => (int) round(3000 * exp($d)));
        $adjusted = AdjustedPace::compute($set, $difficulty, array_fill(0, $set->count(), -0.1));

        // ln 3000 + 0.2 - 0.1 + offset = ln 3000
        self::assertEqualsWithDelta(-0.1, (float) $adjusted->offsetAfter(9), 0.001);
    }

    /**
     * Rows are as of their attempt: the offset at a moment is the one after the pair's last attempt strictly before it;
     * a player who turns to harder puzzles keeps their level.
     */
    public function testTheOffsetAtAMomentIsTheOneAfterTheLastAttemptBeforeIt(): void
    {
        // Five easy puzzles, then five hard ones, every time on the 3000 s level
        [$set, $difficulty] = $this->pair([...array_fill(0, 5, -0.1), ...array_fill(0, 5, 0.3)], static fn (float $d): int => (int) round(3000 * exp($d)));
        $adjusted = AdjustedPace::compute($set, $difficulty, array_fill(0, $set->count(), 0.0));

        self::assertSame(0.0, $adjusted->offsetAt('a|500', 3 * self::DAY, 3 * self::DAY), 'before the first row');
        self::assertNull($adjusted->rowBefore('a|500', 5 * self::DAY, 5 * self::DAY), 'the 5th first try itself is not before its moment');
        self::assertEqualsWithDelta(0.1, $adjusted->offsetAt('a|500', 5.5 * self::DAY, 5.5 * self::DAY), 0.001);
        self::assertEqualsWithDelta(-0.1, (float) $adjusted->selectionAt('a|500', 5.5 * self::DAY, 5.5 * self::DAY), 1e-9);
        // Ten days, no decay: the raw median is an easy one's, the level stays 3000 s
        self::assertEqualsWithDelta(0.1, (float) $adjusted->selectionAt('a|500', 100 * self::DAY, 100 * self::DAY), 1e-9);
        self::assertEqualsWithDelta(0.1, $adjusted->offsetAt('a|500', 100 * self::DAY, 100 * self::DAY), 0.001);
        self::assertSame(0.0, $adjusted->offsetAt('b|500', 100 * self::DAY, 100 * self::DAY), 'a pair without rows');
        self::assertCount(1, array_unique(array_map(static fn (array $row): float => $row[1], iterator_to_array($adjusted->rows(), false))), 'one stored value: it never moved');
    }

    /**
     * The pace's weights: older first tries count less after the 3-month plateau (PlayerBaselineCalculator's rule) - a
     * player who picked hard puzzles years ago and easy ones now is read by the easy ones.
     */
    public function testOldFirstTriesWeighLess(): void
    {
        $moments = [];

        for ($i = 0; $i < 10; $i++) {
            $moments[] = $i < 5 ? (1 + $i) * self::DAY : (1500 + $i) * self::DAY;
        }

        [$set, $difficulty] = $this->pair([...array_fill(0, 5, 0.3), ...array_fill(0, 5, -0.1)], static fn (float $d): int => (int) round(3000 * exp($d)), $moments);
        $adjusted = AdjustedPace::compute($set, $difficulty, array_fill(0, $set->count(), 0.0));

        // Four years later the old ones weigh exp(-(49.4 - 3) / 13.5) ≈ 0.032 each
        $weight = exp(-((1504 - 5) * self::DAY / (30.4375 * self::DAY) - 3.0) / 13.5);
        $expected = (5 * $weight * 0.3 + 5 * -0.1) / (5 * $weight + 5);
        self::assertEqualsWithDelta($expected, (float) $adjusted->selectionAfter(9), 0.002);
        self::assertEqualsWithDelta(0.1, (float) $adjusted->offsetAfter(9), 0.001, 'the raw median: an easy one now');
    }

    /**
     * What the daily fit stores (a row where a pair's offset moved, 4 decimals) is exactly what it computed with: read
     * back as of every attempt (the 15-minute refresh, the reconstruction) it gives the same offsets.
     */
    public function testTheStoredOffsetsGiveBackTheComputedOnes(): void
    {
        $difficulties = [];

        for ($n = 0; $n < 30; $n++) {
            $difficulties[] = 0.05 * sin($n) + ($n > 15 ? 0.1 : 0.0);
        }

        [$set, $difficulty] = $this->pair($difficulties, static fn (float $d): int => (int) round(3000 * exp($d + 0.03 * cos(3 * $d))));
        $computed = AdjustedPace::compute($set, $difficulty, array_fill(0, $set->count(), 0.02));
        $stored = new CandidatePaceOffsets();
        $last = null;

        foreach ($computed->rows() as [$i, $offset]) {
            self::assertSame(round($offset, 4), $offset, 'quantised to 4 decimals');

            if ($offset !== $last) {
                $stored->add($set->player[$i], $set->pieces[$i], $set->solved[$i], $set->tracked[$i], $offset);
                $last = $offset;
            }
        }

        $read = AdjustedPace::fromStored($set, $stored);

        for ($i = 0; $i < $set->count(); $i++) {
            self::assertSame($computed->offsetAfter($i), $read->offsetAfter($i));
        }

        self::assertSame($computed->offsetAt('a|500', 20 * self::DAY + 1, 0.0), $read->offsetAt('a|500', 20 * self::DAY + 1, 0.0));
    }

    /**
     * @param list<float> $logDifficulty of each first try's puzzle
     * @param \Closure(float): int $seconds
     * @param null|list<float> $moments
     * @return array{0: FirstAttemptSet, 1: SplFixedArray<null|float>}
     */
    private function pair(array $logDifficulty, \Closure $seconds, null|array $moments = null): array
    {
        $rows = [];

        foreach ($logDifficulty as $n => $d) {
            $moment = $moments[$n] ?? ($n + 1) * self::DAY;
            $rows[] = [
                'id' => sprintf('00000000-0000-7000-8000-%012d', $n),
                'player_id' => 'a',
                'puzzle_id' => 'puzzle-' . $n,
                'pieces_count' => 500,
                'seconds_to_solve' => $seconds($d),
                'solved_ts' => $moment,
                'tracked_ts' => $moment,
            ];
        }

        $set = FirstAttemptSet::build($rows, new PaceTimeline(new PlayerBaselineCalculator($this->createStub(Connection::class), new MockClock(TestClock::now()))));
        /** @var SplFixedArray<null|float> $difficulty */
        $difficulty = new SplFixedArray($set->count());

        for ($i = 0; $i < $set->count(); $i++) {
            $difficulty[$i] = $logDifficulty[(int) substr($set->puzzle[$i], 7)];
        }

        return [$set, $difficulty];
    }
}
