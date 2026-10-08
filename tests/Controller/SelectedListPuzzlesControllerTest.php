<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Selecting several puzzles on the wishlist, sell/swap, unsolved and lend/borrow pages
 * (docs/features/collections/bulk-actions.md "Other lists"): members on their own pages only.
 */
final class SelectedListPuzzlesControllerTest extends WebTestCase
{
    private const string CHECKBOX = '[data-collection-selection-target="checkbox"]';
    private const array FRAME = ['HTTP_TURBO_FRAME' => 'modal-frame', 'HTTP_ORIGIN' => 'http://localhost'];

    public function testAMemberGetsCheckboxesAndTheBarOnEveryOwnList(): void
    {
        $browser = $this->member();

        foreach (['/en/wish-list/', '/en/sell-swap-list/', '/en/unsolved-puzzles/', '/en/lend-borrow-list/'] as $page) {
            $crawler = $browser->request('GET', $page . PlayerFixture::PLAYER_WITH_STRIPE);
            self::assertResponseIsSuccessful($page);
            self::assertGreaterThan(0, $crawler->filter(self::CHECKBOX)->count(), $page);
            self::assertSelectorExists('form.collection-selection-bar');
        }
    }

    public function testNobodyElseGetsCheckboxes(): void
    {
        $browser = self::createClient();

        // Somebody else's list
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', '/en/sell-swap-list/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::CHECKBOX);

        // A player without a membership on their own list
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/wish-list/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::CHECKBOX);
    }

    public function testWishlistRemoveAsksFirstThenTakesTheCardsOff(): void
    {
        $browser = $this->member();
        $selection = ['_token' => 'csrf-token', 'puzzleIds' => [PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_3000]];

        $browser->request('POST', '/en/my-lists/wishlist/selected/remove', $selection, server: self::FRAME);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal-frame input[name="confirm"]');
        self::assertSelectorTextContains('.modal-title', 'Remove 2 puzzles from your wishlist?');
        self::assertSame(1, $this->rows('wish_list_item', PuzzleFixture::PUZZLE_9000));

        $browser->request('POST', '/en/my-lists/wishlist/selected/remove', $selection + ['confirm' => '1'], server: self::FRAME);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('target="library-wishlist-' . PuzzleFixture::PUZZLE_9000 . '"', $content);
        self::assertStringContainsString('target="wishlist-count"', $content);
        self::assertStringContainsString('2 puzzles removed from your wishlist.', $content);
        self::assertSame(0, $this->rows('wish_list_item', PuzzleFixture::PUZZLE_9000));
    }

    public function testWishlistAddToCollectionReloadsThePage(): void
    {
        $browser = $this->member();

        $browser->request('POST', '/en/my-lists/wishlist/selected/add-to-collection', [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_500_01],
        ], server: self::FRAME);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-title', 'Add 2 puzzles to a collection');

        $browser->request('POST', '/en/my-lists/wishlist/selected/add-to-collection', [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_500_01],
            'collection_puzzle_action_form' => [
                'collection' => CollectionFixture::COLLECTION_PUBLIC,
                'collectionVisibility' => 'private',
            ],
        ], server: self::FRAME);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('action="refresh"', (string) $browser->getResponse()->getContent());
        // 500_01 was in the collection already
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM collection_item WHERE player_id = :player AND collection_id = :collection AND puzzle_id = :puzzle',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'collection' => CollectionFixture::COLLECTION_PUBLIC, 'puzzle' => PuzzleFixture::PUZZLE_9000],
        ));
    }

    public function testSellSwapReserveActsAtOnce(): void
    {
        $browser = $this->member();

        $browser->request('POST', '/en/my-lists/sell-swap/selected/reserve', [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_1000_01],
        ], server: self::FRAME);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('action="refresh"', (string) $browser->getResponse()->getContent());
        self::assertTrue((bool) $this->connection()->fetchOne(
            'SELECT reserved FROM sell_swap_list_item WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzle' => PuzzleFixture::PUZZLE_500_01],
        ));
    }

    public function testSellSwapSoldTakesTheCardsOff(): void
    {
        $browser = $this->member();

        $browser->request('POST', '/en/my-lists/sell-swap/selected/sold', [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_1000_03],
            'confirm' => '1',
        ], server: self::FRAME);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('target="library-sell-swap-' . PuzzleFixture::PUZZLE_1000_03 . '"', $content);
        self::assertStringContainsString('1 listing marked as sold/swapped.', $content);
        self::assertSame(0, $this->rows('sell_swap_list_item', PuzzleFixture::PUZZLE_1000_03));
    }

    public function testUnsolvedLendLeavesBorrowedPuzzlesOut(): void
    {
        $browser = $this->member();
        // PUZZLE_1500_02 is borrowed from PLAYER_REGULAR (LENT_05)
        $selection = ['_token' => 'csrf-token', 'puzzleIds' => [PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_1500_02]];

        $browser->request('POST', '/en/my-lists/unsolved/selected/lend', $selection, server: self::FRAME);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-title', 'Lend 1 puzzle');

        $browser->request('POST', '/en/my-lists/unsolved/selected/lend', $selection + [
            'lend_puzzle_form' => ['borrowerCode' => '#player1', '_token' => 'csrf-token'],
        ], server: self::FRAME);
        self::assertResponseIsSuccessful();

        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM lent_puzzle WHERE puzzle_id = :puzzle AND owner_player_id = :owner',
            ['puzzle' => PuzzleFixture::PUZZLE_500_04, 'owner' => PlayerFixture::PLAYER_WITH_STRIPE],
        ));
        self::assertSame(0, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM lent_puzzle WHERE puzzle_id = :puzzle AND owner_player_id = :owner',
            ['puzzle' => PuzzleFixture::PUZZLE_1500_02, 'owner' => PlayerFixture::PLAYER_WITH_STRIPE],
        ));
    }

    public function testReturnClosesLendsOfBothTabs(): void
    {
        $browser = $this->member();

        // PUZZLE_2000 lent out (LENT_01), PUZZLE_3000 borrowed (LENT_06), PUZZLE_9000 neither
        $browser->request('POST', '/en/my-lists/lend-borrow/selected/return', [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_2000, PuzzleFixture::PUZZLE_3000, PuzzleFixture::PUZZLE_9000],
            'confirm' => '1',
        ], server: self::FRAME);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('action="refresh"', (string) $browser->getResponse()->getContent());
        self::assertSame(0, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM lent_puzzle WHERE puzzle_id IN (:a, :b)',
            ['a' => PuzzleFixture::PUZZLE_2000, 'b' => PuzzleFixture::PUZZLE_3000],
        ));
    }

    public function testRefusals(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('POST', '/en/my-lists/wishlist/selected/remove', [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_4000],
            'confirm' => '1',
        ], server: self::FRAME);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM wish_list_item WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => PlayerFixture::PLAYER_REGULAR, 'puzzle' => PuzzleFixture::PUZZLE_4000],
        ));

        // An action the list does not have
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('POST', '/en/my-lists/wishlist/selected/sold', [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_9000],
            'confirm' => '1',
        ], server: self::FRAME);
        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutTurboItGoesBackToTheListWithAFlash(): void
    {
        $browser = $this->member();

        $browser->request('POST', '/en/my-lists/wishlist/selected/remove', [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_9000],
            'confirm' => '1',
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/en/wish-list/' . PlayerFixture::PLAYER_WITH_STRIPE);
    }

    private function member(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        return $browser;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }

    private function rows(string $table, string $puzzleId): int
    {
        return (int) $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE player_id = :player AND puzzle_id = :puzzle",
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzle' => $puzzleId],
        );
    }
}
