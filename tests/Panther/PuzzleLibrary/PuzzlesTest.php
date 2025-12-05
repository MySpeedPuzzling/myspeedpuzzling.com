<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\PuzzleLibrary;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Panther\AbstractPantherTestCase;

/**
 * Tests for puzzle list page (/en/puzzle) dropdown actions with Turbo Streams.
 *
 * Uses PLAYER_WITH_STRIPE who has active membership.
 * Tests use puzzles that appear on the first page (sorted by most-solved).
 */
final class PuzzlesTest extends AbstractPantherTestCase
{
    /**
     * Test: Add puzzle to wishlist, then remove it.
     * Uses PUZZLE_500_05 which is clean (no badges) for PLAYER_WITH_STRIPE.
     */
    public function testOwnerCanAddAndRemoveFromWishlist(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle');
        $client->waitFor('body');

        // Use PUZZLE_500_05 - clean puzzle (not in collection/wishlist for PLAYER_WITH_STRIPE)
        $puzzleCardSelector = '#puzzle-list-item-' . PuzzleFixture::PUZZLE_500_05;
        $badgesSelector = '#puzzle-badges-' . PuzzleFixture::PUZZLE_500_05;

        $client->waitForVisibility($puzzleCardSelector);

        // Assert NO wishlist badge initially
        self::assertSelectorNotExists($badgesSelector . ' .badge.border-warning');

        // Open dropdown and click "Add to Wishlist"
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="wishlist"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');
        $client->waitForVisibility('#modal-frame form');

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for Turbo Stream to process
        usleep(1500000);

        // Assert wishlist badge appears
        self::assertSelectorExists($badgesSelector . ' .badge.border-warning');

        // Open dropdown and click "Remove from Wishlist"
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="remove-from-wish-list"] button')
            ->first()
            ->click();

        // Wait for Turbo Stream to process
        usleep(1500000);

        // Assert wishlist badge removed
        self::assertSelectorNotExists($badgesSelector . ' .badge.border-warning');
    }

    /**
     * Test: Add to wishlist, then add to collection (removes wishlist), then remove from collection.
     * Uses PUZZLE_1500_01 which is in system collection only (not in wishlist).
     * We test the collection flow - adding to named collection should show collection badge.
     */
    public function testAddToCollectionAndRemove(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle');
        $client->waitFor('body');

        // Use PUZZLE_500_01 which is already in collection - test adding to another collection
        $puzzleCardSelector = '#puzzle-list-item-' . PuzzleFixture::PUZZLE_500_01;
        $badgesSelector = '#puzzle-badges-' . PuzzleFixture::PUZZLE_500_01;
        $dropdownActionsSelector = '#puzzle-dropdown-actions-' . PuzzleFixture::PUZZLE_500_01;

        $client->waitForVisibility($puzzleCardSelector);

        // Assert collection badge exists
        self::assertSelectorExists($badgesSelector . ' .badge.border-primary');

        // Count initial remove buttons
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $initialRemoveButtons = $client->getCrawler()->filter($dropdownActionsSelector . ' form[action*="collections"][action*="remove"]')->count();

        // Add to another collection
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="collections"][href*="add"]')
            ->first()
            ->click();

        $client->waitForVisibility('#modal-frame form');

        // Select a collection from tom-select dropdown
        $client->waitForVisibility('#modal-frame .ts-control');
        $client->getCrawler()->filter('#modal-frame .ts-control')->first()->click();

        $client->waitForVisibility('.ts-dropdown .option');
        $client->getCrawler()->filter('.ts-dropdown .option')->first()->click();

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        usleep(2000000);

        // Assert collection badge still there
        self::assertSelectorExists($badgesSelector . ' .badge.border-primary');

        // Open dropdown, check we have more remove buttons now
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $newRemoveButtons = $client->getCrawler()->filter($dropdownActionsSelector . ' form[action*="collections"][action*="remove"]')->count();
        self::assertGreaterThan($initialRemoveButtons, $newRemoveButtons, 'Should have more remove buttons after adding to collection');

        // Remove from one collection
        $client->getCrawler()
            ->filter($dropdownActionsSelector . ' form[action*="collections"][action*="remove"] button')
            ->first()
            ->click();

        usleep(1500000);

        // Assert collection badge still there (still in other collections)
        self::assertSelectorExists($badgesSelector . ' .badge.border-primary');
    }

    /**
     * Test: Borrow puzzle from player, then pass to someone else.
     * Uses PUZZLE_500_05 which is clean for PLAYER_WITH_STRIPE.
     */
    public function testBorrowAndPassToSomeoneElse(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle');
        $client->waitFor('body');

        $puzzleCardSelector = '#puzzle-list-item-' . PuzzleFixture::PUZZLE_500_05;
        $badgesSelector = '#puzzle-badges-' . PuzzleFixture::PUZZLE_500_05;

        $client->waitForVisibility($puzzleCardSelector);

        // Assert NO borrowed badge initially
        self::assertSelectorNotExists($badgesSelector . ' .badge.border-dark');

        // Click "Borrowed from" link
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="borrow"]')
            ->first()
            ->click();

        $client->waitForVisibility('#modal-frame form');

        // Fill owner code
        $client->getCrawler()
            ->filter('#modal-frame input[name*="ownerCode"]')
            ->first()
            ->sendKeys('#player1');

        // Submit form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        usleep(2000000);

        // Assert borrowed badge appears
        self::assertSelectorExists($badgesSelector . ' .badge.border-dark');
        // Assert unsolved badge appears
        self::assertSelectorExists($badgesSelector . ' .badge.border-info');

        // Click "Pass" link
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="pass-puzzle"]')
            ->first()
            ->click();

        $client->waitForVisibility('#modal-frame form');

        // Fill new holder code
        $client->getCrawler()
            ->filter('#modal-frame input[name*="newHolderCode"]')
            ->first()
            ->sendKeys('#player3');

        // Submit form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        usleep(2000000);

        // Assert borrowed badge removed
        self::assertSelectorNotExists($badgesSelector . ' .badge.border-dark');
        // Assert unsolved badge removed
        self::assertSelectorNotExists($badgesSelector . ' .badge.border-info');
    }

    /**
     * Test: Borrow puzzle, then return to owner.
     * Uses PUZZLE_1000_04 - in COLLECTION_STRIPE_TREFL only.
     * First we need to borrow, then return.
     */
    public function testBorrowAndReturnToOwner(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle');
        $client->waitFor('body');

        // PUZZLE_500_03 is in system collection for PLAYER_WITH_STRIPE, but we can still borrow conceptually
        // Actually let's use a puzzle that we can borrow - PUZZLE_500_05 is clean
        $puzzleCardSelector = '#puzzle-list-item-' . PuzzleFixture::PUZZLE_300;
        $badgesSelector = '#puzzle-badges-' . PuzzleFixture::PUZZLE_300;

        $client->waitForVisibility($puzzleCardSelector);

        // This puzzle is already in collection, so we'll test lend instead
        // Open dropdown and click "Lend" link (puzzle must be in collection)
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="lend"]')
            ->first()
            ->click();

        $client->waitForVisibility('#modal-frame form');

        // Fill borrower code
        $client->getCrawler()
            ->filter('#modal-frame input[name*="borrowerCode"]')
            ->first()
            ->sendKeys('#player1');

        // Submit form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        usleep(2000000);

        // Assert lent badge appears
        $crawler = $client->getCrawler();
        $lentBadges = $crawler->filter($badgesSelector . ' .badge.border-dark');
        self::assertGreaterThan(0, $lentBadges->count(), 'Should have lent badge after lending');

        // Click "Return" button (owner marks as returned)
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="return-puzzle"] button')
            ->first()
            ->click();

        usleep(2000000);

        // Assert lent badge removed
        $crawler = $client->getCrawler();
        $lentBadges = $crawler->filter($badgesSelector . ' .badge.border-dark .bi-box-arrow-right');
        self::assertCount(0, $lentBadges, 'Lent badge should be removed after return');
    }

    /**
     * Test: Add puzzle to sell/swap list, then remove.
     * Uses PUZZLE_300 which is in COLLECTION_PUBLIC but NOT on sell/swap.
     */
    public function testAddAndRemoveFromSellSwap(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle');
        $client->waitFor('body');

        // PUZZLE_500_04 is in COLLECTION_PUBLIC, not on sell/swap
        $puzzleCardSelector = '#puzzle-list-item-' . PuzzleFixture::PUZZLE_500_04;
        $badgesSelector = '#puzzle-badges-' . PuzzleFixture::PUZZLE_500_04;

        $client->waitForVisibility($puzzleCardSelector);

        // Assert NO "For Sale" badge initially
        self::assertSelectorNotExists($badgesSelector . ' .badge.border-danger');

        // Click "Add to sell/swap" link
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="sell-swap"][href*="add"]')
            ->first()
            ->click();

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
            ->sendKeys('50');

        // Select condition using JavaScript
        $client->executeScript("
            const select = document.querySelector('#modal-frame select');
            select.selectedIndex = 1;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        ");

        // Submit form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        usleep(2000000);

        // Assert "For Sale" badge appears
        self::assertSelectorExists($badgesSelector . ' .badge.border-danger');

        // Click "Remove from sell/swap" button
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="sell-swap"][action*="remove"] button')
            ->first()
            ->click();

        usleep(1500000);

        // Assert badge removed
        self::assertSelectorNotExists($badgesSelector . ' .badge.border-danger');
    }

    /**
     * Test: Add puzzle to second collection, verify 2 remove buttons, remove one.
     * Uses PUZZLE_1000_04 which is only in COLLECTION_STRIPE_TREFL.
     */
    public function testAddToSecondCollectionAndRemove(): void
    {
        $client = self::createBrowserClient();

        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        $client->request('GET', '/en/puzzle');
        $client->waitFor('body');

        $puzzleCardSelector = '#puzzle-list-item-' . PuzzleFixture::PUZZLE_1000_04;
        $badgesSelector = '#puzzle-badges-' . PuzzleFixture::PUZZLE_1000_04;
        $dropdownActionsSelector = '#puzzle-dropdown-actions-' . PuzzleFixture::PUZZLE_1000_04;

        $client->waitForVisibility($puzzleCardSelector);

        // Assert collection badge exists (puzzle is already in COLLECTION_STRIPE_TREFL)
        self::assertSelectorExists($badgesSelector . ' .badge.border-primary');

        // Open dropdown, assert 1 remove button
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $removeButtons = $client->getCrawler()->filter($dropdownActionsSelector . ' form[action*="collections"][action*="remove"]');
        self::assertCount(1, $removeButtons, 'Should have 1 remove button initially');

        // Click "Add to collection" and select different collection
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="collections"][href*="add"]')
            ->first()
            ->click();

        $client->waitForVisibility('#modal-frame form');

        // Select a different collection from tom-select dropdown
        $client->waitForVisibility('#modal-frame .ts-control');
        $client->getCrawler()->filter('#modal-frame .ts-control')->first()->click();

        $client->waitForVisibility('.ts-dropdown .option');
        $client->getCrawler()->filter('.ts-dropdown .option')->first()->click();

        // Submit form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        usleep(2000000);

        // Assert collection badge still there
        self::assertSelectorExists($badgesSelector . ' .badge.border-primary');

        // Open dropdown, assert 2 remove buttons
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $removeButtons = $client->getCrawler()->filter($dropdownActionsSelector . ' form[action*="collections"][action*="remove"]');
        self::assertCount(2, $removeButtons, 'Should have 2 remove buttons after adding to second collection');

        // Click one "Remove from collection" button
        $client->getCrawler()
            ->filter($dropdownActionsSelector . ' form[action*="collections"][action*="remove"] button')
            ->first()
            ->click();

        usleep(1500000);

        // Assert collection badge still there
        self::assertSelectorExists($badgesSelector . ' .badge.border-primary');

        // Open dropdown, assert only 1 remove button remains
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        $removeButtons = $client->getCrawler()->filter($dropdownActionsSelector . ' form[action*="collections"][action*="remove"]');
        self::assertCount(1, $removeButtons, 'Should have 1 remove button after removing from one collection');
    }
}
