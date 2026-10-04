<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The puzzle lists filtered in the browser (collection_filter_controller.js) render each puzzle's stored search keys -
 * every name and every code, folded by the server - as one `data-search` attribute; the browser folds only what is
 * typed (assets/search_fold.js, pinned by SearchFoldParityTest).
 */
final class PuzzleListSearchKeysTest extends WebTestCase
{
    public function testSellSwapListItemsCarryEveryNameAndCode(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/sell-swap-list/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $this->assertResponseIsSuccessful();
        self::assertSame(
            "\npuzzle 7\nkouzelna zahrada\nzauberhafter garten\n",
            $crawler->filter('#library-sell-swap-' . PuzzleFixture::PUZZLE_1000_02)->attr('data-search'),
        );
        self::assertSame(
            "\npuzzle 1\n\nc:rb500001\n",
            $crawler->filter('#library-sell-swap-' . PuzzleFixture::PUZZLE_500_01)->attr('data-search'),
        );
        self::assertCount(0, $crawler->filter('[data-puzzle-name], [data-ean], [data-puzzle-code], [data-puzzle-alternative-name]'));
    }

    public function testLibraryListItemsCarryEveryNameAndCode(): void
    {
        $browser = self::createClient();
        // Solved puzzles are private by default - the owner sees them
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/solved-puzzles/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
        self::assertSame(
            "\npuzzle 7\nkouzelna zahrada\nzauberhafter garten\n",
            $crawler->filter('#library-solved-' . PuzzleFixture::PUZZLE_1000_02)->attr('data-search'),
        );
        self::assertCount(0, $crawler->filter('[data-puzzle-name], [data-ean], [data-puzzle-code], [data-puzzle-alternative-name]'));
    }
}
