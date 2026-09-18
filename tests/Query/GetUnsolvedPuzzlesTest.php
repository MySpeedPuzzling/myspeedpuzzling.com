<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
use SpeedPuzzling\Web\Results\UnsolvedPuzzleItem;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetUnsolvedPuzzlesTest extends KernelTestCase
{
    private GetUnsolvedPuzzles $getUnsolvedPuzzles;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->getUnsolvedPuzzles = $container->get(GetUnsolvedPuzzles::class);
    }

    public function testByPlayerIdReturnsUnsolvedPuzzles(): void
    {
        $unsolved = $this->getUnsolvedPuzzles->byPlayerId(PlayerFixture::PLAYER_REGULAR);
        $puzzleIds = array_map(fn($item) => $item->puzzleId, $unsolved);

        // PLAYER_REGULAR has puzzles in collection that are NOT solved
        // e.g., PUZZLE_3000 in COLLECTION_PRIVATE (ITEM_06) - no solving time exists
        self::assertContains(PuzzleFixture::PUZZLE_3000, $puzzleIds);

        // PLAYER_REGULAR solved PUZZLE_500_01 solo (TIME_01)
        // PUZZLE_500_01 is also in their collection (ITEM_07 in favoritesCollection)
        self::assertNotContains(
            PuzzleFixture::PUZZLE_500_01,
            $puzzleIds,
            'Solo-solved puzzles should not appear in unsolved list',
        );

        // PLAYER_PRIVATE has PUZZLE_1000_03 in their collection (ITEM_24)
        // They solved it as part of team-002 (TIME_41) but are NOT the player_id owner
        self::assertNotContains(
            PuzzleFixture::PUZZLE_1000_03,
            $puzzleIds,
            'Puzzles solved as a team member should not appear in unsolved list',
        );
    }

    public function testCountByPlayerIdExcludesTeamSolves(): void
    {
        // Count should NOT include PUZZLE_1000_03 for PLAYER_PRIVATE
        // because they solved it as a team member
        $unsolved = $this->getUnsolvedPuzzles->byPlayerId(PlayerFixture::PLAYER_PRIVATE);
        $count = $this->getUnsolvedPuzzles->countByPlayerId(PlayerFixture::PLAYER_PRIVATE);

        self::assertSame(count($unsolved), $count);
    }

    public function testByPuzzleIdAndPlayerIdExcludesTeamSolves(): void
    {
        // PLAYER_PRIVATE solved PUZZLE_1000_03 as team member, so it should return null
        $item = $this->getUnsolvedPuzzles->byPuzzleIdAndPlayerId(
            PuzzleFixture::PUZZLE_1000_03,
            PlayerFixture::PLAYER_PRIVATE,
        );

        self::assertNull(
            $item,
            'byPuzzleIdAndPlayerId should return null for puzzles solved as team member',
        );
    }

    /**
     * The team-membership test was rewritten from an EXISTS over json_array_elements() (a scan of
     * the team times of every puzzle in the collection) to an index-backed jsonb containment:
     * every player must see exactly the same unsolved puzzles as before, from all three methods.
     */
    public function testUnsolvedPuzzlesMatchThePreviousTeamMembershipPredicate(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        /** @var list<string> $playerIds */
        $playerIds = $connection->fetchFirstColumn('SELECT DISTINCT player_id FROM collection_item');
        $solvedOnlyAsTeamMemberSeen = false;
        $othersTeamTimeSeen = false;

        foreach ($playerIds as $playerId) {
            /** @var list<string> $expected */
            $expected = $connection->fetchFirstColumn(
                <<<'SQL'
SELECT DISTINCT ci.puzzle_id
FROM collection_item ci
WHERE ci.player_id = :playerId
  AND NOT EXISTS (
    SELECT 1 FROM puzzle_solving_time pst
    WHERE pst.puzzle_id = ci.puzzle_id
      AND (
        pst.player_id = ci.player_id
        OR (pst.team IS NOT NULL AND EXISTS (
            SELECT 1 FROM json_array_elements(pst.team -> 'puzzlers') AS puzzler
            WHERE puzzler ->> 'player_id' = ci.player_id::text
        ))
      )
  )
SQL,
                ['playerId' => $playerId],
            );
            /** @var list<string> $collectionPuzzleIds */
            $collectionPuzzleIds = $connection->fetchFirstColumn(
                'SELECT DISTINCT puzzle_id FROM collection_item WHERE player_id = :playerId',
                ['playerId' => $playerId],
            );
            /** @var list<string> $unsolvedByOwnTimes */
            $unsolvedByOwnTimes = $connection->fetchFirstColumn(
                'SELECT DISTINCT ci.puzzle_id FROM collection_item ci WHERE ci.player_id = :playerId AND NOT EXISTS (SELECT 1 FROM puzzle_solving_time pst WHERE pst.puzzle_id = ci.puzzle_id AND pst.player_id = ci.player_id)',
                ['playerId' => $playerId],
            );
            /** @var list<string> $puzzlesWithTeamTimes */
            $puzzlesWithTeamTimes = $connection->fetchFirstColumn(
                'SELECT DISTINCT puzzle_id FROM puzzle_solving_time WHERE team IS NOT NULL',
            );

            $actual = array_map(
                static fn (UnsolvedPuzzleItem $item): string => $item->puzzleId,
                $this->getUnsolvedPuzzles->byPlayerId($playerId),
            );

            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, sprintf('unsolved puzzles of player %s', $playerId));
            self::assertSame(count($expected), $this->getUnsolvedPuzzles->countByPlayerId($playerId), sprintf('unsolved count of player %s', $playerId));

            foreach ($collectionPuzzleIds as $puzzleId) {
                self::assertSame(
                    in_array($puzzleId, $expected, true),
                    $this->getUnsolvedPuzzles->byPuzzleIdAndPlayerId($puzzleId, $playerId) !== null,
                    sprintf('puzzle %s of player %s', $puzzleId, $playerId),
                );
            }

            $solvedOnlyAsTeamMemberSeen = $solvedOnlyAsTeamMemberSeen || count($expected) < count($unsolvedByOwnTimes);
            $othersTeamTimeSeen = $othersTeamTimeSeen || array_intersect($expected, $puzzlesWithTeamTimes) !== [];
        }

        self::assertTrue($solvedOnlyAsTeamMemberSeen, 'Fixtures must contain a collection puzzle solved only as a team member');
        self::assertTrue($othersTeamTimeSeen, 'Fixtures must contain an unsolved collection puzzle with a team time the player is not part of');
    }
}
