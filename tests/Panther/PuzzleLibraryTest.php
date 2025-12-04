<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;

final class PuzzleLibraryTest extends AbstractPantherTestCase
{
    public function testLoggedUserCanAccessOwnPuzzleLibrary(): void
    {
        $client = self::createBrowserClient();

        // Login as the player with Stripe (has public collection visibility)
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit own puzzle library
        $client->request('GET', '/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE);

        // Verify the page loads
        self::assertPageTitleContains('MySpeedPuzzling');
    }

    public function testLoggedUserCanAccessOtherPlayerPuzzleLibrary(): void
    {
        $client = self::createBrowserClient();

        // Login as regular player
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            email: PlayerFixture::PLAYER_REGULAR_EMAIL,
            name: PlayerFixture::PLAYER_REGULAR_NAME,
        );

        // Visit another player's puzzle library (player with public collections)
        $client->request('GET', '/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE);

        // Verify the page loads
        self::assertSelectorExists('body');
        self::assertPageTitleContains('MySpeedPuzzling');
    }
}
