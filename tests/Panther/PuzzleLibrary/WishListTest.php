<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\PuzzleLibrary;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Panther\AbstractPantherTestCase;

final class WishListTest extends AbstractPantherTestCase
{
    public function testOwnerCanRemoveItemFromListViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE - has membership and wishlist items with auto-remove enabled
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit wish list page
        $client->request('GET', '/en/wish-list/' . PlayerFixture::PLAYER_WITH_STRIPE);

        // Wait for page to load
        $client->waitFor('body');

        // Wait for the count to be visible
        $client->waitForVisibility('#wishlist-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#wishlist-count')->text();
        self::assertEquals('2', $initialCount, 'Initial wishlist count should be 2');

        // Find the puzzle card for PUZZLE_9000 (WISHLIST_04 - has auto-remove enabled)
        $puzzleCardSelector = '#library-wishlist-' . PuzzleFixture::PUZZLE_9000;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu on the puzzle card
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        // Wait for dropdown to open
        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click the "Remove from list" button (it's a form submit button with text-danger class)
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form button.text-danger')
            ->first()
            ->click();

        // Wait for Turbo Stream to process and update the count
        $client->waitForElementToContain('#wishlist-count', '1', timeoutInSecond: 2);

        // Verify the card is removed from the list
        self::assertSelectorNotExists($puzzleCardSelector);

        // Verify the count is updated
        $newCount = $client->getCrawler()->filter('#wishlist-count')->text();
        self::assertEquals('1', $newCount, 'Wishlist count should decrease to 1 after removing');
    }

    public function testOwnerCanAddItemToCollectionViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE - has membership and wishlist items with auto-remove enabled
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit wish list page
        $client->request('GET', '/en/wish-list/' . PlayerFixture::PLAYER_WITH_STRIPE);

        // Wait for page to load
        $client->waitFor('body');

        // Wait for the count to be visible
        $client->waitForVisibility('#wishlist-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#wishlist-count')->text();
        self::assertEquals('2', $initialCount, 'Initial wishlist count should be 2');

        // Find the puzzle card for PUZZLE_9000 (WISHLIST_04 - has auto-remove enabled)
        $puzzleCardSelector = '#library-wishlist-' . PuzzleFixture::PUZZLE_9000;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu on the puzzle card
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        // Wait for dropdown to open
        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Add to collection" link to open modal
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/collections/"][href*="/add"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');

        // The form has a tom-select input for collection selection
        // PLAYER_WITH_STRIPE has COLLECTION_PUBLIC available
        // We need to interact with tom-select to select the collection
        // tom-select creates a div.ts-control that we click to open dropdown
        $client->waitForVisibility('#modal-frame .ts-control');

        // Click on the tom-select control to open dropdown
        $client->getCrawler()
            ->filter('#modal-frame .ts-control')
            ->first()
            ->click();

        // Wait for dropdown options to appear
        $client->waitForVisibility('.ts-dropdown .option');

        // Click on the first available option (My Ravensburger Collection)
        $client->getCrawler()
            ->filter('.ts-dropdown .option')
            ->first()
            ->click();

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for Turbo Stream to process and update the count
        // Since PUZZLE_9000 has removeOnCollectionAdd: true, it should be auto-removed
        $client->waitForElementToContain('#wishlist-count', '1', timeoutInSecond: 2);

        // Verify the card is removed from the list
        self::assertSelectorNotExists($puzzleCardSelector);

        // Verify the count is updated
        $newCount = $client->getCrawler()->filter('#wishlist-count')->text();
        self::assertEquals('1', $newCount, 'Wishlist count should decrease to 1 after adding to collection');
    }

    public function testOwnerCanBorrowPuzzleViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE - has membership and wishlist items with auto-remove enabled
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit wish list page
        $client->request('GET', '/en/wish-list/' . PlayerFixture::PLAYER_WITH_STRIPE);

        // Wait for page to load
        $client->waitFor('body');

        // Wait for the count to be visible
        $client->waitForVisibility('#wishlist-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#wishlist-count')->text();
        self::assertEquals('2', $initialCount, 'Initial wishlist count should be 2');

        // Find the puzzle card for PUZZLE_9000 (WISHLIST_04 - has auto-remove enabled)
        $puzzleCardSelector = '#library-wishlist-' . PuzzleFixture::PUZZLE_9000;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu on the puzzle card
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        // Wait for dropdown to open
        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Borrow from" link to open modal
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/borrow"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');

        // Fill in the form with owner player code (PLAYER_REGULAR - John Doe, code: player1)
        $client->getCrawler()
            ->filter('#modal-frame input[name*="ownerCode"]')
            ->first()
            ->sendKeys('#player1');

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for Turbo Stream to process and update the count
        // Since PUZZLE_9000 has removeOnCollectionAdd: true, it should be auto-removed when borrowed
        $client->waitForElementToContain('#wishlist-count', '1', timeoutInSecond: 2);

        // Verify the card is removed from the list
        self::assertSelectorNotExists($puzzleCardSelector);

        // Verify the count is updated
        $newCount = $client->getCrawler()->filter('#wishlist-count')->text();
        self::assertEquals('1', $newCount, 'Wishlist count should decrease to 1 after borrowing');
    }
}
