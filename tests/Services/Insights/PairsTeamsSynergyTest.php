<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetGroupCoordinationConstants;
use SpeedPuzzling\Web\Results\Insights\GroupSynergy;
use SpeedPuzzling\Web\Services\Insights\PairsTeamsSynergy;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\InsightsPaceSeeding;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\SynergyVerdict;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Pair/team synergy (docs/features/insights/computation.md §4c.1): expected = c × difficulty ÷ Σ 1/paceᵢ with every
 * pace as of the solve; pairs and teams apart; guests listed without a score; private partners never named; a partner
 * the viewer blocks left out.
 */
final class PairsTeamsSynergyTest extends KernelTestCase
{
    use InsightsPaceSeeding;

    private Connection $connection;
    private DateTimeImmutable $now;
    private string $me;
    private string $anna;
    private string $bob;
    private string $cecil;
    private string $puzzle;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->now = new DateTimeImmutable('today 12:00:00');
        self::getContainer()->set(ClockInterface::class, new MockClock($this->now));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
        $this->markPaceHistoryBuilt($this->connection);

        $this->me = $this->player('me', 3600);
        $this->anna = $this->player('anna', 3600);
        $this->bob = $this->player('bob', 1800);
        $this->cecil = $this->player('cecil', 3600);
        $this->puzzle = $this->puzzle(1.0);
    }

    public function testExpectedTimesFromPacesAsOfTheSolveAndVerdicts(): void
    {
        $pairCoordination = $this->coordination(2);
        $withAnna = $this->team([$this->me, $this->anna]);
        $withBob = $this->team([$this->me, $this->bob]);
        $trio = $this->team([$this->me, $this->anna, $this->bob]);
        $withGuest = $this->team([$this->me], ['Grandma']);
        $withCecil = $this->team([$this->me, $this->cecil]);
        $secret = $this->puzzle(1.0, hidden: true);

        // Anna + me: expected = c × 1.0 ÷ (2 / 3600); together 20 % under it
        for ($i = 0; $i < 6; $i++) {
            $this->time($withAnna, $this->puzzle, (int) round($pairCoordination * 1800 * 0.8));
        }

        // Not compared: unboxed, a secret puzzle
        $this->time($withAnna, $this->puzzle, 999, unboxed: true);
        $this->time($withAnna, $secret, 999);

        // Bob + me: expected = c × 1.0 ÷ (1/3600 + 1/1800); together 20 % over it
        for ($i = 0; $i < 6; $i++) {
            $this->time($withBob, $this->puzzle, (int) round($pairCoordination * 1200 * 1.2));
        }

        for ($i = 0; $i < 6; $i++) {
            $this->time($trio, $this->puzzle, (int) round($this->coordination(3) * 900));
        }

        for ($i = 0; $i < 3; $i++) {
            $this->time($withGuest, $this->puzzle, 2000);
        }

        $this->time($withCecil, $this->puzzle, 2000);
        $this->time($withCecil, $this->puzzle, 2100);

        $result = $this->synergy()->build($this->me);

        self::assertSame(19, $result->pairResultsCount);
        self::assertSame(6, $result->teamResultsCount);
        self::assertSame([$withAnna, $withBob, $withGuest, $withCecil], array_map(static fn (GroupSynergy $pair): string => $pair->teamId, $result->pairs));
        self::assertSame([$trio], array_map(static fn (GroupSynergy $team): string => $team->teamId, $result->teams));

        [$anna, $bob, $guest, $cecil] = $result->pairs;

        self::assertSame(SynergyVerdict::GreatMatch, $anna->verdict);
        self::assertSame(8, $anna->resultsCount);
        self::assertSame(6, $anna->scoredCount);
        self::assertNotNull($anna->effect);
        self::assertEqualsWithDelta(exp(log(0.8) * 6 / 14) - 1.0, $anna->effect->effect, 1e-3);
        self::assertSame($this->anna, $anna->partners[0]->playerId);
        // Alone the player takes pace × difficulty = 3600 s
        self::assertNotNull($anna->speedVsAlone);
        self::assertEqualsWithDelta(3600 / round($pairCoordination * 1800 * 0.8), $anna->speedVsAlone, 1e-9);

        self::assertSame(SynergyVerdict::FasterApart, $bob->verdict);
        self::assertSame(SynergyVerdict::NoSoloPace, $guest->verdict);
        self::assertSame(3, $guest->resultsCount);
        self::assertSame('Grandma', $guest->partners[0]->guestName);
        self::assertSame(SynergyVerdict::NotSureYet, $cecil->verdict);
        self::assertSame(SynergyVerdict::AsExpected, $result->teams[0]->verdict);

        self::assertCount(5, $result->bestPairResults);
        self::assertSame($withAnna, $result->bestPairResults[0]->result->teamId);
        self::assertSame((int) round($pairCoordination * 1800), $result->bestPairResults[0]->expectedSeconds);
        self::assertEqualsWithDelta(0.8, $result->bestPairResults[0]->ratio, 1e-3);

        // Filters: period and pieces
        self::assertSame(0, $this->synergy()->build($this->me, from: $this->now->modify('-5 days'))->pairResultsCount);
        self::assertSame(0, $this->synergy()->build($this->me, pieces: PiecesRange::between(1000, 1000))->pairResultsCount);
    }

    public function testThePaceAtTheSolveCountsNotTodays(): void
    {
        $pair = $this->team([$this->me, $this->anna]);

        // Anna got much faster 5 days ago, and today's baseline says faster still - the result 10 days ago is
        // compared with her pace then
        $this->connection->executeStatement(
            "INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, baseline_type, computed_at) VALUES (gen_random_uuid(), :player, 500, 100, 10, 'direct', NOW())",
            ['player' => $this->anna],
        );
        $this->paceRow($this->connection, $this->anna, 500, $this->now->modify('-5 days'), 900, 6);
        $this->time($pair, $this->puzzle, 2000);

        $score = $this->synergy()->build($this->me)->bestPairResults[0];

        // At the solve (10 days ago) Anna's pace was still 3600 (the row 5 days ago came after it)
        self::assertSame((int) round($this->coordination(2) * 1800), $score->expectedSeconds);
    }

    public function testPrivatePartnersAreNotNamedAndBlockedOnesAreLeftOut(): void
    {
        // The fixture pair: PLAYER_REGULAR + PLAYER_PRIVATE (private, not on his allow list), and he blocks her
        $signedOut = $this->synergy()->build(PlayerFixture::PLAYER_REGULAR);
        self::assertCount(1, $signedOut->pairs);
        $partner = $signedOut->pairs[0]->partners[0];
        self::assertNull($partner->playerId, 'Not even the id of a hidden teammate');
        self::assertTrue($partner->hidden);
        self::assertFalse($partner->isGuest());
        self::assertNull($partner->name);
        self::assertNull($partner->code);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        $signedIn = $this->synergy()->build(PlayerFixture::PLAYER_REGULAR);
        self::assertSame([], $signedIn->pairs);
        self::assertSame(0, $signedIn->pairResultsCount);
    }

    public function testFourStatementsWhateverTheHistory(): void
    {
        $pair = $this->team([$this->me, $this->anna]);
        $trio = $this->team([$this->me, $this->anna, $this->bob]);

        for ($i = 0; $i < 30; $i++) {
            $this->time($pair, $this->puzzle, 2000 + $i);
            $this->time($trio, $this->puzzle, 1500 + $i);
        }

        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $this->synergy()->build($this->me);

        $queries = $holder->getData()['default'] ?? [];
        self::assertCount(4, is_array($queries) ? $queries : []);
    }

    private function coordination(int $size): float
    {
        return (new GetGroupCoordinationConstants($this->connection))->all()[$size]['first'];
    }

    private function synergy(): PairsTeamsSynergy
    {
        /** @var PairsTeamsSynergy $synergy */
        $synergy = self::getContainer()->get(PairsTeamsSynergy::class);

        return $synergy;
    }

    private function player(string $name, int $pace500): string
    {
        $id = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            'INSERT INTO player (id, code, name, registered_at) VALUES (:id, :code, :name, NOW())',
            ['id' => $id, 'code' => 'synergy' . $name, 'name' => 'Synergy ' . $name],
        );
        $this->paceRow($this->connection, $id, 500, $this->now->modify('-400 days'), $pace500, 5);

        return $id;
    }

    private function puzzle(float $difficulty, bool $hidden = false): string
    {
        $id = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, is_available, hide_until) VALUES (:id, 500, 'Synergy puzzle', true, true, :hideUntil)",
            ['id' => $id, 'hideUntil' => $hidden ? $this->now->modify('+1 month')->format('Y-m-d H:i:s') : null],
        );
        $this->connection->executeStatement(
            "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, computed_at) VALUES (:id, :score, 3, 'high', 30, NOW())",
            ['id' => $id, 'score' => $difficulty],
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
            'INSERT INTO puzzling_team (id, composition_key, size, created_at) VALUES (:id, :key, :size, NOW())',
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

    private function time(string $team, string $puzzle, int $seconds, bool $unboxed = false): void
    {
        /** @var int|string $size */
        $size = $this->connection->fetchOne('SELECT size FROM puzzling_team WHERE id = :id', ['id' => $team]);

        $this->connection->executeStatement(
            'INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, unboxed, puzzling_type, puzzlers_count, puzzling_team_id)
            VALUES (:id, :tracker, :puzzle, :seconds, :at, :at, true, true, :unboxed, :type, :size, :team)',
            [
                'id' => Uuid::uuid7()->toString(),
                'tracker' => $this->me,
                'puzzle' => $puzzle,
                'seconds' => $seconds,
                'at' => $this->now->modify('-10 days')->format('Y-m-d H:i:s'),
                'unboxed' => $unboxed ? 'true' : 'false',
                'type' => (int) $size === 2 ? 'duo' : 'team',
                'size' => (int) $size,
                'team' => $team,
            ],
        );
    }
}
