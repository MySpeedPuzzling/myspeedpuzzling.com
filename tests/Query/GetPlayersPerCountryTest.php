<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayersPerCountry;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UserBlockFixture: PLAYER_REGULAR blocks PLAYER_PRIVATE (country "us").
 */
final class GetPlayersPerCountryTest extends KernelTestCase
{
    private GetPlayersPerCountry $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPlayersPerCountry::class);
    }

    public function testByCountryLeavesOutThePlayerTheViewerBlocks(): void
    {
        $everyone = $this->playerIdsIn(CountryCode::us);
        self::assertContains(PlayerFixture::PLAYER_PRIVATE, $everyone);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::assertSame(
            array_values(array_diff($everyone, [PlayerFixture::PLAYER_PRIVATE])),
            $this->playerIdsIn(CountryCode::us),
        );

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        self::assertSame($everyone, $this->playerIdsIn(CountryCode::us));
    }

    /**
     * @return list<string>
     */
    private function playerIdsIn(CountryCode $countryCode): array
    {
        return array_values(array_map(static fn ($p) => $p->playerId, $this->query->byCountry($countryCode)));
    }
}
