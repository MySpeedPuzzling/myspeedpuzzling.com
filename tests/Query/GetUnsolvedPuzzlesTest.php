<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
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
}
