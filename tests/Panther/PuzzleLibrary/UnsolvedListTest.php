<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\PuzzleLibrary;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Panther\AbstractPantherTestCase;

final class UnsolvedListTest extends AbstractPantherTestCase
{
    public function testOwnerCanRemoveItemFromSingleCollectionViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE - has unsolved puzzles in collections
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit unsolved puzzles page
        $client->request('GET', '/en/unsolved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        // Wait for page to load
        $client->waitFor('body');

        // Wait for the count to be visible
        $client->waitForVisibility('#unsolved-count');

        // Get initial count
        $initialCount = (int) $client->getCrawler()->filter('#unsolved-count')->text();

        // Find the puzzle card for PUZZLE_1000_04 (only in COLLECTION_STRIPE_TREFL)
        $puzzleCardSelector = '#library-unsolved-' . PuzzleFixture::PUZZLE_1000_04;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu on the puzzle card
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        // Wait for dropdown to open
        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Remove from collection" button (it's a form submit button)
        // The form action is /en/collections/{puzzleId}/remove
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="/collections/"] button')
            ->first()
            ->click();

        // Wait for Turbo Stream to process and update the count
        $expectedCount = (string) ($initialCount - 1);
        $client->waitForElementToContain('#unsolved-count', $expectedCount, timeoutInSecond: 2);

        // Verify the card is removed from the list
        self::assertSelectorNotExists($puzzleCardSelector);

        // Verify the count is updated
        $newCount = $client->getCrawler()->filter('#unsolved-count')->text();
        self::assertEquals($expectedCount, $newCount, 'Unsolved count should decrease after removing from collection');
    }

    public function testOwnerCanRemoveItemFromOneOfTwoCollectionsViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit unsolved puzzles page
        $client->request('GET', '/en/unsolved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#unsolved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#unsolved-count')->text();

        // Find the puzzle card for PUZZLE_500_02 (in 2 collections: COLLECTION_PUBLIC + COLLECTION_STRIPE_TREFL)
        $puzzleCardSelector = '#library-unsolved-' . PuzzleFixture::PUZZLE_500_02;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click the first "Remove from collection" button (removes from one of two collections)
        // The form action is /en/collections/{puzzleId}/remove
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="/collections/"] button')
            ->first()
            ->click();

        // Wait a moment for Turbo Stream to process
        usleep(500000);

        // The card should still exist (puzzle still in another collection)
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#unsolved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Unsolved count should stay the same when puzzle is still in another collection');
    }

    public function testOwnerCanAddItemToCollectionViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit unsolved puzzles page
        $client->request('GET', '/en/unsolved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#unsolved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#unsolved-count')->text();

        // Find the puzzle card for PUZZLE_1000_03 (only in COLLECTION_PUBLIC)
        $puzzleCardSelector = '#library-unsolved-' . PuzzleFixture::PUZZLE_1000_03;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Add to collection" link to open modal
        // The href contains /collections/ and /add (e.g., /en/collections/{puzzleId}/add?context=...)
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/collections/"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');

        // Select a collection from tom-select dropdown
        $client->waitForVisibility('#modal-frame .ts-control');
        $client->getCrawler()->filter('#modal-frame .ts-control')->first()->click();

        $client->waitForVisibility('.ts-dropdown .option');
        // Select the second collection (COLLECTION_STRIPE_TREFL)
        $client->getCrawler()->filter('.ts-dropdown .option')->last()->click();

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for toast to appear (indicates success)
        $client->waitForVisibility('#toast-container .toast');

        // The card should still exist
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#unsolved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Unsolved count should stay the same when adding to another collection');
    }

    public function testOwnerCanAddItemToSellSwapViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit unsolved puzzles page
        $client->request('GET', '/en/unsolved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#unsolved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#unsolved-count')->text();

        // Find the puzzle card for PUZZLE_1000_05 (in collection, NOT on sell/swap)
        $puzzleCardSelector = '#library-unsolved-' . PuzzleFixture::PUZZLE_1000_05;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Add to sell/swap" link to open modal
        // The href is /en/sell-swap/{puzzleId}/add?context=...
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/sell-swap/"]')
            ->first()
            ->click();

        // Wait for modal to open and form to load
        $client->waitForVisibility('#modal-frame');

        // Screenshot for debugging
        $client->takeScreenshot(__DIR__ . '/../../../var/unsolved_addtosellswap_modal.png');

        // Wait for form to load (membership check should pass)
        $client->waitForVisibility('#modal-frame form');

        // Screenshot after form loads
        $client->takeScreenshot(__DIR__ . '/../../../var/unsolved_addtosellswap_form.png');

        // Fill in the form - select "Sell" option by clicking the radio button's label
        // Radio buttons in Symfony forms use IDs like add_to_sell_swap_list_form_listingType_1 for "Sell"
        $client->getCrawler()
            ->filter('#modal-frame input[type="radio"]')
            ->eq(1)  // Second radio button is "Sell"
            ->click();

        // Fill in price
        $client->getCrawler()
            ->filter('#modal-frame input[name*="price"]')
            ->first()
            ->sendKeys('30');

        // Select condition (required field) - click to open, then select an option
        // Open the select dropdown
        $client->getCrawler()
            ->filter('#modal-frame select')
            ->first()
            ->click();

        // Select "Like new" option by index (first non-placeholder option)
        $client->executeScript("
            const select = document.querySelector('#modal-frame select');
            select.selectedIndex = 1;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        ");

        // Screenshot before submit
        $client->takeScreenshot(__DIR__ . '/../../../var/unsolved_addtosellswap_before_submit.png');

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait a moment for turbo stream to process
        usleep(2000000);  // 2 seconds

        // The card should still exist (puzzle still in collection)
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#unsolved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Unsolved count should stay the same when adding to sell/swap');
    }

    public function testOwnerCanMarkItemAsSoldViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit unsolved puzzles page
        $client->request('GET', '/en/unsolved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#unsolved-count');

        // Get initial count
        $initialCount = (int) $client->getCrawler()->filter('#unsolved-count')->text();

        // Find the puzzle card for PUZZLE_1000_03 (in collection + on sell/swap as SELLSWAP_07)
        $puzzleCardSelector = '#library-unsolved-' . PuzzleFixture::PUZZLE_1000_03;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Mark as sold" link to open modal (href contains mark-sold)
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="mark-sold"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');
        $client->waitForVisibility('#modal-frame form');

        // Submit the form (buyer is optional)
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for turbo stream to process
        usleep(2000000);

        // Verify the card is removed from the list
        self::assertSelectorNotExists($puzzleCardSelector);

        // Verify the count decreased
        $newCount = (int) $client->getCrawler()->filter('#unsolved-count')->text();
        self::assertLessThan($initialCount, $newCount, 'Unsolved count should decrease after marking as sold');
    }

    public function testOwnerCanRemoveItemFromSellSwapViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit unsolved puzzles page
        $client->request('GET', '/en/unsolved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#unsolved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#unsolved-count')->text();

        // Find the puzzle card for PUZZLE_500_02 (in collection + on sell/swap as SELLSWAP_02)
        // UUID: 018d0003-0000-0000-0000-000000000002 = "Puzzle 2" (500 pieces, Ravensburger)
        $puzzleCardSelector = '#library-unsolved-' . PuzzleFixture::PUZZLE_500_02;

        // Wait for the specific card
        $client->waitForVisibility($puzzleCardSelector);

        // Scroll to the card to ensure it's in view
        $client->executeScript("document.querySelector('" . $puzzleCardSelector . "').scrollIntoView({block: 'center'});");
        usleep(300000);

        // Open the dropdown menu using JavaScript to ensure we click the right element
        $client->executeScript("document.querySelector('" . $puzzleCardSelector . " .dropdown-toggle').click();");

        // Wait for dropdown to appear
        usleep(500000);
        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu.show');

        // Debug: take screenshot
        $client->takeScreenshot(__DIR__ . '/../../../var/unsolved_removesellswap_dropdown.png');

        // Click "Remove from sell/swap" button using JavaScript
        // The form action contains /sell-swap/ and ends with /remove
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

        // Wait for Turbo Stream to process
        usleep(1500000);

        // Take screenshot after action
        $client->takeScreenshot(__DIR__ . '/../../../var/unsolved_removesellswap_after.png');

        // The card should still exist (puzzle still in collection)
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#unsolved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Unsolved count should stay the same when removing from sell/swap');
    }

    public function testOwnerCanBorrowPuzzleFromPlayerViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit unsolved puzzles page
        $client->request('GET', '/en/unsolved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#unsolved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#unsolved-count')->text();

        // Find the puzzle card for PUZZLE_300 (in collection, not borrowed)
        $puzzleCardSelector = '#library-unsolved-' . PuzzleFixture::PUZZLE_300;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Borrow from player" link to open modal (route is /en/borrow/{puzzleId})
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/borrow/"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');
        $client->waitForVisibility('#modal-frame form');

        // Fill in the form with owner code (PLAYER_REGULAR)
        $client->getCrawler()
            ->filter('#modal-frame input[name*="ownerCode"]')
            ->first()
            ->sendKeys('#player1');

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for turbo stream to process
        usleep(2000000);

        // The card should still exist
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#unsolved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Unsolved count should stay the same when borrowing');
    }

    public function testOwnerCanLendPuzzleToPlayerViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit unsolved puzzles page
        $client->request('GET', '/en/unsolved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#unsolved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#unsolved-count')->text();

        // Find the puzzle card for PUZZLE_500_04 (in collection, not lent)
        $puzzleCardSelector = '#library-unsolved-' . PuzzleFixture::PUZZLE_500_04;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Lend to player" link to open modal (route is /en/lend/{puzzleId})
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/lend/"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');
        $client->waitForVisibility('#modal-frame form');

        // Fill in the form with borrower code (PLAYER_REGULAR)
        $client->getCrawler()
            ->filter('#modal-frame input[name*="borrowerCode"]')
            ->first()
            ->sendKeys('#player1');

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for turbo stream to process
        usleep(2000000);

        // The card should still exist with .puzzle-lent class
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#unsolved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Unsolved count should stay the same when lending');
    }

    public function testBorrowerCanReturnPuzzleToOwnerViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE (has borrowed PUZZLE_3000 from PLAYER_REGULAR via LENT_06)
        // Note: PUZZLE_3000 is unsolved by PLAYER_WITH_STRIPE, unlike PUZZLE_1500_02 which is solved
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit unsolved puzzles page
        $client->request('GET', '/en/unsolved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#unsolved-count');

        // Get initial count
        $initialCount = (int) $client->getCrawler()->filter('#unsolved-count')->text();

        // Find the puzzle card for PUZZLE_3000 (borrowed from PLAYER_REGULAR via LENT_06)
        $puzzleCardSelector = '#library-unsolved-' . PuzzleFixture::PUZZLE_3000;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Return" button (form action is /en/return-puzzle/{lentPuzzleId})
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="return-puzzle"] button')
            ->first()
            ->click();

        // Wait for Turbo Stream to process
        usleep(2000000);

        // Verify the card is removed from the list (borrowed puzzle returned)
        self::assertSelectorNotExists($puzzleCardSelector);

        // Verify the count decreased
        $newCount = (int) $client->getCrawler()->filter('#unsolved-count')->text();
        self::assertLessThan($initialCount, $newCount, 'Unsolved count should decrease after returning borrowed puzzle');
    }

    public function testBorrowerCanPassPuzzleToSomeoneElseViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE (has borrowed PUZZLE_3000 from PLAYER_REGULAR via LENT_06)
        // Note: PUZZLE_3000 is unsolved by PLAYER_WITH_STRIPE, unlike PUZZLE_1500_02 which is solved
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit unsolved puzzles page
        $client->request('GET', '/en/unsolved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#unsolved-count');

        // Get initial count
        $initialCount = (int) $client->getCrawler()->filter('#unsolved-count')->text();

        // Find the puzzle card for PUZZLE_3000 (borrowed from PLAYER_REGULAR via LENT_06)
        $puzzleCardSelector = '#library-unsolved-' . PuzzleFixture::PUZZLE_3000;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Pass" link to open modal (route is /en/pass-puzzle/{lentPuzzleId})
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="pass-puzzle"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');
        $client->waitForVisibility('#modal-frame form');

        // Fill in the form with new holder code (PLAYER_WITH_FAVORITES = player3)
        $client->getCrawler()
            ->filter('#modal-frame input[name*="newHolderCode"]')
            ->first()
            ->sendKeys('#player3');

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for Turbo Stream to process
        usleep(2000000);

        // Verify the card is removed from the list (borrowed puzzle passed)
        self::assertSelectorNotExists($puzzleCardSelector);

        // Verify the count decreased
        $newCount = (int) $client->getCrawler()->filter('#unsolved-count')->text();
        self::assertLessThan($initialCount, $newCount, 'Unsolved count should decrease after passing borrowed puzzle');
    }
}
