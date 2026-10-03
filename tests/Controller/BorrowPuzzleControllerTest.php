<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class BorrowPuzzleControllerTest extends WebTestCase
{
    /**
     * "Borrow from player" on a collection page replaces the puzzle's card. The stream used to be rendered without
     * the card - a 500 after the borrow was already saved (found 2026-10-03 through the Panther suite).
     */
    public function testBorrowingFromTheCollectionPageReplacesTheCard(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $puzzleId = PuzzleFixture::PUZZLE_500_02;

        // The modal, opened from the system collection's card menu
        $modal = $browser->request('GET', '/en/borrow/' . $puzzleId . '?context=collection-detail&collection_id=__system_collection__', server: [
            'HTTP_TURBO_FRAME' => 'modal-frame',
        ]);
        $this->assertResponseIsSuccessful();
        self::assertSame('__system_collection__', $modal->filter('input[name="collection_id"]')->attr('value'));

        $form = $modal->filter('#modal-frame form')->form();
        $form['borrow_puzzle_form[ownerCode]'] = '#player1';

        $browser->submit($form, [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);

        $this->assertResponseIsSuccessful();
        $stream = new Crawler((string) $browser->getResponse()->getContent());
        $card = $stream->filter('turbo-stream[action="replace"][target="library-collection-' . $puzzleId . '"] template');
        self::assertCount(1, $card);
        self::assertStringContainsString('Puzzle was marked as borrowed', (string) $browser->getResponse()->getContent());
    }

    public function testACollectionPageStreamWithoutTheCardStillAnswers(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Not in the player's collection: there is no card to replace, the toast still confirms the borrow
        $modal = $browser->request('GET', '/en/borrow/' . PuzzleFixture::PUZZLE_9000 . '?context=collection-detail&collection_id=__system_collection__', server: [
            'HTTP_TURBO_FRAME' => 'modal-frame',
        ]);
        $form = $modal->filter('#modal-frame form')->form();
        $form['borrow_puzzle_form[ownerCode]'] = '#player1';

        $browser->submit($form, [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);

        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('target="library-collection-', (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('Puzzle was marked as borrowed', (string) $browser->getResponse()->getContent());
    }
}
