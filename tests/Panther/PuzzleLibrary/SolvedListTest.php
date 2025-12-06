<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\PuzzleLibrary;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Panther\AbstractPantherTestCase;

final class SolvedListTest extends AbstractPantherTestCase
{
    /**
     * Tests that removing a puzzle from a collection does NOT remove it from the solved list.
     * This is the key difference from unsolved list - solved puzzles stay regardless of collection status.
     */
    public function testOwnerCanRemoveItemFromCollectionViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE - has solved puzzles
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit solved puzzles page
        $client->request('GET', '/en/solved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#solved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#solved-count')->text();

        // Find the puzzle card for PUZZLE_500_01 (in COLLECTION_PUBLIC)
        $puzzleCardSelector = '#library-solved-' . PuzzleFixture::PUZZLE_500_01;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Remove from collection" button
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="/collections/"] button')
            ->first()
            ->click();

        // Wait for Turbo Stream to process
        usleep(1500000);

        // KEY DIFFERENCE: The card should STILL exist (solved puzzles stay in the list)
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#solved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Solved count should stay the same after removing from collection');
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

        // Visit solved puzzles page
        $client->request('GET', '/en/solved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#solved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#solved-count')->text();

        // Find the puzzle card for PUZZLE_1000_01 (in COLLECTION_PUBLIC)
        $puzzleCardSelector = '#library-solved-' . PuzzleFixture::PUZZLE_1000_01;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Add to collection" link to open modal
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
        // Select the last collection option
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
        $newCount = $client->getCrawler()->filter('#solved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Solved count should stay the same when adding to collection');
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

        // Visit solved puzzles page
        $client->request('GET', '/en/solved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#solved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#solved-count')->text();

        // Find a puzzle card that is NOT on sell/swap yet
        // PUZZLE_2000 is lent to PLAYER_REGULAR but not on sell/swap
        $puzzleCardSelector = '#library-solved-' . PuzzleFixture::PUZZLE_2000;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Add to sell/swap" link to open modal
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/sell-swap/"]')
            ->first()
            ->click();

        // Wait for modal to open and form to load
        $client->waitForVisibility('#modal-frame');
        $client->waitForVisibility('#modal-frame form');

        // Fill in the form - select "Sell" option
        $client->getCrawler()
            ->filter('#modal-frame input[type="radio"]')
            ->eq(1)  // Second radio button is "Sell"
            ->click();

        // Fill in price
        $client->getCrawler()
            ->filter('#modal-frame input[name*="price"]')
            ->first()
            ->sendKeys('50');

        // Select condition
        $client->executeScript("
            const select = document.querySelector('#modal-frame select');
            select.selectedIndex = 1;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        ");

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
        $newCount = $client->getCrawler()->filter('#solved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Solved count should stay the same when adding to sell/swap');
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

        // Visit solved puzzles page
        $client->request('GET', '/en/solved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#solved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#solved-count')->text();

        // Find a puzzle card that IS on sell/swap
        // PUZZLE_500_01 is on sell/swap as SELLSWAP_01
        $puzzleCardSelector = '#library-solved-' . PuzzleFixture::PUZZLE_500_01;

        $client->waitForVisibility($puzzleCardSelector);

        // Scroll to the card
        $client->executeScript("document.querySelector('" . $puzzleCardSelector . "').scrollIntoView({block: 'center'});");
        usleep(300000);

        // Open the dropdown menu using JavaScript
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

        // Wait for Turbo Stream to process
        usleep(1500000);

        // The card should still exist (puzzle still solved)
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#solved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Solved count should stay the same when removing from sell/swap');
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

        // Visit solved puzzles page
        $client->request('GET', '/en/solved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#solved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#solved-count')->text();

        // Find a puzzle card that is on sell/swap
        // PUZZLE_1000_01 is on sell/swap as SELLSWAP_03
        $puzzleCardSelector = '#library-solved-' . PuzzleFixture::PUZZLE_1000_01;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Mark as sold" link to open modal
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

        // KEY: The card should STILL exist (puzzle still solved, even though removed from collection)
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#solved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Solved count should stay the same after marking as sold');
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

        // Visit solved puzzles page
        $client->request('GET', '/en/solved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#solved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#solved-count')->text();

        // Find a puzzle card that is in collection but not lent
        // PUZZLE_1000_02 is in collection, not lent (PUZZLE_500_03 is already lent via LENT_04)
        $puzzleCardSelector = '#library-solved-' . PuzzleFixture::PUZZLE_1000_02;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Lend to player" link to open modal
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

        // The card should still exist with lent status
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#solved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Solved count should stay the same when lending');
    }

    public function testOwnerCanBorrowPuzzleViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit solved puzzles page
        $client->request('GET', '/en/solved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#solved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#solved-count')->text();

        // Find a puzzle card that can be borrowed (in collection, not already borrowed)
        // PUZZLE_1000_02 is in collection, not borrowed
        $puzzleCardSelector = '#library-solved-' . PuzzleFixture::PUZZLE_1000_02;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Borrow from player" link to open modal
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
        $newCount = $client->getCrawler()->filter('#solved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Solved count should stay the same when borrowing');
    }

    /**
     * Tests that returning a borrowed puzzle keeps it in the solved list.
     * PLAYER_WITH_STRIPE has borrowed PUZZLE_1500_02 from PLAYER_REGULAR and also solved it.
     */
    public function testBorrowerCanReturnPuzzleToOwnerViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE (has borrowed PUZZLE_1500_02 from PLAYER_REGULAR and solved it)
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit solved puzzles page
        $client->request('GET', '/en/solved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#solved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#solved-count')->text();

        // Find the puzzle card for PUZZLE_1500_02 (borrowed from PLAYER_REGULAR)
        $puzzleCardSelector = '#library-solved-' . PuzzleFixture::PUZZLE_1500_02;
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

        // KEY: The card should STILL exist (puzzle was solved, so it stays in solved list)
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#solved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Solved count should stay the same after returning borrowed puzzle');
    }

    /**
     * Tests that passing a borrowed puzzle to someone else keeps it in the solved list.
     * PLAYER_WITH_STRIPE has borrowed PUZZLE_1500_02 from PLAYER_REGULAR and also solved it.
     */
    public function testBorrowerCanPassPuzzleToSomeoneElseViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE (has borrowed PUZZLE_1500_02 from PLAYER_REGULAR and solved it)
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit solved puzzles page
        $client->request('GET', '/en/solved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#solved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#solved-count')->text();

        // Find the puzzle card for PUZZLE_1500_02 (borrowed from PLAYER_REGULAR)
        $puzzleCardSelector = '#library-solved-' . PuzzleFixture::PUZZLE_1500_02;
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

        // KEY: The card should STILL exist (puzzle was solved, so it stays in solved list)
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#solved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Solved count should stay the same after passing borrowed puzzle');
    }

    /**
     * Tests that removing a puzzle from wishlist updates the card via Turbo Stream.
     * PLAYER_WITH_STRIPE has PUZZLE_500_01 both solved and on wishlist (WISHLIST_07).
     */
    public function testOwnerCanRemoveItemFromWishlistViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit solved puzzles page
        $client->request('GET', '/en/solved-puzzles/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $client->waitFor('body');
        $client->waitForVisibility('#solved-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#solved-count')->text();

        // Find a puzzle card that is on wishlist
        // PUZZLE_500_01 is on wishlist as WISHLIST_01
        $puzzleCardSelector = '#library-solved-' . PuzzleFixture::PUZZLE_500_01;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Remove from wishlist" button
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="wish-list"] button')
            ->first()
            ->click();

        // Wait for Turbo Stream to process
        usleep(1500000);

        // The card should still exist
        self::assertSelectorExists($puzzleCardSelector);

        // The count should remain the same
        $newCount = $client->getCrawler()->filter('#solved-count')->text();
        self::assertEquals($initialCount, $newCount, 'Solved count should stay the same when removing from wishlist');
    }
}
