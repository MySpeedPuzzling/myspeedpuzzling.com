<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Difficulty icon, solve count and the members-only difficulty chips on the puzzle-list
 * pages (ResolvePuzzleListInsights). The viewer's membership decides, not the owner's.
 */
final class PuzzleListInsightsTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string STRIPE = PlayerFixture::PLAYER_WITH_STRIPE;

    /**
     * @return iterable<string, array{string, bool}> url, has filter controls (more than 3 items)
     */
    public static function memberOwnPages(): iterable
    {
        yield 'system collection' => ['/en/puzzle-collection/' . self::STRIPE, true];
        yield 'custom collection' => ['/en/collection/' . CollectionFixture::COLLECTION_PUBLIC, true];
        yield 'wishlist' => ['/en/wish-list/' . self::STRIPE, false];
        yield 'solved' => ['/en/solved-puzzles/' . self::STRIPE, true];
        yield 'sell-swap' => ['/en/sell-swap-list/' . self::STRIPE, true];
        yield 'lend-borrow' => ['/en/lend-borrow-list/' . self::STRIPE, false];
    }

    #[DataProvider('memberOwnPages')]
    public function testMemberSeesTiersAndChips(string $url, bool $hasFilters): void
    {
        $browser = self::createClient();
        self::getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        TestingLogin::asPlayer($browser, self::STRIPE);

        $crawler = $browser->request('GET', $url);
        $this->assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('.puzzle-solved-times')->count());
        self::assertGreaterThan(0, $this->icons($crawler)->count());
        self::assertCount(0, $this->icons($crawler, 'diff-locked'));

        if (str_contains($url, 'lend-borrow') === false) {
            self::assertGreaterThan(0, $crawler->filter('[data-difficulty-tier]')->count());
        }

        self::assertCount($hasFilters ? 6 : 0, $crawler->filter('input[data-collection-filter-target="difficultyTier"]'));
    }

    public function testWishlistAndUnsolvedPagesGetTiersAndChips(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        TestingLogin::asPlayer($browser, self::STRIPE);

        // Enough items for the filter controls: puzzles the member has not solved, on her wishlist and in her collection
        $connection = self::getContainer()->get(Connection::class);
        /** @var list<string> $puzzleIds */
        $puzzleIds = $connection->fetchFirstColumn(
            'SELECT id FROM puzzle WHERE id NOT IN (SELECT puzzle_id FROM puzzle_solving_time) AND id NOT IN (SELECT puzzle_id FROM wish_list_item WHERE player_id = :p) AND id NOT IN (SELECT puzzle_id FROM collection_item WHERE player_id = :p) LIMIT 4',
            ['p' => self::STRIPE],
        );
        self::assertCount(4, $puzzleIds);

        foreach ($puzzleIds as $puzzleId) {
            $connection->insert('wish_list_item', ['id' => Uuid::uuid7()->toString(), 'player_id' => self::STRIPE, 'puzzle_id' => $puzzleId, 'added_at' => '2026-01-01 10:00:00']);
            $connection->insert('collection_item', ['id' => Uuid::uuid7()->toString(), 'player_id' => self::STRIPE, 'puzzle_id' => $puzzleId, 'added_at' => '2026-01-01 10:00:00']);
        }

        foreach (['/en/wish-list/' . self::STRIPE, '/en/unsolved-puzzles/' . self::STRIPE] as $url) {
            $crawler = $browser->request('GET', $url);
            $this->assertResponseIsSuccessful();

            foreach ($puzzleIds as $puzzleId) {
                self::assertCount(1, $crawler->filter(sprintf('[data-puzzle-id="%s"][data-difficulty-tier="0"]', $puzzleId)), "$url: never solved = no tier yet");
            }
            self::assertCount(6, $crawler->filter('input[data-collection-filter-target="difficultyTier"]'), $url);
            self::assertCount(0, $this->icons($crawler, 'diff-locked'), $url);
        }
    }

    public function testSellSwapStreamsRenderTheItemWithoutInsights(): void
    {
        self::createClient();
        $item = self::getContainer()->get(GetSellSwapListItems::class)->byPlayerId(self::STRIPE)[0];
        $settings = self::getContainer()->get(GetPlayerProfile::class)->byId(self::STRIPE)->sellSwapListSettings;
        $twig = self::getContainer()->get(Environment::class);

        foreach (['sell-swap/_mark_reserved_stream.html.twig', 'sell-swap/_remove_reservation_stream.html.twig'] as $template) {
            $crawler = new Crawler($twig->render($template, ['message' => 'Done', 'context' => 'list', 'item' => $item, 'settings' => $settings]));
            $replaced = new Crawler((string) $crawler->filter('turbo-stream[target="library-sell-swap-' . $item->puzzleId . '"] template')->html());

            // The wrapper still carries everything the filter needs, only the insights are left out
            $wrapper = $replaced->filter('#library-sell-swap-' . $item->puzzleId);
            self::assertCount(1, $wrapper, $template);
            self::assertSame('item', $wrapper->attr('data-collection-filter-target'));
            self::assertSame($item->listingType->value, $wrapper->attr('data-listing-type'));
            self::assertNull($wrapper->attr('data-difficulty-tier'));
            self::assertCount(0, $replaced->filter('svg.diff-icon'));
        }
    }

    public function testNonMemberSeesLockedIconsAndNoChips(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // His own collection (9 items, so the filters are there) ...
        $crawler = $browser->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_REGULAR);
        $this->assertResponseIsSuccessful();
        $this->assertLockedWithoutChips($crawler);

        // ... and a member's public collection: the viewer's membership decides
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $this->assertResponseIsSuccessful();
        $this->assertLockedWithoutChips($crawler);
    }

    public function testGuestSeesLockedIconsAndNoChips(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle-collection/' . self::STRIPE);
        $this->assertResponseIsSuccessful();
        $this->assertLockedWithoutChips($crawler);
    }

    public function testQueriesDoNotGrowWithTheList(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::STRIPE);
        $url = '/en/puzzle-collection/' . self::STRIPE;

        $browser->request('GET', $url); // warm-up (session, caches)
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        $before = $this->queryCount($browser);

        $connection = self::getContainer()->get(Connection::class);
        $puzzleIds = $connection->fetchFirstColumn('SELECT id FROM puzzle WHERE id NOT IN (SELECT puzzle_id FROM collection_item WHERE player_id = ? AND collection_id IS NULL)', [self::STRIPE]);
        foreach ($puzzleIds as $puzzleId) {
            $connection->insert('collection_item', [
                'id' => Uuid::uuid7()->toString(),
                'player_id' => self::STRIPE,
                'puzzle_id' => $puzzleId,
                'added_at' => '2026-01-01 10:00:00',
            ]);
        }
        self::assertGreaterThan(10, count($puzzleIds));

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', $url);
        $this->assertResponseIsSuccessful();

        self::assertSame($before, $this->queryCount($browser), 'The insights are one query for the whole list');
        self::assertCount(5 + count($puzzleIds), $crawler->filter('[data-difficulty-tier]'));
    }

    public function testNonMemberNeverQueriesDifficulty(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_REGULAR);
        $this->assertResponseIsSuccessful();

        foreach ($this->executedSql($browser) as $sql) {
            self::assertStringNotContainsString('puzzle_difficulty', $sql);
        }
    }

    private function assertLockedWithoutChips(Crawler $crawler): void
    {
        self::assertGreaterThan(0, $crawler->filter('.puzzle-solved-times')->count());
        self::assertGreaterThan(0, $this->icons($crawler, 'diff-locked')->count());
        self::assertCount($this->icons($crawler)->count(), $this->icons($crawler, 'diff-locked'));
        self::assertSame('#membersExclusiveModal', $this->icons($crawler, 'diff-locked')->closest('svg')?->attr('data-bs-target'));
        self::assertCount(0, $crawler->filter('[data-difficulty-tier]'));
        self::assertCount(0, $crawler->filter('input[data-collection-filter-target="difficultyTier"]'));
    }

    /**
     * Difficulty icons of the list items (the chip labels excluded).
     */
    private function icons(Crawler $crawler, string $symbol = 'diff-'): Crawler
    {
        return $crawler->filter('[data-collection-filter-target="item"] svg.diff-icon use, #lend-borrow-list svg.diff-icon use, .card svg.diff-icon use')
            ->reduce(static fn (Crawler $use): bool => str_contains((string) $use->attr('href'), '#' . $symbol));
    }
}
