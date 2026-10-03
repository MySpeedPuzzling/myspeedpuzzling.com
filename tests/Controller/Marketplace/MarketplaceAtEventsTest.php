<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Marketplace;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The marketplace's "Pick up at an event" (docs/features/marketplace/11-events.md) as a page: the select, a link
 * with ?event=, the labels, the query budget. Fixture roles in GetMarketplaceListingsAtEventsTest.
 */
final class MarketplaceAtEventsTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string FAIR = MarketplaceEventFixture::COMPETITION_SWAP_FAIR;

    public function testTheSelectOffersTheEventsSellersAreGoingTo(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/marketplace');

        self::assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));

        $options = $crawler->filter('#marketplaceEventFilter option');
        self::assertSame(['', CompetitionSeriesFixture::EDITION_OFFLINE_1, self::FAIR], $options->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('Any event', $options->eq(0)->text());
        self::assertMatchesRegularExpression('/^Puzzle Meetup #1 — .+ — 7 to ask$/u', $options->eq(1)->text());
        self::assertMatchesRegularExpression('/^Puzzle Swap Fair — .+ · Olomouc — 2 coming · 10 to ask$/u', $options->eq(2)->text());

        self::assertCount(0, $crawler->filter('[data-testid="marketplace-event-context"]'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-testid="marketplace-seller-country"]')->attr('class'));
    }

    public function testTheSelectIsHiddenWhenNoSellerGoesAnywhere(): void
    {
        $browser = self::createClient();
        $this->database($browser)->executeStatement('UPDATE competition_participant SET deleted_at = NOW()');

        $crawler = $browser->request('GET', '/en/marketplace');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#marketplaceEventFilter'));
    }

    public function testALinkToAnEventShowsItsListingsBringingFirstAndStaysOutOfTheIndex(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/marketplace?event=' . self::FAIR);

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));

        $header = $crawler->filter('[data-testid="marketplace-event-context"]');
        self::assertCount(1, $header);
        self::assertStringContainsString('Puzzle Swap Fair', $header->text());
        self::assertStringContainsString('Olomouc', $header->text());
        self::assertStringContainsString('2 coming · 10 to ask', $header->text());
        self::assertStringContainsString('Pick up in person, shipping filters are off', $header->text());
        self::assertCount(1, $header->filter('input#onlyBringingFilter:not([checked])'));
        self::assertSame(self::FAIR, $crawler->filter('#marketplaceEventFilter option[selected]')->attr('value'));

        // Shipping filters are off
        self::assertStringContainsString('d-none', (string) $crawler->filter('[data-testid="marketplace-ship-to-my-country"]')->attr('class'));
        self::assertStringContainsString('d-none', (string) $crawler->filter('[data-testid="marketplace-seller-country"]')->attr('class'));

        // One list: the 2 they are bringing, the divider, the 10 to ask for
        $cards = $crawler->filter('[id^="marketplace-listing-"]');
        self::assertCount(12, $cards);
        self::assertCount(1, $cards->eq(0)->filter('[data-testid="listing-bringing"]'));
        self::assertCount(1, $cards->eq(1)->filter('[data-testid="listing-bringing"]'));
        self::assertCount(0, $cards->eq(2)->filter('[data-testid="listing-bringing"]'));

        $divider = $crawler->filter('[data-testid="marketplace-event-divider"]');
        self::assertCount(1, $divider);
        self::assertSame('Not packed yet · these sellers are going, ask them to bring it (10)', $divider->text());
        self::assertSame($cards->eq(2)->attr('id'), $divider->nextAll()->first()->attr('id'));

        // Guests sign in to ask, like for "Contact seller" - not on listings reserved for somebody (03, 04, 05, 12)
        $ask = $crawler->filter('[data-testid="listing-ask-to-bring"]');
        self::assertCount(6, $ask);
        self::assertSame('/login?return=/en/marketplace?event=' . self::FAIR, urldecode((string) $ask->first()->attr('href')));
    }

    public function testAnythingButAMarketplaceEventIsIgnored(): void
    {
        $browser = self::createClient();
        $all = $browser->request('GET', '/en/marketplace')->filter('[id^="marketplace-listing-"]')->count();

        foreach ([CompetitionSeriesFixture::EDITION_EJJ_69, CompetitionSeriesFixture::EDITION_PAST_ONLY_1, 'nonsense'] as $event) {
            $crawler = $browser->request('GET', '/en/marketplace?event=' . $event);

            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('[data-testid="marketplace-event-context"]'), $event);
            self::assertCount($all, $crawler->filter('[id^="marketplace-listing-"]'), $event);
        }
    }

    public function testOnlyWhatTheyAreBringing(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/marketplace?event=' . self::FAIR . '&onlyBringing=1');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[id^="marketplace-listing-"]'));
        self::assertCount(2, $crawler->filter('[data-testid="listing-bringing"]'));
        self::assertCount(0, $crawler->filter('[data-testid="marketplace-event-divider"]'));
        self::assertCount(1, $crawler->filter('input#onlyBringingFilter[checked]'));
    }

    public function testNoDividerWhenNothingIsComing(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/marketplace?event=' . CompetitionSeriesFixture::EDITION_OFFLINE_1);

        self::assertResponseIsSuccessful();
        self::assertCount(7, $crawler->filter('[id^="marketplace-listing-"]'));
        self::assertCount(0, $crawler->filter('[data-testid="marketplace-event-divider"]'));
        // A's 7 listings, 3 of them reserved for somebody
        self::assertCount(4, $crawler->filter('[data-testid="listing-ask-to-bring"]'));
    }

    public function testEveryoneSeesWhatIsBroughtWhere(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/marketplace');

        $label = $crawler->filter('#marketplace-listing-' . SellSwapListItemFixture::SELLSWAP_01 . ' [data-testid="listing-bringing-to"]');
        self::assertCount(1, $label);
        self::assertStringStartsWith('Bringing to Puzzle Swap Fair · ', $label->text());
        self::assertCount(0, $crawler->filter('[data-testid="listing-seller-goes-to"]'));
        self::assertCount(0, $crawler->filter('[data-testid="listing-ask-to-bring"]'));
        // A history row (SELLSWAP_07 marked for an event that is over) labels nothing
        self::assertCount(0, $crawler->filter('#marketplace-listing-' . SellSwapListItemFixture::SELLSWAP_07 . ' [data-testid="listing-bringing-to"]'));
    }

    public function testABuyerGoingThereIsOfferedToAskTheSellers(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/marketplace');

        $card = $crawler->filter('#marketplace-listing-' . SellSwapListItemFixture::SELLSWAP_08);
        self::assertStringStartsWith('Seller goes to Puzzle Swap Fair · ', $card->filter('[data-testid="listing-seller-goes-to"]')->text());
        self::assertSame(
            '/en/messages/new/offer/' . SellSwapListItemFixture::SELLSWAP_08 . '?event=' . self::FAIR,
            $card->filter('[data-testid="listing-ask-to-bring"]')->attr('href'),
        );

        // Already coming: the label, nothing to ask
        $bringing = $crawler->filter('#marketplace-listing-' . SellSwapListItemFixture::SELLSWAP_01);
        self::assertCount(1, $bringing->filter('[data-testid="listing-bringing-to"]'));
        self::assertCount(0, $bringing->filter('[data-testid="listing-ask-to-bring"]'));
    }

    public function testReservedForTheViewerAtTheEvent(): void
    {
        // SELLSWAP_04 is reserved for B; A marks it for the fair
        $browser = self::createClient();
        $this->database($browser)->executeStatement(
            'INSERT INTO sell_swap_list_item_event (sell_swap_list_item_id, competition_id, added_at) VALUES (:item, :competition, NOW())',
            ['item' => SellSwapListItemFixture::SELLSWAP_04, 'competition' => self::FAIR],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $card = $browser->request('GET', '/en/marketplace')->filter('#marketplace-listing-' . SellSwapListItemFixture::SELLSWAP_04);

        self::assertStringStartsWith('Reserved for you · Puzzle Swap Fair · ', $card->filter('.mpe-reserved-for-you')->text());

        // Anyone else sees the plain reservation
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $card = $browser->request('GET', '/en/marketplace')->filter('#marketplace-listing-' . SellSwapListItemFixture::SELLSWAP_04);
        self::assertCount(0, $card->filter('.mpe-reserved-for-you'));
        self::assertStringContainsStringIgnoringCase('Reserved', $card->text());
    }

    public function testABlockedSellerStaysHiddenUnderTheEvent(): void
    {
        $browser = self::createClient();
        $this->database($browser)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_REGULAR, 'blocked' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/marketplace?event=' . self::FAIR);

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('[id^="marketplace-listing-"]'));
        self::assertCount(0, $crawler->filter('[data-testid="listing-bringing"]'));
        self::assertStringContainsString('5 to ask', $crawler->filter('[data-testid="marketplace-event-context"]')->text());
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_STRIPE, $content);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $content);
    }

    public function testTheQueryCountDoesNotDependOnTheListingsOrTheEvent(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // The first request after signing in does the session's own work - warm up first
        $browser->request('GET', '/en/marketplace');
        $counts = [];

        $pages = [
            'no event, all listings' => '/en/marketplace',
            'no event, one listing' => '/en/marketplace?search=' . urlencode('Puzzle 5'),
            'event, 12 listings' => '/en/marketplace?event=' . self::FAIR,
            'event, one listing' => '/en/marketplace?event=' . self::FAIR . '&search=' . urlencode('Puzzle 5'),
            'event, only bringing' => '/en/marketplace?event=' . self::FAIR . '&onlyBringing=1',
        ];

        foreach ($pages as $case => $url) {
            $this->startCountingQueries($browser);
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertGreaterThan(0, $crawler->filter('[id^="marketplace-listing-"]')->count(), $case);

            $counts[$case] = $this->queryCount($browser);
        }

        self::assertCount(1, array_unique($counts), (string) json_encode($counts));
    }

    private function database(KernelBrowser $browser): Connection
    {
        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);

        return $connection;
    }
}
