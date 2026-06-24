<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetComparisonPlayers;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetComparisonPlayersTest extends KernelTestCase
{
    private GetComparisonPlayers $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetComparisonPlayers::class);
    }

    public function testReturnsPlayersKeyedByIdWithDisplayData(): void
    {
        $players = $this->query->byIds([ComparisonFixture::CMP_A, ComparisonFixture::CMP_D]);

        self::assertArrayHasKey(ComparisonFixture::CMP_A, $players);
        self::assertArrayHasKey(ComparisonFixture::CMP_D, $players);

        $playerA = $players[ComparisonFixture::CMP_A];
        self::assertSame('Compare A', $playerA->playerName);
        self::assertSame('Compare A', $playerA->displayName());
        // Code is normalised to uppercase
        self::assertSame('CMPA', $playerA->playerCode);
        self::assertNotNull($playerA->playerCountry);
        self::assertFalse($playerA->isPrivate);
        // CMP_A has no computed skill (no baseline) -> tier null, not opted out.
        self::assertNull($playerA->skillTierName);
        self::assertFalse($playerA->rankingOptedOut);
    }

    public function testReportsPrivacyFlag(): void
    {
        $players = $this->query->byIds([ComparisonFixture::CMP_D]);

        self::assertTrue($players[ComparisonFixture::CMP_D]->isPrivate);
    }

    public function testReturnsEmptyArrayForNoIds(): void
    {
        self::assertSame([], $this->query->byIds([]));
    }

    public function testIgnoresUnknownIds(): void
    {
        $players = $this->query->byIds([ComparisonFixture::CMP_A, '018d00c0-0000-0000-0000-0000000000ff']);

        self::assertCount(1, $players);
        self::assertArrayHasKey(ComparisonFixture::CMP_A, $players);
    }
}
