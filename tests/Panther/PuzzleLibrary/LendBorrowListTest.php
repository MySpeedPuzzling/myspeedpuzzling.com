<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\PuzzleLibrary;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Panther\AbstractPantherTestCase;

final class LendBorrowListTest extends AbstractPantherTestCase
{
    public function testOwnerCanReturnLentPuzzleViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE - the owner who lent puzzles to others
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit lend-borrow list page
        $client->request('GET', '/en/lend-borrow-list/' . PlayerFixture::PLAYER_WITH_STRIPE);

        // Wait for page to load
        $client->waitFor('body');

        // Wait for the lent tab count to be visible
        $client->waitForVisibility('#lent-tab-count');

        // Get initial count from the "Lent out" tab
        $lentTabCount = $client->getCrawler()->filter('#lent-tab-count')->text();
        self::assertEquals('4', $lentTabCount, 'Initial lent count should be 4');

        // Find the puzzle card for PUZZLE_2000 (LENT_01)
        $puzzleCardSelector = '#library-lend-borrow-lent-' . PuzzleFixture::PUZZLE_2000;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu on the puzzle card
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        // Wait for dropdown to open
        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click the "Return to owner" button (it's a form submit button)
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="/return-puzzle/"] button')
            ->first()
            ->click();

        // Wait for Turbo Stream to process and remove the card
        $client->waitForElementToContain('#lent-tab-count', '3');

        // Verify the card is removed from the list
        self::assertSelectorNotExists($puzzleCardSelector);

        // Verify the tab count is updated
        $newLentTabCount = $client->getCrawler()->filter('#lent-tab-count')->text();
        self::assertEquals('3', $newLentTabCount, 'Lent count should decrease to 3 after returning');
    }
}
