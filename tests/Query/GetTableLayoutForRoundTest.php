<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetTableLayoutForRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetTableLayoutForRoundTest extends KernelTestCase
{
    private GetTableLayoutForRound $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetTableLayoutForRound::class);
    }

    public function testReturnsNestedStructure(): void
    {
        $rows = $this->query->byRoundId(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        self::assertCount(2, $rows);
        self::assertCount(2, $rows[0]->tables);
        self::assertCount(2, $rows[1]->tables);
        self::assertCount(2, $rows[0]->tables[0]->spots);
    }

    public function testSpotsShowPlayerData(): void
    {
        $rows = $this->query->byRoundId(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $assignedSpot = $rows[0]->tables[0]->spots[0];
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $assignedSpot->playerId);
        self::assertNotNull($assignedSpot->playerName);
        self::assertTrue($assignedSpot->isAssigned());
    }

    public function testEmptyRoundReturnsEmptyArray(): void
    {
        $rows = $this->query->byRoundId(CompetitionRoundFixture::ROUND_CZECH_FINAL);

        self::assertSame([], $rows);
    }

    public function testOrderingIsCorrect(): void
    {
        $rows = $this->query->byRoundId(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        self::assertSame(1, $rows[0]->position);
        self::assertSame(2, $rows[1]->position);
        self::assertSame(1, $rows[0]->tables[0]->position);
        self::assertSame(2, $rows[0]->tables[1]->position);
        self::assertSame(1, $rows[0]->tables[0]->spots[0]->position);
        self::assertSame(2, $rows[0]->tables[0]->spots[1]->position);
    }
}
