<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetTransactionRatings;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SoldSwappedItemFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetTransactionRatingsForConversationTest extends KernelTestCase
{
    private GetTransactionRatings $getTransactionRatings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getTransactionRatings = self::getContainer()->get(GetTransactionRatings::class);
    }

    public function testForConversationReturnsRatingInfoWhenTransactionExists(): void
    {
        // CONVERSATION_MARKETPLACE_COMPLETED: WITH_FAVORITES ↔ WITH_STRIPE, puzzle=PUZZLE_500_01
        // SOLD_MARKETPLACE: WITH_STRIPE(seller) → WITH_FAVORITES(buyer), puzzle=PUZZLE_500_01
        $result = $this->getTransactionRatings->forConversation(
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            playerAId: PlayerFixture::PLAYER_WITH_FAVORITES,
            playerBId: PlayerFixture::PLAYER_WITH_STRIPE,
            viewerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        );

        self::assertNotNull($result);
        self::assertSame(SoldSwappedItemFixture::SOLD_MARKETPLACE, $result->soldSwappedItemId);
        self::assertTrue($result->canRate);
        self::assertNull($result->myRatingStars);
    }

    public function testForConversationReturnsNullWhenNoTransaction(): void
    {
        // CONVERSATION_ACCEPTED: REGULAR ↔ ADMIN, no puzzle
        $result = $this->getTransactionRatings->forConversation(
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            playerAId: PlayerFixture::PLAYER_REGULAR,
            playerBId: PlayerFixture::PLAYER_ADMIN,
            viewerId: PlayerFixture::PLAYER_REGULAR,
        );

        self::assertNull($result);
    }

    public function testForConversationShowsExistingRating(): void
    {
        // SOLD_01: ADMIN(seller) → REGULAR(buyer), puzzle=PUZZLE_500_05
        // RATING_01: ADMIN rated REGULAR for SOLD_01 (stars=5)
        // When viewer=ADMIN, should see their existing rating
        $result = $this->getTransactionRatings->forConversation(
            puzzleId: PuzzleFixture::PUZZLE_500_05,
            playerAId: PlayerFixture::PLAYER_ADMIN,
            playerBId: PlayerFixture::PLAYER_REGULAR,
            viewerId: PlayerFixture::PLAYER_ADMIN,
        );

        self::assertNotNull($result);
        self::assertSame(SoldSwappedItemFixture::SOLD_01, $result->soldSwappedItemId);
        self::assertFalse($result->canRate);
        self::assertSame(5, $result->myRatingStars);
    }

    public function testForConversationListReturnsBulkRatings(): void
    {
        $result = $this->getTransactionRatings->forConversationList(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertArrayHasKey(ConversationFixture::CONVERSATION_MARKETPLACE_COMPLETED, $result);

        $ratingInfo = $result[ConversationFixture::CONVERSATION_MARKETPLACE_COMPLETED];
        self::assertSame(SoldSwappedItemFixture::SOLD_MARKETPLACE, $ratingInfo->soldSwappedItemId);
        self::assertTrue($ratingInfo->canRate);
        self::assertNull($ratingInfo->myRatingStars);
    }

    public function testForConversationListExcludesNonMarketplaceConversations(): void
    {
        // PLAYER_REGULAR is part of CONVERSATION_ACCEPTED (no puzzle/marketplace)
        $result = $this->getTransactionRatings->forConversationList(PlayerFixture::PLAYER_REGULAR);

        self::assertArrayNotHasKey(ConversationFixture::CONVERSATION_ACCEPTED, $result);
    }
}
