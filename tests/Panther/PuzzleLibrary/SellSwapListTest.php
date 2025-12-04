<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\PuzzleLibrary;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Panther\AbstractPantherTestCase;

final class SellSwapListTest extends AbstractPantherTestCase
{
    public function testOwnerCanRemoveItemFromListViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE - the only player with sell/swap items
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit sell-swap list page
        $client->request('GET', '/en/sell-swap-list/' . PlayerFixture::PLAYER_WITH_STRIPE);

        // Wait for page to load
        $client->waitFor('body');

        // Wait for the count to be visible
        $client->waitForVisibility('#sellswap-count');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#sellswap-count')->text();
        self::assertEquals('7', $initialCount, 'Initial sell/swap count should be 7');

        // Find the puzzle card for PUZZLE_500_01 (SELLSWAP_01 - Sell only)
        $puzzleCardSelector = '#library-sell-swap-' . PuzzleFixture::PUZZLE_500_01;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu on the puzzle card
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        // Wait for dropdown to open
        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click the "Remove from list" button (it's a form submit button)
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu form[action*="/remove"] button')
            ->first()
            ->click();

        // Wait for Turbo Stream to process and update the count
        $client->waitForElementToContain('#sellswap-count', '6', timeoutInSecond: 2);

        // Verify the card is removed from the list
        self::assertSelectorNotExists($puzzleCardSelector);

        // Verify the count is updated
        $newCount = $client->getCrawler()->filter('#sellswap-count')->text();
        self::assertEquals('6', $newCount, 'Sell/swap count should decrease to 6 after removing');
    }

    public function testOwnerCanMarkItemAsSoldViaTurboStream(): void
    {
        $client = self::createBrowserClient();

        // Login as PLAYER_WITH_STRIPE - the only player with sell/swap items
        self::loginUser(
            $client,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            email: PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        );

        // Visit sell-swap list page
        $client->request('GET', '/en/sell-swap-list/' . PlayerFixture::PLAYER_WITH_STRIPE);

        // Wait for page to load
        $client->waitFor('body');

        // Wait for the count to be visible
        $client->waitForVisibility('#sellswap-count');

        // Screenshot at the start
        $client->takeScreenshot(__DIR__ . '/../../../var/sellswap_01_start.png');

        // Get initial count
        $initialCount = $client->getCrawler()->filter('#sellswap-count')->text();
        self::assertEquals('7', $initialCount, 'Initial sell/swap count should be 7');

        // Find the puzzle card for PUZZLE_500_02 (SELLSWAP_02 - Swap only)
        $puzzleCardSelector = '#library-sell-swap-' . PuzzleFixture::PUZZLE_500_02;
        $client->waitForVisibility($puzzleCardSelector);

        // Open the dropdown menu on the puzzle card
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-toggle')
            ->first()
            ->click();

        // Wait for dropdown to open
        $client->waitForVisibility($puzzleCardSelector . ' .dropdown-menu');

        // Click "Mark as sold" link to open modal
        $client->getCrawler()
            ->filter($puzzleCardSelector . ' .dropdown-menu a[href*="/mark-sold"]')
            ->first()
            ->click();

        // Wait for modal to open
        $client->waitForVisibility('#modal-frame');

        // Screenshot with modal open
        $client->takeScreenshot(__DIR__ . '/../../../var/sellswap_02_modal.png');

        // Fill in the form with existing player code (PLAYER_REGULAR - John Doe)
        $client->getCrawler()
            ->filter('#modal-frame input[name*="buyerInput"]')
            ->first()
            ->sendKeys('#player1');

        // Submit the form
        $client->getCrawler()
            ->filter('#modal-frame button[type="submit"]')
            ->first()
            ->click();

        // Wait for Turbo Stream to process and update the count
        $client->waitForElementToContain('#sellswap-count', '6', timeoutInSecond: 2);

        // Verify the card is removed from the list
        self::assertSelectorNotExists($puzzleCardSelector);
    }
}
