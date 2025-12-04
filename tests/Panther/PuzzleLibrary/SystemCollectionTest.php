<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\PuzzleLibrary;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Panther\AbstractPantherTestCase;

final class SystemCollectionTest extends AbstractPantherTestCase
{
    public function testOwnerCanRemoveItemFromSystemCollection(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE - has items in system collection
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit system collection page
        $client->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#collection-count');

        // Get initial count
        $initialCount = (int) $client->getCrawler()->filter('#collection-count')->text();

        // Find the puzzle card for PUZZLE_1000_02 (only in system collection)
        $puzzleCardSelector = '#library-collection-' . PuzzleFixture::PUZZLE_1000_02;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Remove from collection" button (form action contains /collections/ and /remove)
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="/collections/"] button')
            ->first()
            ->click();

        // Wait for Turbo Stream to process and update the count
        $expectedCount = (string) ($initialCount - 1);
        $client->waitForElementToContain('#collection-count', $expectedCount, timeoutInSecond: 2);

        // Verify the card is removed from the list
        self::assertSelectorNotExists($puzzleCardSelector);

        // Verify the count is updated
        $newCount = $client->getCrawler()->filter('#collection-count')->text();
        self::assertEquals($expectedCount, $newCount, 'Collection count should decrease after removing item');
    }

    public function testOwnerCanMoveItemFromSystemCollectionToNamedCollection(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#collection-count');

        $initialCount = (int) $client->getCrawler()->filter('#collection-count')->text();

        // Use PUZZLE_500_03 (in system collection only, not in any named collection)
        $puzzleCardSelector = '#library-collection-' . PuzzleFixture::PUZZLE_500_03;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Move to collection" link
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/collections/"][href*="/move"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');

        // Wait for tom-select dropdown to load
        $client->waitForVisibility('#modal-frame .ts-control');
        $client->getCrawler()->filter('#modal-frame .ts-control')->first()->click();

        $client->waitForVisibility('.ts-dropdown .option');
        // Select the first collection option
        $client->getCrawler()->filter('.ts-dropdown .option')->first()->click();

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for Turbo Stream to process
        $expectedCount = (string) ($initialCount - 1);
        $client->waitForElementToContain('#collection-count', $expectedCount, timeoutInSecond: 2);

        // Verify the card is removed from the list
        self::assertSelectorNotExists($puzzleCardSelector);

        $newCount = $client->getCrawler()->filter('#collection-count')->text();
        self::assertEquals($expectedCount, $newCount, 'Collection count should decrease after moving item');
    }

    public function testOwnerCanRemoveFromOtherCollectionWithoutAffectingCurrent(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#collection-count');

        $initialCount = $client->getCrawler()->filter('#collection-count')->text();

        // Use PUZZLE_500_02 (in system collection + COLLECTION_PUBLIC + COLLECTION_STRIPE_TREFL)
        $puzzleCardSelector = '#library-collection-' . PuzzleFixture::PUZZLE_500_02;
        $client->waitForVisibility($puzzleCardSelector);

        // Count how many remove buttons exist before
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Get count of remove form buttons before
        $removeButtonsBefore = $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="/collections/"] button')
            ->count();

        // Click the second remove button (remove from a named collection, not system collection)
        // The first button removes from current (system) collection, second removes from another
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="/collections/"] button')
            ->eq(1)
            ->click();

        // Wait for Turbo Stream to process
        usleep(1500000);

        // Card should still exist
        self::assertSelectorExists($puzzleCardSelector);

        // Count should remain the same
        $newCount = $client->getCrawler()->filter('#collection-count')->text();
        self::assertEquals($initialCount, $newCount, 'Collection count should stay same when removing from other collection');

        // Reopen dropdown to verify one less remove button
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $removeButtonsAfter = $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="/collections/"] button')
            ->count();

        self::assertLessThan($removeButtonsBefore, $removeButtonsAfter, 'Should have one less remove button after removing from other collection');
    }

    public function testOwnerCanAddItemToAnotherCollection(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#collection-count');

        $initialCount = $client->getCrawler()->filter('#collection-count')->text();

        // Use PUZZLE_2000 (only in system collection)
        $puzzleCardSelector = '#library-collection-' . PuzzleFixture::PUZZLE_2000;
        $client->waitForVisibility($puzzleCardSelector);

        // Open dropdown
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Add to collection" link
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/collections/"][href*="/add"]')
            ->first()
            ->click();

        // Wait for modal
        $client->waitForVisibility('#modal-frame');

        // Select collection from tom-select
        $client->waitForVisibility('#modal-frame .ts-control');
        $client->getCrawler()->filter('#modal-frame .ts-control')->first()->click();

        $client->waitForVisibility('.ts-dropdown .option');
        $client->getCrawler()->filter('.ts-dropdown .option')->first()->click();

        // Submit
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for toast to appear
        $client->waitForVisibility('#toast-container .toast');

        // Card should still exist
        self::assertSelectorExists($puzzleCardSelector);

        // Count should remain the same
        $newCount = $client->getCrawler()->filter('#collection-count')->text();
        self::assertEquals($initialCount, $newCount, 'Collection count should stay same when adding to another collection');
    }

    public function testOwnerCanAddItemToSellSwap(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#collection-count');

        $initialCount = $client->getCrawler()->filter('#collection-count')->text();

        // Use PUZZLE_2000 (in system collection, lent but no sell/swap)
        $puzzleCardSelector = '#library-collection-' . PuzzleFixture::PUZZLE_2000;
        $client->waitForVisibility($puzzleCardSelector);

        // Open dropdown
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Add to sell/swap" link
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/sell-swap/"]')
            ->first()
            ->click();

        // Wait for modal
        $client->waitForVisibility('#modal-frame');
        $client->waitForVisibility('#modal-frame form');

        // Select "Sell" option (second radio button)
        $client->getCrawler()
            ->filter('#modal-frame input[type="radio"]')
            ->eq(1)
            ->click();

        // Fill in price
        $client->getCrawler()
            ->filter('#modal-frame input[name*="price"]')
            ->first()
            ->sendKeys('80');

        // Select condition
        $client->executeScript("
            const select = document.querySelector('#modal-frame select');
            select.selectedIndex = 1;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        ");

        // Submit
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for processing
        usleep(2000000);

        // Card should still exist
        self::assertSelectorExists($puzzleCardSelector);

        // Count should remain the same
        $newCount = $client->getCrawler()->filter('#collection-count')->text();
        self::assertEquals($initialCount, $newCount, 'Collection count should stay same when adding to sell/swap');
    }

    public function testOwnerCanMarkItemAsSold(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#collection-count');

        $initialCount = (int) $client->getCrawler()->filter('#collection-count')->text();

        // Use PUZZLE_1000_02 (has SELLSWAP_05, NOT lent - important because lent puzzles can't be sold)
        $puzzleCardSelector = '#library-collection-' . PuzzleFixture::PUZZLE_1000_02;
        $client->waitForVisibility($puzzleCardSelector);

        // Open dropdown
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Mark as sold" link
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="mark-sold"]')
            ->first()
            ->click();

        // Wait for modal
        $client->waitForVisibility('#modal-frame');
        $client->waitForVisibility('#modal-frame form');

        // Submit (buyer is optional)
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for Turbo Stream to process
        usleep(2000000);

        // Take screenshot for debugging if test fails
        $client->takeScreenshot(__DIR__ . '/../../../var/systemcollection_marksold_after.png');

        // Card should be removed (mark as sold removes from all collections)
        self::assertSelectorNotExists($puzzleCardSelector);

        // Count should decrease
        $newCount = (int) $client->getCrawler()->filter('#collection-count')->text();
        self::assertLessThan($initialCount, $newCount, 'Collection count should decrease after marking as sold');
    }

    public function testOwnerCanRemoveFromSellSwap(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#collection-count');

        $initialCount = $client->getCrawler()->filter('#collection-count')->text();

        // Use PUZZLE_1000_02 (has SELLSWAP_05 - Swap)
        $puzzleCardSelector = '#library-collection-' . PuzzleFixture::PUZZLE_1000_02;
        $client->waitForVisibility($puzzleCardSelector);

        // Scroll to the card
        $client->executeScript("document.querySelector('" . $puzzleCardSelector . "').scrollIntoView({block: 'center'});");
        usleep(300000);

        // Open dropdown using JavaScript
        $client->executeScript("document.querySelector('" . $puzzleCardSelector . " .dropdown-toggle').click();");

        usleep(500000);
        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu.show');

        // Click "Remove from sell/swap" button using JavaScript
        $client->executeScript("
            const card = document.querySelector('" . $puzzleCardSelector . "');
            const forms = card.querySelectorAll('.dropdown-menu form');
            for (const form of forms) {
                if (form.action.includes('sell-swap') && form.action.includes('remove')) {
                    form.querySelector('button').click();
                    break;
                }
            }
        ");

        // Wait for Turbo Stream
        usleep(1500000);

        // Card should still exist
        self::assertSelectorExists($puzzleCardSelector);

        // Count should remain the same
        $newCount = $client->getCrawler()->filter('#collection-count')->text();
        self::assertEquals($initialCount, $newCount, 'Collection count should stay same when removing from sell/swap');
    }

    public function testOwnerCanBorrowPuzzleFromPlayer(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#collection-count');

        $initialCount = $client->getCrawler()->filter('#collection-count')->text();

        // Use PUZZLE_500_02 (in system collection, not borrowed, not lent)
        $puzzleCardSelector = '#library-collection-' . PuzzleFixture::PUZZLE_500_02;
        $client->waitForVisibility($puzzleCardSelector);

        // Open dropdown
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Borrow from player" link
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/borrow/"]')
            ->first()
            ->click();

        // Wait for modal
        $client->waitForVisibility('#modal-frame');
        $client->waitForVisibility('#modal-frame form');

        // Fill in owner code
        $client->getCrawler()
            ->filter('#modal-frame input[name*="ownerCode"]')
            ->first()
            ->sendKeys('#player1');

        // Submit
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for Turbo Stream
        usleep(2000000);

        // Card should still exist
        self::assertSelectorExists($puzzleCardSelector);

        // Count should remain the same
        $newCount = $client->getCrawler()->filter('#collection-count')->text();
        self::assertEquals($initialCount, $newCount, 'Collection count should stay same when borrowing');
    }

    public function testOwnerCanLendPuzzleToPlayer(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#collection-count');

        $initialCount = $client->getCrawler()->filter('#collection-count')->text();

        // Use PUZZLE_500_02 (in system collection, not lent)
        $puzzleCardSelector = '#library-collection-' . PuzzleFixture::PUZZLE_500_02;
        $client->waitForVisibility($puzzleCardSelector);

        // Open dropdown
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Lend to player" link
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/lend/"]')
            ->first()
            ->click();

        // Wait for modal
        $client->waitForVisibility('#modal-frame');
        $client->waitForVisibility('#modal-frame form');

        // Fill in borrower code
        $client->getCrawler()
            ->filter('#modal-frame input[name*="borrowerCode"]')
            ->first()
            ->sendKeys('#player1');

        // Submit
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for Turbo Stream
        usleep(2000000);

        // Card should still exist with puzzle-lent class
        self::assertSelectorExists($puzzleCardSelector);

        // Count should remain the same
        $newCount = $client->getCrawler()->filter('#collection-count')->text();
        self::assertEquals($initialCount, $newCount, 'Collection count should stay same when lending');
    }

    public function testOwnerCanReturnLentPuzzle(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#collection-count');

        $initialCount = $client->getCrawler()->filter('#collection-count')->text();

        // Use PUZZLE_2000 (lent to player1 via LENT_01)
        $puzzleCardSelector = '#library-collection-' . PuzzleFixture::PUZZLE_2000;
        $client->waitForVisibility($puzzleCardSelector);

        // Open dropdown
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Return" button (form action contains return-puzzle)
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="return-puzzle"] button')
            ->first()
            ->click();

        // Wait for Turbo Stream
        usleep(2000000);

        // Card should still exist
        self::assertSelectorExists($puzzleCardSelector);

        // Count should remain the same
        $newCount = $client->getCrawler()->filter('#collection-count')->text();
        self::assertEquals($initialCount, $newCount, 'Collection count should stay same when returning lent puzzle');
    }

    public function testOwnerCanPassLentPuzzleToSomeoneElse(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#collection-count');

        $initialCount = $client->getCrawler()->filter('#collection-count')->text();

        // Use PUZZLE_1500_01 (lent to Jane Doe via LENT_02)
        $puzzleCardSelector = '#library-collection-' . PuzzleFixture::PUZZLE_1500_01;
        $client->waitForVisibility($puzzleCardSelector);

        // Open dropdown
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Pass" link
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="pass-puzzle"]')
            ->first()
            ->click();

        // Wait for modal
        $client->waitForVisibility('#modal-frame');
        $client->waitForVisibility('#modal-frame form');

        // Fill in new holder code
        $client->getCrawler()
            ->filter('#modal-frame input[name*="newHolderCode"]')
            ->first()
            ->sendKeys('#player3');

        // Submit
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for Turbo Stream
        usleep(2000000);

        // Card should still exist
        self::assertSelectorExists($puzzleCardSelector);

        // Count should remain the same
        $newCount = $client->getCrawler()->filter('#collection-count')->text();
        self::assertEquals($initialCount, $newCount, 'Collection count should stay same when passing lent puzzle');
    }

    // Note: Borrowed puzzles (via LentPuzzle) don't appear in collections - they only appear in the unsolved puzzles view.
    // Tests for borrower return/pass actions are in UnsolvedListTest.
}
