<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Xp;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Xp\XpCalculator;
use SpeedPuzzling\Web\Value\SolveXpContext;
use SpeedPuzzling\Web\Value\SpeedPercentile;

final class XpCalculatorTest extends TestCase
{
    /**
     * Defaults describe the most common solve: solo, timed, first occurrence, full formula and
     * the week's boost slots already used — so each test isolates one feature.
     */
    private static function context(
        int $pieces = 500,
        null|int $tier = null,
        bool $timed = true,
        bool $team = false,
        bool $unboxed = false,
        int $occurrence = 1,
        bool $backfill = false,
        SpeedPercentile $percentile = SpeedPercentile::None,
        int $weekCount = XpCalculator::WEEKLY_BOOST_SOLVE_LIMIT,
    ): SolveXpContext {
        return new SolveXpContext(
            piecesCount: $pieces,
            difficultyTier: $tier,
            isTimed: $timed,
            isTeamOrDuo: $team,
            unboxed: $unboxed,
            occurrenceIndex: $occurrence,
            isBackfill: $backfill,
            speedPercentile: $percentile,
            xpEarningSolvesThisWeek: $weekCount,
        );
    }

    /**
     * @return array<string, array{SolveXpContext, array<string, int>}>
     */
    public static function provideSolves(): array
    {
        return [
            // --- base scaling & clamps ---
            'plain 500pc solve' => [self::context(), ['solve_base' => 5]],
            'base minimum clamps at 1 (54pc)' => [self::context(pieces: 54), ['solve_base' => 1]],
            'base maximum clamps at 60 (9000pc)' => [self::context(pieces: 9000), ['solve_base' => 60]],
            'base rounds half-up (150pc → 1.5 → 2)' => [self::context(pieces: 150), ['solve_base' => 2]],
            'base rounds half-up (240pc → 2.4 → 2)' => [self::context(pieces: 240), ['solve_base' => 2]],
            'base rounds half-up (250pc → 2.5 → 3)' => [self::context(pieces: 250), ['solve_base' => 3]],

            // --- difficulty multipliers, tiers 1–6 explicit ---
            'tier 1 has no difficulty bonus' => [self::context(pieces: 1000, tier: 1), ['solve_base' => 10]],
            'tier 2 has no difficulty bonus' => [self::context(pieces: 1000, tier: 2), ['solve_base' => 10]],
            'tier 3 = 15% bonus' => [self::context(pieces: 1000, tier: 3), ['solve_base' => 10, 'solve_difficulty_bonus' => 2]],
            'tier 4 = 30% bonus' => [self::context(pieces: 1000, tier: 4), ['solve_base' => 10, 'solve_difficulty_bonus' => 3]],
            'tier 5 = 40% bonus' => [self::context(pieces: 1000, tier: 5), ['solve_base' => 10, 'solve_difficulty_bonus' => 4]],
            'tier 6 = 50% bonus' => [self::context(pieces: 1000, tier: 6), ['solve_base' => 10, 'solve_difficulty_bonus' => 5]],
            'unrated puzzle counts as multiplier 1.00' => [self::context(pieces: 1000, tier: null), ['solve_base' => 10]],
            // A bonus the solve qualifies for is worth at least 1 XP (100pc tier 3: 1 × 0.15 = 0.15)
            'a qualifying difficulty bonus is worth at least 1 XP' => [self::context(pieces: 100, tier: 3), ['solve_base' => 1, 'solve_difficulty_bonus' => 1]],
            'a repeat of a hard puzzle keeps its difficulty line' => [self::context(tier: 3, occurrence: 2), ['solve_base' => 3, 'solve_difficulty_bonus' => 1]],

            // --- team multiplier ---
            'duo/team solve earns 0.75×' => [self::context(pieces: 1000, team: true), ['solve_base' => 8]],
            'team with difficulty bonus' => [self::context(pieces: 1000, tier: 4, team: true), ['solve_base' => 8, 'solve_difficulty_bonus' => 2]],

            // --- unboxed ---
            'unboxed adds 20% of base+difficulty' => [
                self::context(pieces: 1000, tier: 4, unboxed: true),
                ['solve_base' => 10, 'solve_difficulty_bonus' => 3, 'solve_unboxed_bonus' => 3],
            ],
            'unboxed alone' => [self::context(unboxed: true), ['solve_base' => 5, 'solve_unboxed_bonus' => 1]],
            'a qualifying unboxed bonus is worth at least 1 XP (100pc: 0.2)' => [self::context(pieces: 100, unboxed: true), ['solve_base' => 1, 'solve_unboxed_bonus' => 1]],

            // --- occurrence ladder (positions count ALL solves of the puzzle, any mode) ---
            'timed 2nd occurrence earns 50%' => [self::context(occurrence: 2), ['solve_base' => 3]],
            'timed 3rd occurrence earns 25%' => [self::context(occurrence: 3), ['solve_base' => 1]],
            'timed 10th occurrence still earns 25%' => [self::context(pieces: 1000, occurrence: 10), ['solve_base' => 3]],
            'relax 1st occurrence earns 50%' => [self::context(timed: false), ['solve_base' => 3]],
            'timed solve after an earlier relax solve is occurrence 2' => [self::context(occurrence: 2), ['solve_base' => 3]],

            // --- base floor ---
            'base part floors at 1 when core > 0' => [
                self::context(pieces: 100, team: true, occurrence: 3),
                ['solve_base' => 1],
            ],

            // --- backfill: core only, bonuses ignored even when eligible ---
            'backfill drops speed and weekly boost' => [
                self::context(pieces: 2000, tier: 5, unboxed: true, backfill: true, percentile: SpeedPercentile::Top10, weekCount: 0),
                ['solve_base' => 20, 'solve_difficulty_bonus' => 8, 'solve_unboxed_bonus' => 6],
            ],

            // --- speed bonus ---
            'speed bonus above median = 5% of core' => [
                self::context(pieces: 1000, percentile: SpeedPercentile::AboveMedian),
                ['solve_base' => 10, 'solve_speed_bonus' => 1],
            ],
            'speed bonus top 25% = 10% of core' => [
                self::context(pieces: 1000, percentile: SpeedPercentile::Top25),
                ['solve_base' => 10, 'solve_speed_bonus' => 1],
            ],
            'speed bonus top 10% = 15% of core' => [
                self::context(pieces: 1000, percentile: SpeedPercentile::Top10),
                ['solve_base' => 10, 'solve_speed_bonus' => 2],
            ],
            // The bug report of 2026-10-11: faster than the median on a 500-piece puzzle was 5 % of 5 XP = 0.25,
            // rounded to nothing - a qualifying bonus is worth at least 1 XP
            'faster than the median on a 500-piece puzzle is worth 1 XP' => [
                self::context(pieces: 500, percentile: SpeedPercentile::AboveMedian),
                ['solve_base' => 5, 'solve_speed_bonus' => 1],
            ],
            'a top 10 % 99-piece solve is worth 1 XP' => [
                self::context(pieces: 99, percentile: SpeedPercentile::Top10),
                ['solve_base' => 1, 'solve_speed_bonus' => 1],
            ],
            'slower than the median earns no speed line' => [
                self::context(pieces: 500, percentile: SpeedPercentile::None),
                ['solve_base' => 5],
            ],
            'duo never gets a speed bonus' => [
                self::context(pieces: 1000, team: true, percentile: SpeedPercentile::Top10),
                ['solve_base' => 8],
            ],
            'relax never gets a speed bonus' => [
                self::context(pieces: 1000, timed: false, percentile: SpeedPercentile::Top10),
                ['solve_base' => 5],
            ],

            // --- weekly boost: first 5 XP-earning solves per ISO week ---
            'weekly boost = 20% of core for the first solve of the week' => [
                self::context(pieces: 2000, weekCount: 0),
                ['solve_base' => 20, 'solve_weekly_boost' => 4],
            ],
            'weekly boost on a 500-piece solve (1.0)' => [
                self::context(weekCount: 0),
                ['solve_base' => 5, 'solve_weekly_boost' => 1],
            ],
            'weekly boost still applies to the 5th solve of the week' => [
                self::context(weekCount: 4),
                ['solve_base' => 5, 'solve_weekly_boost' => 1],
            ],
            'a weekly boost is worth at least 1 XP (200pc: 0.4)' => [
                self::context(pieces: 200, weekCount: 0),
                ['solve_base' => 2, 'solve_weekly_boost' => 1],
            ],
            'no weekly boost from the 6th solve of the week' => [
                self::context(weekCount: 5),
                ['solve_base' => 5],
            ],
            'weekly boost computed on full core incl. bonuses' => [
                self::context(pieces: 1000, tier: 4, unboxed: true, weekCount: 0),
                ['solve_base' => 10, 'solve_difficulty_bonus' => 3, 'solve_unboxed_bonus' => 3, 'solve_weekly_boost' => 3],
            ],
            'relax first solve gets the weekly boost too' => [
                self::context(timed: false, weekCount: 0),
                ['solve_base' => 3, 'solve_weekly_boost' => 1],
            ],

            // --- everything at once: the XP explainer's example (20 XP) ---
            'full receipt: base, difficulty, unboxed, speed, weekly' => [
                self::context(pieces: 1000, tier: 4, unboxed: true, percentile: SpeedPercentile::AboveMedian, weekCount: 0),
                [
                    'solve_base' => 10,
                    'solve_difficulty_bonus' => 3,
                    'solve_unboxed_bonus' => 3,
                    'solve_speed_bonus' => 1,
                    'solve_weekly_boost' => 3,
                ],
            ],
        ];
    }

    /**
     * @param array<string, int> $expected reason value => amount
     */
    #[DataProvider('provideSolves')]
    public function testCalculate(SolveXpContext $context, array $expected): void
    {
        $awards = (new XpCalculator())->calculate($context);

        $actual = [];
        foreach ($awards as $award) {
            self::assertArrayNotHasKey($award->reason->value, $actual, 'Duplicate reason in one receipt');
            $actual[$award->reason->value] = $award->amount;
        }

        self::assertSame($expected, $actual);
    }

    public function testRelaxRepeatEarnsNothingAtAll(): void
    {
        $context = self::context(timed: false, occurrence: 2, weekCount: 0);

        self::assertSame([], (new XpCalculator())->calculate($context));
    }

    public function testRelaxThirdOccurrenceEarnsNothing(): void
    {
        self::assertSame([], (new XpCalculator())->calculate(self::context(timed: false, occurrence: 3)));
    }

    public function testInvalidDifficultyTierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new XpCalculator())->calculate(self::context(tier: 7));
    }

    public function testOccurrenceIndexMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new XpCalculator())->calculate(self::context(occurrence: 0));
    }
}
