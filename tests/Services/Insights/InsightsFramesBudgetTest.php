<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetInsightsContext;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\Insights\InsightsCriteria;
use SpeedPuzzling\Web\Services\Insights\InsightsFrames;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\InsightsLadderSeeding;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The lazy frames of the Insights page within their statement budget and under 100 ms each on the heaviest fixture -
 * the insights ladder (24 players × 55 puzzles, repeats, a shelf, a pair) - on a cache miss (docs/features/insights/
 * page.md "Lazy frames"); a cache hit costs only the key. The first response is pinned apart (InsightsQueryBudgetTest):
 * the frames' placeholders add nothing to it.
 */
final class InsightsFramesBudgetTest extends KernelTestCase
{
    use InsightsLadderSeeding;

    private const int MILLISECONDS = 100;

    /** Statements of a cache miss: the key (1) + the part */
    private const array BUDGETS = [
        'rating, member' => 11,
        'rating, free' => 9,
        'shelf picks, member' => 6,
        'shelf picks, free' => 2,
        'top row, member' => 16,
        'top row, free' => 10,
        're-solve radar' => 5,
        'pairs & teams' => 5,
        'improvement, member' => 3,
        'improvement, free' => 2,
    ];

    public function testEveryFrameWithinItsBudget(): void
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
        $member = InsightsCriteria::fromUserInput(['period' => 'all'], true);
        $free = InsightsCriteria::fromUserInput([], false);
        /** @var InsightsFrames $frames */
        $frames = $container->get(InsightsFrames::class);

        $parts = [
            'rating, member' => static fn () => $frames->rating($player, true, false, false),
            'rating, free' => static fn () => $frames->rating($player, false, false, false),
            'shelf picks, member' => static fn () => $frames->shelf($player, $member, true, true),
            'shelf picks, free' => static fn () => $frames->shelf($player, $free, false, true),
            'top row, member' => static fn () => $frames->top($player, $member, true, true, false, false),
            'top row, free' => static fn () => $frames->top($player, $free, false, true, false, false),
            're-solve radar' => static fn () => $frames->resolve($player, $member, true),
            'pairs & teams' => static fn () => $frames->pairs($player, $member),
            'improvement, member' => static fn () => $frames->improvement($player, $member, true),
            'improvement, free' => static fn () => $frames->improvement($player, $free, false),
        ];

        /** @var DebugDataHolder $holder */
        $holder = $container->get('doctrine.debug_data_holder');
        /** @var ResetInterface $resetter */
        $resetter = $container->get('services_resetter');

        foreach ($parts as $name => $part) {
            $best = INF;

            for ($run = 0; $run < 3; $run++) {
                // A new request with an empty page cache: nothing memoised from the part before
                $resetter->reset();
                $this->loadViewer();
                $holder->reset();

                $start = hrtime(true);
                $part();
                $best = min($best, (hrtime(true) - $start) / 1e6);

                self::assertLessThanOrEqual(self::BUDGETS[$name], $this->statements($holder), "{$name}: statements");
            }

            self::assertLessThan(self::MILLISECONDS, $best, "{$name}: {$best} ms");

            // A cache hit: the key only
            $holder->reset();
            $part();
            self::assertSame(1, $this->statements($holder), "{$name}: a cache hit");
        }

        // Predictions switched off: the radar is nothing but predictions - not even the key
        $resetter->reset();
        $this->loadViewer();
        $holder->reset();
        self::assertNull($frames->resolve($player, $member, false));
        self::assertNull($frames->shelf($player, $member, true, false));
        self::assertNull($frames->top($player, $member, true, false, false, false));
        self::assertSame(0, $this->statements($holder));

        // The top row and the frames below share their cache entries: after the top row, rating and shelf cost the key only
        $resetter->reset();
        $this->loadViewer();
        $frames->top($player, $member, true, true, false, false);
        $holder->reset();
        $frames->rating($player, true, false, false);
        $frames->shelf($player, $member, true, true);
        self::assertSame(2, $this->statements($holder));
    }

    /**
     * The top row's rating tile reads the stored rating and its place on today's ladder from the first response's
     * context statement - no statement of its own, no rating model. Opted out of rankings or private: no place.
     */
    public function testTheRatingTileRidesOnTheContextStatement(): void
    {
        self::bootKernel();
        $now = new DateTimeImmutable('today 12:00:00');
        self::getContainer()->set(ClockInterface::class, new MockClock($now));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->seedLadder($connection, $now);
        self::getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();

        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $getContext = self::getContainer()->get(GetInsightsContext::class);
        $this->loadViewer();
        $holder->reset();

        $context = $getContext->forPlayer(self::ladderPlayer(1));
        self::assertSame(1, $this->statements($holder));
        self::assertNotNull($context->rating500);
        self::assertNotNull($context->ratingPlace500);
        self::assertGreaterThanOrEqual(1, $context->ratingPlace500);

        $connection->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => self::ladderPlayer(1)]);
        self::assertNull($getContext->forPlayer(self::ladderPlayer(1))->ratingPlace500, 'opted out of rankings: no place');

        self::assertNull($getContext->forPlayer(self::ladderPlayer(23))->ratingPlace500, 'private: no place');
    }

    /**
     * The viewer's own profile (hidden players, allow list) rides on the page's profile row - load it first
     */
    private function loadViewer(): void
    {
        self::getContainer()->get(HiddenPlayers::class)->ids();
        self::getContainer()->get(PrivateProfileAccess::class)->revealedIds();
    }

    private function statements(DebugDataHolder $holder): int
    {
        /** @var list<array{sql: string}> $executed */
        $executed = $holder->getData()['default'] ?? [];

        return count(array_filter($executed, static fn (array $query): bool => preg_match('/^"?(SAVEPOINT|RELEASE|START|BEGIN|COMMIT)\b/i', $query['sql']) !== 1));
    }
}
