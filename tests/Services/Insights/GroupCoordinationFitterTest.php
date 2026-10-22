<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use SpeedPuzzling\Web\Tests\TestClock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetGroupCoordinationConstants;
use SpeedPuzzling\Web\Services\Insights\GroupCoordinationFitter;
use SpeedPuzzling\Web\Tests\InsightsPaceSeeding;
use SpeedPuzzling\Web\Value\GroupCoordination;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * c = median of group time × Σ 1/paceᵢ ÷ difficulty over comparable group results, every pace as of the solve (the
 * pace history); the seed while a bucket has too few of them, and for every bucket before the history is built.
 */
final class GroupCoordinationFitterTest extends KernelTestCase
{
    use InsightsPaceSeeding;

    private Connection $connection;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
        $this->now = TestClock::now()->modify('today 12:00:00');

        // The fixtures' own pair/team results stay out of the fit
        $this->connection->executeStatement("UPDATE puzzle_solving_time SET suspicious = true WHERE puzzling_type <> 'solo'");
        $this->markPaceHistoryBuilt($this->connection);
    }

    public function testFitsTheMedianAndKeepsSeedsForThinBuckets(): void
    {
        $alice = $this->player('alice', 3600);
        $bob = $this->player('bob', 3600);
        $carol = $this->player('carol', 1800);
        $dave = $this->player('dave', null);
        $puzzle = $this->puzzle(1.2, 'high');
        $secret = $this->puzzle(1.2, 'high', hidden: true);
        $unrated = $this->puzzle(1.2, 'insufficient');

        // Alice + Bob: expected = 1.2 × 1 / (2/3600) = 2160 s
        $pair = $this->team([$alice, $bob]);
        // 31 first tries at 1.5 × expected, around it: the median is exactly 1.5
        for ($i = 0; $i < 31; $i++) {
            $this->time($pair, $alice, $puzzle, (int) round(2160 * (1.5 + ($i - 15) * 0.01)), true);
        }
        // 10 repeats: too few, the seed stays
        for ($i = 0; $i < 10; $i++) {
            $this->time($pair, $alice, $puzzle, 2160, false);
        }

        // Left out: a guest member, a member without a pace, an unboxed result, a secret or unrated puzzle
        $withGuest = $this->team([$alice], ['Grandma']);
        $withoutBaseline = $this->team([$alice, $dave]);

        for ($i = 0; $i < 40; $i++) {
            $this->time($withGuest, $alice, $puzzle, 9000, true);
            $this->time($withoutBaseline, $alice, $puzzle, 9000, true);
            $this->time($pair, $alice, $puzzle, 9000, true, unboxed: true);
            $this->time($pair, $alice, $secret, 9000, true);
            $this->time($pair, $alice, $unrated, 9000, true);
        }

        // A team of three: expected = 1.2 × 1 / (1/3600 + 1/3600 + 1/1800) = 1080 s; 30 first tries at 1.4
        $trio = $this->team([$alice, $bob, $carol]);

        for ($i = 0; $i < 30; $i++) {
            $this->time($trio, $alice, $puzzle, 1512, true);
        }

        /** @var GroupCoordinationFitter $fitter */
        $fitter = self::getContainer()->get(GroupCoordinationFitter::class);
        $constants = $fitter->fit($this->now);

        self::assertSame(['coordination' => 1.5, 'sample_size' => 31, 'fitted' => true], $constants[2]['first']);
        self::assertSame(['coordination' => GroupCoordination::SEEDS[2]['repeat'], 'sample_size' => 10, 'fitted' => false], $constants[2]['repeat']);
        self::assertSame(['coordination' => 1.4, 'sample_size' => 30, 'fitted' => true], $constants[3]['first']);
        self::assertSame(['coordination' => GroupCoordination::SEEDS[4]['first'], 'sample_size' => 0, 'fitted' => false], $constants[4]['first']);

        // Stored, and a refit over the same data writes nothing
        self::assertSame(['written' => 6, 'deleted' => 0], $fitter->refit($this->now));
        self::assertSame(['written' => 0, 'deleted' => 0], $fitter->refit($this->now));

        $query = new GetGroupCoordinationConstants($this->connection);
        $stored = $query->all();
        self::assertSame(1.5, $stored[2]['first']);
        self::assertSame(1.08, $stored[2]['repeat']);
        self::assertSame(1.4, $stored[3]['first']);
    }

    public function testSeedsEverywhereBeforeThePaceHistoryIsBuilt(): void
    {
        $alice = $this->player('alice', 3600);
        $bob = $this->player('bob', 3600);
        $puzzle = $this->puzzle(1.2, 'high');
        $pair = $this->team([$alice, $bob]);

        for ($i = 0; $i < 40; $i++) {
            $this->time($pair, $alice, $puzzle, 3240, true);
        }

        $this->connection->executeStatement('DELETE FROM pace_history_build');

        /** @var GroupCoordinationFitter $fitter */
        $fitter = self::getContainer()->get(GroupCoordinationFitter::class);

        self::assertSame(['coordination' => GroupCoordination::SEEDS[2]['first'], 'sample_size' => 0, 'fitted' => false], $fitter->fit($this->now)[2]['first']);
    }

    public function testSeedsWithoutAnyStoredConstant(): void
    {
        $this->connection->executeStatement('DELETE FROM group_coordination_constant');

        $query = new GetGroupCoordinationConstants($this->connection);

        self::assertSame(GroupCoordination::SEEDS, $query->all());
        self::assertSame(1.41, GroupCoordination::seed(6, true));
        self::assertSame(1.08, GroupCoordination::seed(2, false));
    }

    private function player(string $name, null|int $baseline500): string
    {
        $id = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            'INSERT INTO player (id, code, name, registered_at) VALUES (:id, :code, :name, ' . TestClock::SQL_NOW . ')',
            ['id' => $id, 'code' => 'coord' . $name, 'name' => 'Coordination ' . $name],
        );

        if ($baseline500 !== null) {
            // The pace as of the solves (10 days ago), an older slower one before it, and a faster one after them -
            // only the one valid just before each solve counts
            $this->paceRow($this->connection, $id, 500, $this->now->modify('-400 days'), $baseline500 * 2, 5);
            $this->paceRow($this->connection, $id, 500, $this->now->modify('-100 days'), $baseline500, 6);
            $this->paceRow($this->connection, $id, 500, $this->now->modify('-1 day'), intdiv($baseline500, 2), 7);
            // Today's baseline says nothing about the past solves
            $this->connection->executeStatement(
                "INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, baseline_type, computed_at)
                VALUES (gen_random_uuid(), :id, 500, :baseline, 10, 'direct', " . TestClock::SQL_NOW . ")",
                ['id' => $id, 'baseline' => intdiv($baseline500, 2)],
            );
        }

        return $id;
    }

    private function puzzle(float $difficulty, string $confidence, bool $hidden = false): string
    {
        $id = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, is_available, hide_until) VALUES (:id, 500, 'Coordination puzzle', true, true, :hideUntil)",
            ['id' => $id, 'hideUntil' => $hidden ? $this->now->modify('+1 month')->format('Y-m-d H:i:s') : null],
        );
        $this->connection->executeStatement(
            'INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, computed_at) VALUES (:id, :score, 4, :confidence, 30, ' . TestClock::SQL_NOW . ')',
            ['id' => $id, 'score' => $difficulty, 'confidence' => $confidence],
        );

        return $id;
    }

    /**
     * @param list<string> $players
     * @param list<string> $guests
     */
    private function team(array $players, array $guests = []): string
    {
        $id = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            'INSERT INTO puzzling_team (id, composition_key, size, created_at) VALUES (:id, :key, :size, ' . TestClock::SQL_NOW . ')',
            ['id' => $id, 'key' => sha1($id), 'size' => count($players) + count($guests)],
        );

        $position = 0;

        foreach ($players as $player) {
            $this->connection->executeStatement(
                'INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, guest_name, position) VALUES (:id, :team, :key, :player, NULL, :position)',
                ['id' => Uuid::uuid7()->toString(), 'team' => $id, 'key' => $player, 'player' => $player, 'position' => $position++],
            );
        }

        foreach ($guests as $guest) {
            $this->connection->executeStatement(
                'INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, guest_name, position) VALUES (:id, :team, :key, NULL, :guest, :position)',
                ['id' => Uuid::uuid7()->toString(), 'team' => $id, 'key' => 'g:' . mb_strtolower($guest), 'guest' => $guest, 'position' => $position++],
            );
        }

        return $id;
    }

    private function time(string $team, string $tracker, string $puzzle, int $seconds, bool $firstAttempt, bool $unboxed = false): void
    {
        /** @var int|string $size */
        $size = $this->connection->fetchOne('SELECT size FROM puzzling_team WHERE id = :id', ['id' => $team]);

        $this->connection->executeStatement(
            'INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, unboxed, puzzling_type, puzzlers_count, puzzling_team_id)
            VALUES (gen_random_uuid(), :tracker, :puzzle, :seconds, :at, :at, true, :first, :unboxed, :type, :size, :team)',
            [
                'tracker' => $tracker,
                'puzzle' => $puzzle,
                'seconds' => $seconds,
                'at' => $this->now->modify('-10 days')->format('Y-m-d H:i:s'),
                'first' => $firstAttempt ? 'true' : 'false',
                'unboxed' => $unboxed ? 'true' : 'false',
                'type' => (int) $size === 2 ? 'duo' : 'team',
                'size' => (int) $size,
                'team' => $team,
            ],
        );
    }
}
