<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/features/players-page/README.md - the precomputed numbers match the results, a re-run changes nothing, and
 * moments keep their ids while they hold.
 */
final class RecalculateCommunityStatsHandlerTest extends KernelTestCase
{
    use ClonesSolvingTimes;

    private RecalculateCommunityStatsHandler $handler;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->handler = self::getContainer()->get(RecalculateCommunityStatsHandler::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testEveryPlayerGetsARowWithTheirResults(): void
    {
        ($this->handler)(new RecalculateCommunityStats());

        self::assertSame(
            self::int($this->database->fetchOne('SELECT COUNT(*) FROM player')),
            self::int($this->database->fetchOne('SELECT COUNT(*) FROM community_player_stats')),
        );

        foreach ([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_WITH_FAVORITES] as $playerId) {
            // Tracked by them (unfinished competition results are no solves) + pair/team times they are a member of
            $expected = self::int($this->database->fetchOne(
                "SELECT COUNT(*) FROM puzzle_solving_time t
                 WHERE (t.seconds_to_solve IS NOT NULL OR t.pieces_placed IS NULL)
                   AND (t.player_id = :id OR (t.team IS NOT NULL AND t.team::jsonb -> 'puzzlers' @> jsonb_build_array(jsonb_build_object('player_id', CAST(:id AS text)))))",
                ['id' => $playerId],
            ));

            $row = $this->statsOf($playerId);
            self::assertSame($expected, self::int($row['solved_total']), 'solved_total of ' . $playerId);
            self::assertIsString($row['monthly_solves']);
            $monthly = json_decode($row['monthly_solves'], true);
            self::assertIsArray($monthly);
            self::assertCount(12, $monthly);
        }
    }

    public function testBestTimesAreSoloOnExactlyThatPieceCount(): void
    {
        ($this->handler)(new RecalculateCommunityStats());

        $expected = $this->database->fetchOne(
            "SELECT MIN(t.seconds_to_solve) FROM puzzle_solving_time t JOIN puzzle z ON z.id = t.puzzle_id
             WHERE t.player_id = :id AND t.puzzling_type = 'solo' AND z.pieces_count = 500 AND t.suspicious = false",
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        self::assertSame(self::int($expected), self::int($this->statsOf(PlayerFixture::PLAYER_REGULAR)['best500_seconds']));
    }

    public function testFavoritesAreCountedNotListed(): void
    {
        ($this->handler)(new RecalculateCommunityStats());

        $expected = self::int($this->database->fetchOne(
            'SELECT COUNT(*) FROM player WHERE jsonb_exists(favorite_players::jsonb, :id)',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        ));

        self::assertSame($expected, self::int($this->statsOf(PlayerFixture::PLAYER_REGULAR)['favorites_count']));
    }

    public function testTheWorldCountsEveryPlayerAndACountryItsOwn(): void
    {
        ($this->handler)(new RecalculateCommunityStats());

        self::assertSame(
            self::int($this->database->fetchOne('SELECT COUNT(*) FROM player')),
            self::int($this->database->fetchOne("SELECT registered_players FROM community_scope_stats WHERE scope = 'world'")),
        );
        self::assertSame(
            self::int($this->database->fetchOne("SELECT COUNT(*) FROM player WHERE country = 'cz'")),
            self::int($this->database->fetchOne("SELECT registered_players FROM community_scope_stats WHERE scope = 'cz'")),
        );
        self::assertSame(
            self::int($this->database->fetchOne('SELECT SUM(solves30d) FROM community_player_stats')),
            self::int($this->database->fetchOne("SELECT solves30d FROM community_scope_stats WHERE scope = 'world'")),
        );
    }

    public function testASecondRunRewritesNoPlayerRow(): void
    {
        ($this->handler)(new RecalculateCommunityStats());
        $second = ($this->handler)(new RecalculateCommunityStats());

        self::assertSame(0, $second['players']);
    }

    public function testAFasterSoloTimeIsAPersonalBestUntilItIsEditedAway(): void
    {
        $previousBest = self::int($this->database->fetchOne(
            "SELECT MIN(t.seconds_to_solve) FROM puzzle_solving_time t JOIN puzzle z ON z.id = t.puzzle_id
             WHERE t.player_id = :id AND t.puzzling_type = 'solo' AND z.pieces_count = 500 AND t.suspicious = false",
            ['id' => PlayerFixture::PLAYER_REGULAR],
        ));
        // TIME_01: PLAYER_REGULAR, PUZZLE_500_01, solo
        $timeId = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_01, ['seconds_to_solve' => $previousBest - 60, 'days_ago' => 0]);

        ($this->handler)(new RecalculateCommunityStats());
        $moment = $this->momentOf(PlayerFixture::PLAYER_REGULAR, 'pb:' . $timeId);

        self::assertNotFalse($moment);
        self::assertSame('personal_best', $moment['type']);
        self::assertSame($previousBest - 60, self::int($moment['value']));
        self::assertSame($previousBest, self::int($moment['previous_value']));
        self::assertSame(500, self::int($moment['pieces_count']));

        ($this->handler)(new RecalculateCommunityStats());
        $again = $this->momentOf(PlayerFixture::PLAYER_REGULAR, 'pb:' . $timeId);
        self::assertNotFalse($again);
        self::assertSame($moment['id'], $again['id'], 'A moment keeps its id across runs');

        $this->database->executeStatement('UPDATE puzzle_solving_time SET seconds_to_solve = :seconds WHERE id = :id', ['seconds' => $previousBest + 600, 'id' => $timeId]);
        ($this->handler)(new RecalculateCommunityStats());

        self::assertFalse($this->momentOf(PlayerFixture::PLAYER_REGULAR, 'pb:' . $timeId));
    }

    public function testReachingFiftyPuzzlesIsAMilestone(): void
    {
        ($this->handler)(new RecalculateCommunityStats());
        $solved = self::int($this->statsOf(PlayerFixture::PLAYER_REGULAR)['solved_total']);
        self::assertLessThan(50, $solved, 'The fixtures already reach the milestone - pick a higher one');

        for ($i = $solved; $i < 50; $i++) {
            $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_01, ['days_ago' => 1]);
        }

        ($this->handler)(new RecalculateCommunityStats());

        $moment = $this->momentOf(PlayerFixture::PLAYER_REGULAR, 'puzzles:50');
        self::assertNotFalse($moment);
        self::assertSame('puzzles_milestone', $moment['type']);
        self::assertSame(50, self::int($moment['value']));
    }

    /**
     * @return array<string, mixed>
     */
    private function statsOf(string $playerId): array
    {
        $row = $this->database->fetchAssociative('SELECT * FROM community_player_stats WHERE player_id = :id', ['id' => $playerId]);
        self::assertNotFalse($row);

        return $row;
    }

    /**
     * @return false|array<string, mixed>
     */
    private function momentOf(string $playerId, string $dedupeKey): false|array
    {
        return $this->database->fetchAssociative(
            'SELECT * FROM player_moment WHERE player_id = :id AND dedupe_key = :key',
            ['id' => $playerId, 'key' => $dedupeKey],
        );
    }

    private static function int(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
