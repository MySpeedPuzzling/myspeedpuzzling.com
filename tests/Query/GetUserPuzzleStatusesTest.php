<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetUserPuzzleStatusesTest extends KernelTestCase
{
    private GetUserPuzzleStatuses $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetUserPuzzleStatuses::class);
    }

    public function testSolvedStatusIncludesSoloPuzzles(): void
    {
        // PLAYER_REGULAR solved PUZZLE_500_01 solo (TIME_01)
        $statuses = $this->query->byPlayerId(PlayerFixture::PLAYER_REGULAR);

        self::assertContains(PuzzleFixture::PUZZLE_500_01, $statuses->solved);
    }

    public function testSolvedStatusIncludesTeamPuzzlesAsMainPlayer(): void
    {
        // PLAYER_REGULAR is the player_id owner of TIME_12 (team solve for PUZZLE_1000_01)
        $statuses = $this->query->byPlayerId(PlayerFixture::PLAYER_REGULAR);

        self::assertContains(PuzzleFixture::PUZZLE_1000_01, $statuses->solved);
    }

    public function testSolvedStatusIncludesTeamPuzzlesAsTeamMember(): void
    {
        // PLAYER_PRIVATE participated in team-002 solving PUZZLE_1000_03 (TIME_41)
        // They are in the team JSON but NOT the player_id owner
        $statuses = $this->query->byPlayerId(PlayerFixture::PLAYER_PRIVATE);

        self::assertContains(
            PuzzleFixture::PUZZLE_1000_03,
            $statuses->solved,
            'Player who solved a puzzle as a team member should see it as solved',
        );
    }

    public function testNullPlayerIdReturnsEmptyStatuses(): void
    {
        $statuses = $this->query->byPlayerId(null);

        self::assertEmpty($statuses->solved);
        self::assertEmpty($statuses->wishlist);
        self::assertEmpty($statuses->unsolved);
        self::assertEmpty($statuses->collection);
    }

    /**
     * The team-membership test was rewritten from an EXISTS over json_array_elements()
     * (a scan of every team time) to an index-backed jsonb containment (Sentry WEB-C1):
     * every player must see exactly the same solved puzzles as before.
     */
    public function testSolvedPuzzlesMatchThePreviousTeamMembershipPredicate(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        /** @var list<string> $playerIds */
        $playerIds = $connection->fetchFirstColumn('SELECT id FROM player');
        $teamOnlySeen = false;

        foreach ($playerIds as $playerId) {
            /** @var list<string> $expected */
            $expected = $connection->fetchFirstColumn(
                "SELECT DISTINCT puzzle_id FROM puzzle_solving_time WHERE player_id = :playerId OR (team IS NOT NULL AND EXISTS (SELECT 1 FROM json_array_elements(team -> 'puzzlers') AS puzzler WHERE puzzler ->> 'player_id' = :playerId))",
                ['playerId' => $playerId],
            );
            /** @var list<string> $ownOnly */
            $ownOnly = $connection->fetchFirstColumn(
                'SELECT DISTINCT puzzle_id FROM puzzle_solving_time WHERE player_id = :playerId',
                ['playerId' => $playerId],
            );

            $actual = $this->query->byPlayerId($playerId)->solved;

            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, sprintf('solved puzzles of player %s', $playerId));

            $teamOnlySeen = $teamOnlySeen || count($expected) > count($ownOnly);
        }

        self::assertTrue($teamOnlySeen, 'Fixtures must contain a puzzle solved only as a team member');
    }
}
