<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetTransactionRatings;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SoldSwappedItemFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetTransactionRatingsTest extends KernelTestCase
{
    private GetTransactionRatings $getTransactionRatings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getTransactionRatings = self::getContainer()->get(GetTransactionRatings::class);
    }

    public function testForPlayerReturnsRatingsReceived(): void
    {
        // PLAYER_REGULAR received a rating from PLAYER_ADMIN in fixture
        $ratings = $this->getTransactionRatings->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertNotEmpty($ratings);
        self::assertSame(5, $ratings[0]->stars);
        self::assertSame('Great buyer, fast payment!', $ratings[0]->reviewText);
    }

    public function testAverageForPlayerReturnsCorrectValues(): void
    {
        $summary = $this->getTransactionRatings->averageForPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertNotNull($summary);
        self::assertSame(1, $summary->ratingCount);
        self::assertSame(5.0, $summary->averageRating);
    }

    public function testAverageForPlayerWithNoRatingsReturnsNull(): void
    {
        $summary = $this->getTransactionRatings->averageForPlayer(PlayerFixture::PLAYER_PRIVATE);

        self::assertNull($summary);
    }

    public function testCanRateReturnsTrueForEligibleTransaction(): void
    {
        // SOLD_RECENT: PLAYER_REGULAR is buyer, can still rate
        $canRate = $this->getTransactionRatings->canRate(
            SoldSwappedItemFixture::SOLD_RECENT,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertTrue($canRate);
    }

    public function testCanRateReturnsFalseForAlreadyRated(): void
    {
        // SOLD_01 already rated by PLAYER_ADMIN
        $canRate = $this->getTransactionRatings->canRate(
            SoldSwappedItemFixture::SOLD_01,
            PlayerFixture::PLAYER_ADMIN,
        );

        self::assertFalse($canRate);
    }

    public function testCanRateReturnsFalseForExpiredTransaction(): void
    {
        // SOLD_EXPIRED is -60 days old
        $canRate = $this->getTransactionRatings->canRate(
            SoldSwappedItemFixture::SOLD_EXPIRED,
            PlayerFixture::PLAYER_ADMIN,
        );

        self::assertFalse($canRate);
    }

    public function testCanRateReturnsFalseForNonParticipant(): void
    {
        $canRate = $this->getTransactionRatings->canRate(
            SoldSwappedItemFixture::SOLD_RECENT,
            PlayerFixture::PLAYER_PRIVATE,
        );

        self::assertFalse($canRate);
    }

    public function testPendingRatingsReturnsUnratedTransactions(): void
    {
        // PLAYER_REGULAR is buyer of SOLD_RECENT (can rate) and buyer of SOLD_01 (already rated by seller, but buyer can still rate)
        $pending = $this->getTransactionRatings->pendingRatings(PlayerFixture::PLAYER_REGULAR);

        self::assertNotEmpty($pending);
    }
}
