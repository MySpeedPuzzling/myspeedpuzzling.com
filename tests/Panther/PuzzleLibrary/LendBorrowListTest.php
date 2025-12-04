<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\PuzzleLibrary;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Panther\AbstractPantherTestCase;

final class LendBorrowListTest extends AbstractPantherTestCase
{
    public function testOwnerCanPassAndReturnLentPuzzleViaTurboStream(): void
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

        // Verify initial holder is John Doe (PLAYER_REGULAR)
        $holderBadge = $client->getCrawler()->filter($puzzleCardSelector . ' .badge.bg-purple')->text();
        self::assertStringContainsString('John Doe', $holderBadge, 'Initial holder should be John Doe');

        // ============================================
        // STEP 1: Pass puzzle to an existing player
        // ============================================

        // Open the dropdown menu on the puzzle card
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        // Wait for dropdown to open
        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Pass to someone else" link to open modal
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/pass-puzzle/"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');

        // Fill in the form with existing player code (PLAYER_WITH_FAVORITES - Michael Johnson)
        $client->getCrawler()
            ->filter('#modal-frame input[name*="newHolderCode"]')
            ->first()
            ->sendKeys('#player3');

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for the card to update with new holder name
        $client->waitForElementToContain($puzzleCardSelector . ' .badge.bg-purple', 'Michael Johnson');

        // Verify holder name changed to Michael Johnson
        $holderBadge = $client->getCrawler()->filter($puzzleCardSelector . ' .badge.bg-purple')->text();
        self::assertStringContainsString('Michael Johnson', $holderBadge, 'Holder should be Michael Johnson after pass');

        // Verify lent count is still 4 (pass doesn't change count)
        $lentTabCount = $client->getCrawler()->filter('#lent-tab-count')->text();
        self::assertEquals('4', $lentTabCount, 'Lent count should still be 4 after passing');

        // ============================================
        // STEP 2: Pass puzzle to a non-existing player
        // ============================================

        // Open the dropdown menu again
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        // Wait for dropdown to open
        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Pass to someone else" link to open modal
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/pass-puzzle/"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');

        // Fill in the form with non-existing player name (plain text, no #)
        $client->getCrawler()
            ->filter('#modal-frame input[name*="newHolderCode"]')
            ->first()
            ->sendKeys('SOME_PLAYER');

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for the card to update with new holder name
        $client->waitForElementToContain($puzzleCardSelector . ' .badge.bg-purple', 'SOME_PLAYER');

        // Verify holder name changed to SOME_PLAYER
        $holderBadge = $client->getCrawler()->filter($puzzleCardSelector . ' .badge.bg-purple')->text();
        self::assertStringContainsString('SOME_PLAYER', $holderBadge, 'Holder should be SOME_PLAYER after pass');

        // Verify lent count is still 4
        $lentTabCount = $client->getCrawler()->filter('#lent-tab-count')->text();
        self::assertEquals('4', $lentTabCount, 'Lent count should still be 4 after passing to non-registered');

        // ============================================
        // STEP 3: Return puzzle to owner
        // ============================================

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

    public function testBorrowerCanReturnPuzzleToOwnerViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_REGULAR - who has borrowed a puzzle from PLAYER_WITH_STRIPE
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            email: PlayerFixture::PLAYER_REGULAR_EMAIL,
            name: PlayerFixture::PLAYER_REGULAR_NAME,
        );

        // Visit lend-borrow list page for PLAYER_REGULAR
        $client->request('GET', '/en/lend-borrow-list/' . PlayerFixture::PLAYER_REGULAR);

        // Wait for page to load
        $client->waitFor('body');

        // Switch to borrowed tab
        $client->getCrawler()->filter('#borrowed-tab')->click();

        // Wait for the borrowed tab to become active
        $client->waitForVisibility('#borrowed-pane.active');

        // Wait for the borrowed tab count to be visible
        $client->waitForVisibility('#borrowed-tab-count');

        // Get initial count from the "Borrowed" tab
        $borrowedTabCount = $client->getCrawler()->filter('#borrowed-tab-count')->text();
        self::assertEquals('1', $borrowedTabCount, 'Initial borrowed count should be 1');

        // Find the puzzle card for PUZZLE_2000 (borrowed by PLAYER_REGULAR)
        $puzzleCardSelector = '#library-lend-borrow-borrowed-' . PuzzleFixture::PUZZLE_2000;
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
        $client->waitForElementToContain('#borrowed-tab-count', '0');

        // Verify the card is removed from the list
        self::assertSelectorNotExists($puzzleCardSelector);

        // Verify the tab count is updated
        $newBorrowedTabCount = $client->getCrawler()->filter('#borrowed-tab-count')->text();
        self::assertEquals('0', $newBorrowedTabCount, 'Borrowed count should decrease to 0 after returning');
    }

    /**
     * @return array<string, array{newHolderInput: string}>
     */
    public static function passToSomeoneElseDataProvider(): array
    {
        return [
            'existing player code' => [
                'newHolderInput' => '#player1',
            ],
            'non-existing player name' => [
                'newHolderInput' => 'ANOTHER_PLAYER',
            ],
        ];
    }

    #[DataProvider('passToSomeoneElseDataProvider')]
    public function testBorrowerCanPassPuzzleToSomeoneElseViaTurboStream(string $newHolderInput): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_FAVORITES - who has borrowed PUZZLE_500_03 from PLAYER_WITH_STRIPE
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID,
            email: PlayerFixture::PLAYER_WITH_FAVORITES_EMAIL,
            name: PlayerFixture::PLAYER_WITH_FAVORITES_NAME,
        );

        // Visit lend-borrow list page for PLAYER_WITH_FAVORITES
        $client->request('GET', '/en/lend-borrow-list/' . PlayerFixture::PLAYER_WITH_FAVORITES);

        // Wait for page to load
        $client->waitFor('body');

        // Switch to borrowed tab
        $client->getCrawler()->filter('#borrowed-tab')->click();

        // Wait for the borrowed tab to become active
        $client->waitForVisibility('#borrowed-pane.active');

        // Wait for the borrowed tab count to be visible
        $client->waitForVisibility('#borrowed-tab-count');

        // Get initial count from the "Borrowed" tab
        $borrowedTabCount = $client->getCrawler()->filter('#borrowed-tab-count')->text();
        self::assertEquals('1', $borrowedTabCount, 'Initial borrowed count should be 1');

        // Find the puzzle card for PUZZLE_500_03 (borrowed by PLAYER_WITH_FAVORITES)
        $puzzleCardSelector = '#library-lend-borrow-borrowed-' . PuzzleFixture::PUZZLE_500_03;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu on the puzzle card
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        // Wait for dropdown to open
        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Pass to someone else" link to open modal
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/pass-puzzle/"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');

        // Fill in the form with the new holder input (existing player code or non-existing name)
        $client->getCrawler()
            ->filter('#modal-frame input[name*="newHolderCode"]')
            ->first()
            ->sendKeys($newHolderInput);

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for Turbo Stream to process and remove the card
        $client->waitForElementToContain('#borrowed-tab-count', '0');

        // Verify the card is removed from the list
        self::assertSelectorNotExists($puzzleCardSelector);

        // Verify the tab count is updated
        $newBorrowedTabCount = $client->getCrawler()->filter('#borrowed-tab-count')->text();
        self::assertEquals('0', $newBorrowedTabCount, 'Borrowed count should decrease to 0 after passing');
    }
}
