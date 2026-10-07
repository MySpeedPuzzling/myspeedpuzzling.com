<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Load more" of the puzzle database keeps the "My list" filter and gates it like the page.
 */
final class PuzzleSearchItemsControllerTest extends WebTestCase
{
    public function testMemberPagesThroughHerList(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // sell-swap has 7 puzzles; the endpoint pages by 20, so start 3 before the end
        $data = $this->items($browser, '/en/puzzle-search-items?list=sell-swap&offset=4');

        self::assertCount(3, $data['ids']);
        self::assertFalse($data['hasMore']);

        $all = $this->items($browser, '/en/puzzle-search-items?list=sell-swap&offset=0');
        self::assertCount(7, $all['ids']);
        self::assertContains(PuzzleFixture::PUZZLE_1500_01, $all['ids']);
        self::assertNotContains(PuzzleFixture::PUZZLE_9000, $all['ids']);
    }

    public function testNextUrlCarriesTheList(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Make the wishlist longer than one page, but not longer than two
        $connection = self::getContainer()->get(Connection::class);
        $puzzleIds = $connection->fetchFirstColumn(
            'SELECT id FROM puzzle WHERE (hide_until IS NULL OR hide_until <= NOW()) AND id NOT IN (SELECT puzzle_id FROM wish_list_item WHERE player_id = ?) ORDER BY id LIMIT 30',
            [PlayerFixture::PLAYER_WITH_STRIPE],
        );
        foreach ($puzzleIds as $puzzleId) {
            $connection->insert('wish_list_item', [
                'id' => Uuid::uuid7()->toString(),
                'player_id' => PlayerFixture::PLAYER_WITH_STRIPE,
                'puzzle_id' => $puzzleId,
                'added_at' => '2026-01-01 10:00:00',
            ]);
        }
        $total = 3 + count($puzzleIds);
        self::assertGreaterThan(20, $total);

        $first = $this->items($browser, '/en/puzzle-search-items?list=wishlist&offset=0');
        self::assertCount(20, $first['ids']);
        self::assertTrue($first['hasMore']);
        self::assertIsString($first['nextUrl']);
        self::assertStringContainsString('list=wishlist', $first['nextUrl']);

        $second = $this->items($browser, $first['nextUrl']);
        self::assertCount($total - 20, $second['ids']);
        self::assertSame([], array_intersect($first['ids'], $second['ids']));
    }

    public function testGuestAndNonMemberCannotUseTheLists(): void
    {
        $browser = self::createClient();
        $unfiltered = $this->items($browser, '/en/puzzle-search-items?offset=0');

        // Guest: any list is ignored
        self::assertSame($unfiltered['ids'], $this->items($browser, '/en/puzzle-search-items?list=wishlist&offset=0')['ids']);

        // Non-member: a member list is ignored
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertSame($unfiltered['ids'], $this->items($browser, '/en/puzzle-search-items?list=sell-swap&offset=0')['ids']);
    }

    /**
     * @return array{ids: list<string>, hasMore: bool, nextUrl: null|string}
     */
    private function items(KernelBrowser $browser, string $url): array
    {
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        /** @var array{html: string, hasMore: bool, nextUrl: null|string} $data */
        $data = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $ids = (new Crawler($data['html']))->filter('[id^="puzzle-list-item-"]')->each(
            static fn (Crawler $item): string => substr((string) $item->attr('id'), strlen('puzzle-list-item-')),
        );

        return ['ids' => $ids, 'hasMore' => $data['hasMore'], 'nextUrl' => $data['nextUrl']];
    }
}
