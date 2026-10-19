<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\Insights\ImprovementCurve;
use SpeedPuzzling\Web\Services\Insights\InsightsCache;
use SpeedPuzzling\Web\Services\Insights\PairsTeamsSynergy;
use SpeedPuzzling\Web\Services\Insights\RatingForecast;
use SpeedPuzzling\Web\Services\Insights\RatingOpportunities;
use SpeedPuzzling\Web\Services\Insights\RatingOverTime;
use SpeedPuzzling\Web\Services\Insights\ResolveRadar;
use SpeedPuzzling\Web\Services\Insights\ShelfPicks;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\InsightsLadderSeeding;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The performance guard of the lazy parts (plan §4.5, docs/features/insights/computation.md §4c): on the heaviest
 * fixture - the insights ladder (24 players × 55 puzzles, repeats, a shelf, a pair) - every part stays within its
 * statement budget and under 100 ms. The production-copy numbers are in computation.md §4c.11.
 */
final class InsightsLazyPartsBudgetTest extends KernelTestCase
{
    use InsightsLadderSeeding;

    private const int MILLISECONDS = 100;

    public function testEveryLazyPartWithinItsBudget(): void
    {
        self::bootKernel();
        $now = new DateTimeImmutable('today 12:00:00');
        self::getContainer()->set(ClockInterface::class, new MockClock($now));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->seedLadder($connection, $now);

        $player = self::ladderPlayer(1);
        $partner = self::ladderPlayer(2);

        // A shelf (a puzzle player 1 has not done) and a pair with player 2
        $connection->executeStatement("INSERT INTO puzzle (id, pieces_count, name, approved, is_available) VALUES (:id, 500, 'Insights Puzzle 56', true, true)", ['id' => self::ladderPuzzle(56)]);
        $this->seedSolves($connection, $now, ['SELECT p, 56, 1700 + 40 * p, 90, true FROM generate_series(2, 22) AS p']);
        $connection->executeStatement(
            'INSERT INTO collection_item (id, collection_id, player_id, puzzle_id, comment, added_at) VALUES (:id, NULL, :player, :puzzle, NULL, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'player' => $player, 'puzzle' => self::ladderPuzzle(56)],
        );
        $team = Uuid::uuid7()->toString();
        $connection->executeStatement("INSERT INTO puzzling_team (id, composition_key, size, created_at) VALUES (:id, :key, 2, NOW())", ['id' => $team, 'key' => sha1($team)]);

        foreach ([$player, $partner] as $position => $member) {
            $connection->executeStatement(
                'INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, guest_name, position) VALUES (:id, :team, :key, :player, NULL, :position)',
                ['id' => Uuid::uuid7()->toString(), 'team' => $team, 'key' => $member, 'player' => $member, 'position' => $position],
            );
        }

        $connection->executeStatement(
            "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, puzzling_type, puzzlers_count, puzzling_team_id)
            SELECT gen_random_uuid(), :player, CAST('019b0001-0000-7000-8000-' || lpad(z::text, 12, '0') AS uuid), 1100 + z, CAST(:at AS timestamp), CAST(:at AS timestamp), true, false, 'duo', 2, :team
            FROM generate_series(1, 20) AS z",
            ['player' => $player, 'team' => $team, 'at' => $now->modify('-20 days')->format('Y-m-d H:i:s')],
        );

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = self::getContainer()->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        $container = self::getContainer();
        $parts = [
            'rating opportunities' => [8, static fn () => $container->get(RatingOpportunities::class)->build($player)],
            'rating forecast' => [10, static fn () => $container->get(RatingForecast::class)->build($player)],
            're-solve radar' => [4, static fn () => $container->get(ResolveRadar::class)->build($player)],
            'pairs & teams' => [4, static fn () => $container->get(PairsTeamsSynergy::class)->build($player)],
            'improvement curve' => [1, static fn () => $container->get(ImprovementCurve::class)->build($player)],
            'rating over time' => [1, static fn () => $container->get(RatingOverTime::class)->build($player)],
            'shelf picks' => [7, static fn () => $container->get(ShelfPicks::class)->build($player, isMember: true)],
            'cache key' => [2, static fn () => $container->get(InsightsCache::class)->keyFor($player)],
        ];

        /** @var DebugDataHolder $holder */
        $holder = $container->get('doctrine.debug_data_holder');
        /** @var ResetInterface $resetter */
        $resetter = $container->get('services_resetter');

        foreach ($parts as $name => [$statements, $part]) {
            $best = INF;

            for ($run = 0; $run < 3; $run++) {
                // A new request: nothing memoised from the part before
                $resetter->reset();
                $holder->reset();

                $start = hrtime(true);
                $part();
                $best = min($best, (hrtime(true) - $start) / 1e6);

                $queries = $holder->getData()['default'] ?? [];
                self::assertLessThanOrEqual($statements, is_array($queries) ? count($queries) : 0, "{$name}: statements");
            }

            self::assertLessThan(self::MILLISECONDS, $best, "{$name}: {$best} ms");
        }
    }
}
