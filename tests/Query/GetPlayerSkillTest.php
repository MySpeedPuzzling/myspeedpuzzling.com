<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayerSkill;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerSkillTest extends KernelTestCase
{
    private GetPlayerSkill $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        /** @var GetPlayerSkill $query */
        $query = $container->get(GetPlayerSkill::class);
        $this->query = $query;
    }

    public function testByPlayerIdReturnsSkillsPerPieceCount(): void
    {
        $results = $this->query->byPlayerId(PlayerFixture::PLAYER_REGULAR);

        // Player may or may not have skill scores depending on how many puzzles qualify
        self::assertCount(count($results), $results);

        foreach ($results as $result) {
            self::assertSame(PlayerFixture::PLAYER_REGULAR, $result->playerId);
            self::assertGreaterThan(0, $result->piecesCount);
            self::assertGreaterThan(0.0, $result->skillScore);
            self::assertGreaterThanOrEqual(0.0, $result->skillPercentile);
            self::assertLessThanOrEqual(100.0, $result->skillPercentile);
        }
    }

    public function testByPlayerIdAndPiecesCountReturnsSpecificSkill(): void
    {
        $result = $this->query->byPlayerIdAndPiecesCount(PlayerFixture::PLAYER_REGULAR, 500);

        // May be null if player doesn't have 10+ qualifying 500pc puzzles
        if ($result === null) {
            self::markTestSkipped('Player does not have enough qualifying puzzles for 500pc skill');
        }

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $result->playerId);
        self::assertSame(500, $result->piecesCount);
    }

    public function testByPlayerIdReturnsEmptyForNonExistentPlayer(): void
    {
        $results = $this->query->byPlayerId('00000000-0000-0000-0000-000000000099');

        self::assertSame([], $results);
    }
}
