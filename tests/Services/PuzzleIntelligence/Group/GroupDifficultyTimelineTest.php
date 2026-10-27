<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\Group;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Group\GroupDifficultyTimeline;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\FirstAttemptSet;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionEngine;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimeline;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Component\Clock\MockClock;

/**
 * The pair/team model's as-of difficulty is the shown model's (PredictionEngine::difficultyAt()) at every moment -
 * without holding every first attempt.
 */
final class GroupDifficultyTimelineTest extends TestCase
{
    private const float DAY = 86400.0;

    public function testTheDifficultyAtAnyMomentIsTheShownModelsAsOfDifficulty(): void
    {
        $timeline = new PaceTimeline(new PlayerBaselineCalculator($this->createStub(Connection::class), new MockClock(TestClock::now())), 1.3);
        $rows = [];

        // Eight solvers with paces; the last one's first pace row comes after their solve (known only from then on)
        for ($player = 0; $player < 8; $player++) {
            $timeline->add("p{$player}", 500, ($player === 7 ? 50 : 1) * self::DAY, ($player === 7 ? 50 : 1) * self::DAY, 2000 + 100 * $player);
            $rows[] = ['id' => "t{$player}", 'player_id' => "p{$player}", 'puzzle_id' => 'puzzle', 'pieces_count' => 500, 'seconds_to_solve' => 2300 + 37 * $player, 'solved_ts' => (10 + 3 * $player) * self::DAY, 'tracked_ts' => (10 + 3 * $player) * self::DAY];
        }

        // Too slow for an index (above the ceiling) and one solver without any pace
        $rows[] = ['id' => 'slow', 'player_id' => 'p0', 'puzzle_id' => 'puzzle', 'pieces_count' => 500, 'seconds_to_solve' => 99999, 'solved_ts' => 12 * self::DAY, 'tracked_ts' => 12 * self::DAY];
        $rows[] = ['id' => 'nopace', 'player_id' => 'nobody', 'puzzle_id' => 'puzzle', 'pieces_count' => 500, 'seconds_to_solve' => 2000, 'solved_ts' => 13 * self::DAY, 'tracked_ts' => 13 * self::DAY];

        $engine = new PredictionEngine(FirstAttemptSet::build($rows, $timeline), $timeline, new TimePredictionCalculator());
        $group = GroupDifficultyTimeline::build($rows, $timeline);

        foreach ([0, 10, 10.5, 16, 20, 30, 34, 49, 50, 51, 400] as $day) {
            [$k, $sumLog, $score] = $group->at('puzzle', $day * self::DAY, $day * self::DAY);
            [$expectedK, $expectedSum, $expectedScore] = $engine->difficultyAt('puzzle', $day * self::DAY, $day * self::DAY);

            self::assertSame($expectedK, $k, "k on day {$day}");
            self::assertEqualsWithDelta($expectedSum, $sumLog, 1e-9, "Σ log on day {$day}");
            self::assertSame($expectedScore, $score, "score on day {$day}");
        }

        // Asked out of order (never in the replay): the same answers
        self::assertSame($engine->difficultyAt('puzzle', 30 * self::DAY, 30 * self::DAY)[2], $group->at('puzzle', 30 * self::DAY, 30 * self::DAY)[2]);
        self::assertSame([0, 0.0, null], $group->at('another puzzle', 400 * self::DAY, 400 * self::DAY));
        self::assertSame(TimePredictionCalculator::RATED_INDICES, $group->at('puzzle', 24 * self::DAY, 24 * self::DAY)[0], 'Five indices known on day 24');
        self::assertSame(7, $group->at('puzzle', 49 * self::DAY, 49 * self::DAY)[0], "The last solver's index only from their first pace row (day 50)");
        self::assertSame(8, $group->at('puzzle', 51 * self::DAY, 51 * self::DAY)[0]);
    }
}
