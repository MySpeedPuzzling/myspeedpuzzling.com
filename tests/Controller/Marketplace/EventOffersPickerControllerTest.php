<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Marketplace;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

final class EventOffersPickerControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string FAIR_PICKER = '/en/events/' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR . '/what-i-bring';
    private const string FAIR_PAGE = '/en/events/' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR_SLUG;
    // The flashes base.html.twig renders at the top of <main>
    private const string FLASH = '#main-content > .container > ';

    public function testGoingMemberWithListingsGetsThePicker(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/events/' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR . '/what-i-bring');

        $this->assertResponseIsSuccessful();
        self::assertSame('What will you bring to Puzzle Swap Fair?', trim($crawler->filter('h1')->text()));
    }

    public function testGoingMemberGetsThePickerForAnEditionToo(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/events/' . CompetitionSeriesFixture::EDITION_OFFLINE_1 . '/what-i-bring');

        $this->assertResponseIsSuccessful();
        self::assertSame('What will you bring to Puzzle Meetup Prague · Puzzle Meetup #1?', trim($crawler->filter('h1')->text()));
    }

    public function testEveryLocaleHasItsPath(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach (['/eventy/%s/co-privezu', '/es/eventos/%s/que-llevo', '/ja/イベント/%s/持っていくもの', '/fr/evenements/%s/ce-que-j-apporte', '/de/veranstaltungen/%s/was-ich-mitbringe'] as $path) {
            $browser->request('GET', sprintf($path, MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
            $this->assertResponseIsSuccessful($path);
        }
    }

    public function testRouteDoesNotCollideWithTheEventPage(): void
    {
        $browser = self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);

        self::assertSame('event_offers_picker', $router->match('/en/events/' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR . '/what-i-bring')['_route']);
        self::assertSame('event_detail', $router->match('/en/events/' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR_SLUG)['_route']);

        $browser->request('GET', '/en/events/' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR_SLUG);
        $this->assertResponseIsSuccessful();
    }

    public function testGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR . '/what-i-bring');

        $this->assertResponseRedirects('/login?return=/en/events/' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR . '/what-i-bring');
    }

    public function testMemberNotGoingIsSentToTheEventPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/events/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/what-i-bring');
        $this->assertResponseRedirects('/en/events/wjpc-2024');

        $browser->request('GET', '/en/events/' . CompetitionSeriesFixture::EDITION_OFFLINE_1 . '/what-i-bring');
        $this->assertResponseRedirects('/en/series/puzzle-meetup-prague/puzzle-meetup-1');
    }

    public function testGoingPlayerWithoutMembershipIsSentToTheEventPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/events/' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR . '/what-i-bring');

        $this->assertResponseRedirects('/en/events/' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR_SLUG);
    }

    public function testEventThatDoesNotQualifyIsNotFound(): void
    {
        $browser = self::createClient();

        // Seller B is going to the online edition
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', '/en/events/' . CompetitionSeriesFixture::EDITION_EJJ_69 . '/what-i-bring');
        $this->assertResponseStatusCodeSame(404);

        // Seller A is going to the past edition
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/events/' . CompetitionSeriesFixture::EDITION_PAST_ONLY_1 . '/what-i-bring');
        $this->assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/events/not-a-uuid/what-i-bring');
        $this->assertResponseStatusCodeSame(404);

        // No membership: still a 404, not a redirect
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/events/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE . '/what-i-bring');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPickerListsPublishedOffersWithThisEventsStateTicked(): void
    {
        $browser = self::createClient();
        // SELLSWAP_03 is also brought to the Prague edition A goes to - SELLSWAP_07's link to the past edition is history
        self::getContainer()->get(Connection::class)->insert('sell_swap_list_item_event', [
            'sell_swap_list_item_id' => SellSwapListItemFixture::SELLSWAP_03,
            'competition_id' => CompetitionSeriesFixture::EDITION_OFFLINE_1,
            'added_at' => '2026-01-01 10:00:00',
        ]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::FAIR_PICKER);

        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));

        // Seller A's 7 published listings, nothing ticked but what is brought to the fair already
        self::assertCount(7, $crawler->filter('input[name="listings[]"]'));
        self::assertSame(
            [SellSwapListItemFixture::SELLSWAP_01, SellSwapListItemFixture::SELLSWAP_02],
            $this->sorted($crawler->filter('input[name="listings[]"]:checked')->each(static fn (Crawler $input): string => (string) $input->attr('value'))),
        );
        self::assertSame('2 selected', trim($crawler->filter('[data-event-offers-picker-target="count"]')->text()));
        self::assertSame('Search your 7 offers…', $crawler->filter('input.event-offers-picker-search')->attr('placeholder'));

        self::assertStringContainsString('Also at Puzzle Meetup #1', $this->row($crawler, SellSwapListItemFixture::SELLSWAP_03)->text());
        self::assertStringContainsString('RESERVED', $this->row($crawler, SellSwapListItemFixture::SELLSWAP_03)->text());
        self::assertStringNotContainsString('Also at', $this->row($crawler, SellSwapListItemFixture::SELLSWAP_07)->text());
        self::assertStringNotContainsString('RESERVED', $this->row($crawler, SellSwapListItemFixture::SELLSWAP_01)->text());

        // Normal mode: Cancel back to the event, no "just joined" confirmation
        self::assertCount(0, $crawler->filter('.event-offers-picker-joined'));
        self::assertSame('Cancel', trim($crawler->filter('.event-offers-picker-footer a')->text()));
        self::assertSame(self::FAIR_PAGE, $crawler->filter('.event-offers-picker-footer a')->attr('href'));
        self::assertSame(self::FAIR_PICKER, $crawler->filter('form[method="post"]')->attr('action'));
    }

    public function testJoinedModeConfirmsTheJoinAndOffersSkip(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::FAIR_PICKER . '?joined=1');

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString("You're going to Puzzle Swap Fair!", $crawler->filter('.event-offers-picker-joined')->text());
        self::assertSame('Optional', trim($crawler->filter('.event-offers-picker-eyebrow')->text()));
        self::assertSame('Bringing any puzzles to sell or swap?', trim($crawler->filter('h1')->text()));
        self::assertSame('Skip for now', trim($crawler->filter('.event-offers-picker-footer a')->text()));
        self::assertSame(self::FAIR_PAGE, $crawler->filter('.event-offers-picker-footer a')->attr('href'));
        self::assertSame(self::FAIR_PICKER . '?joined=1', $crawler->filter('form[method="post"]')->attr('action'));
    }

    public function testSellerWithoutPublishedOffersIsPointedToTheList(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE sell_swap_list_item SET published_on_marketplace = false WHERE player_id = :player',
            ['player' => PlayerFixture::PLAYER_ADMIN],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::FAIR_PICKER);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('You have no published offers yet.', $crawler->filter('.event-offers-picker-empty')->text());
        self::assertCount(1, $crawler->filter('.event-offers-picker-empty a[href="/en/sell-swap-list/' . PlayerFixture::PLAYER_ADMIN . '"]'));
        self::assertCount(0, $crawler->filter('input[name="listings[]"]'));
    }

    public function testSavingBringsExactlyTheTickedOffersAndReturnsToTheEvent(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->save($browser, self::FAIR_PICKER . '?joined=1', [SellSwapListItemFixture::SELLSWAP_03, SellSwapListItemFixture::SELLSWAP_04]);

        $this->assertResponseRedirects(self::FAIR_PAGE);
        self::assertSame(
            $this->sorted([SellSwapListItemFixture::SELLSWAP_03, SellSwapListItemFixture::SELLSWAP_04]),
            $this->listingsAt(MarketplaceEventFixture::COMPETITION_SWAP_FAIR),
        );

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Done! 2 puzzles are marked for Puzzle Swap Fair.', $crawler->filter(self::FLASH . '.alert-success')->text());
    }

    public function testSavingNothingSaysSo(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->save($browser, self::FAIR_PICKER, []);

        $this->assertResponseRedirects(self::FAIR_PAGE);
        self::assertSame([], $this->listingsAt(MarketplaceEventFixture::COMPETITION_SWAP_FAIR));

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Saved. Nothing is marked for Puzzle Swap Fair.', $crawler->filter(self::FLASH . '.alert-success')->text());
    }

    public function testSavingAnEditionReturnsToTheEditionPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->save($browser, '/en/events/' . CompetitionSeriesFixture::EDITION_OFFLINE_1 . '/what-i-bring', [SellSwapListItemFixture::SELLSWAP_05]);

        $this->assertResponseRedirects('/en/series/puzzle-meetup-prague/puzzle-meetup-1');
        self::assertSame([SellSwapListItemFixture::SELLSWAP_05], $this->listingsAt(CompetitionSeriesFixture::EDITION_OFFLINE_1));
    }

    public function testSavingSomeoneElsesListingIsNotFound(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->save($browser, self::FAIR_PICKER, [SellSwapListItemFixture::SELLSWAP_08]);

        $this->assertResponseStatusCodeSame(404);
        self::assertSame(
            $this->sorted([SellSwapListItemFixture::SELLSWAP_01, SellSwapListItemFixture::SELLSWAP_02]),
            $this->listingsAt(MarketplaceEventFixture::COMPETITION_SWAP_FAIR),
        );
    }

    public function testSavingFromAnotherSiteIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->save($browser, self::FAIR_PICKER, [], 'https://evil.example');

        $this->assertResponseStatusCodeSame(403);
        self::assertCount(2, $this->listingsAt(MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
    }

    public function testSavingWithoutMembershipChangesNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->save($browser, self::FAIR_PICKER, []);

        $this->assertResponseRedirects(self::FAIR_PAGE);
        self::assertCount(2, $this->listingsAt(MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
    }

    public function testPickerCostsTwoQueriesWhateverTheNumberOfOffers(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        // The first request reuses the kernel the login booted - compare two requests that both boot their own
        $browser->request('GET', self::FAIR_PICKER);

        $this->startCountingQueries($browser);
        $browser->request('GET', self::FAIR_PICKER);
        $this->assertResponseIsSuccessful();
        $few = $this->queryCount($browser);
        self::assertCount(2, $this->pickerStatements($this->executedSql($browser)), 'the events of the seller + the offers with their links');

        // Five more offers, two of them brought to the Prague edition as well
        $database = self::getContainer()->get(Connection::class);
        foreach ([PuzzleFixture::PUZZLE_300, PuzzleFixture::PUZZLE_2000, PuzzleFixture::PUZZLE_3000, PuzzleFixture::PUZZLE_1500_02, PuzzleFixture::PUZZLE_4000] as $i => $puzzleId) {
            $itemId = Uuid::uuid7()->toString();
            $database->insert('sell_swap_list_item', [
                'id' => $itemId,
                'player_id' => PlayerFixture::PLAYER_WITH_STRIPE,
                'puzzle_id' => $puzzleId,
                'listing_type' => 'sell',
                'price' => 10,
                'condition' => 'normal',
                'added_at' => '2026-01-01 10:00:00',
                'published_on_marketplace' => 'true',
                'reserved' => 'false',
            ]);

            if ($i < 2) {
                $database->insert('sell_swap_list_item_event', [
                    'sell_swap_list_item_id' => $itemId,
                    'competition_id' => CompetitionSeriesFixture::EDITION_OFFLINE_1,
                    'added_at' => '2026-01-01 10:00:00',
                ]);
            }
        }

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', self::FAIR_PICKER);
        $this->assertResponseIsSuccessful();
        self::assertCount(12, $crawler->filter('input[name="listings[]"]'));
        self::assertSame($few, $this->queryCount($browser));
        self::assertCount(2, $this->pickerStatements($this->executedSql($browser)));
    }

    private function row(Crawler $crawler, string $listItemId): Crawler
    {
        $row = $crawler->filter(sprintf('input[value="%s"]', $listItemId))->closest('li');
        self::assertNotNull($row);

        return $row;
    }

    /**
     * @param list<string> $listItemIds
     */
    private function save(KernelBrowser $browser, string $url, array $listItemIds, string $origin = 'http://localhost'): void
    {
        $browser->request('POST', $url, [
            '_token' => 'csrf-token',
            'listings' => $listItemIds,
        ], server: ['HTTP_ORIGIN' => $origin]);
    }

    /**
     * The statements the marketplace-events feature adds: the seller's events (attendance) and the offers.
     *
     * @param list<string> $sql
     * @return list<string>
     */
    private function pickerStatements(array $sql): array
    {
        return array_values(array_filter(
            $sql,
            static fn (string $statement): bool => str_contains($statement, 'going_participant') || str_contains($statement, 'sell_swap_list_item_event'),
        ));
    }

    /**
     * @return list<string>
     */
    private function listingsAt(string $competitionId): array
    {
        /** @var list<string> $ids */
        $ids = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT sell_swap_list_item_id FROM sell_swap_list_item_event WHERE competition_id = :id ORDER BY sell_swap_list_item_id',
            ['id' => $competitionId],
        );

        return $ids;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
