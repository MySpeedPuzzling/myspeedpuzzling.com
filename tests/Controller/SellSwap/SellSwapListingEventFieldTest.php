<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\SellSwap;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Services\EventDateFormatter;
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
use Symfony\Component\DomCrawler\Form;

/**
 * "I'm bringing it to" + the reminder in the listing forms (docs/features/marketplace/11-events.md). Seller A
 * (PLAYER_WITH_STRIPE) goes to EDITION_OFFLINE_1 and the fair, SELLSWAP_01 is brought to the fair.
 */
final class SellSwapListingEventFieldTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string FIELD = 'input[name="add_to_sell_swap_list_form[eventIds][]"]';
    private const string ADD_URL = '/en/sell-swap/' . PuzzleFixture::PUZZLE_2000 . '/add';
    private const string EDIT_URL = '/en/edit-sell-swap-item/' . SellSwapListItemFixture::SELLSWAP_01;

    public function testAddFormOffersTheEventsTheSellerGoesTo(): void
    {
        $browser = $this->signedInAsSellerA();

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', self::ADD_URL);

        $this->assertResponseIsSuccessful();
        self::assertSame([CompetitionSeriesFixture::EDITION_OFFLINE_1, MarketplaceEventFixture::COMPETITION_SWAP_FAIR], $this->choices($crawler));
        self::assertSame([], $this->checked($crawler));
        self::assertSame("I'm bringing it to", trim($crawler->filter('.marketplace-events-form legend')->text()));
        self::assertStringContainsString('Puzzle Meetup Prague · Puzzle Meetup #1', $crawler->filter('.marketplace-events-form')->text());
        self::assertStringContainsString('Olomouc', $crawler->filter('.marketplace-events-form')->text());
        // The same locale-aware date as every other marketplace-at-events label (event_dates())
        $fair = self::getContainer()->get(GetMarketplaceEvents::class)->byId(MarketplaceEventFixture::COMPETITION_SWAP_FAIR);
        $fairDates = self::getContainer()->get(EventDateFormatter::class)->format($fair->dateFrom, $fair->dateTo, 'en');
        $fairMeta = $crawler->filter(sprintf('%s[value="%s"]', self::FIELD, MarketplaceEventFixture::COMPETITION_SWAP_FAIR))->closest('.form-check')?->filter('.marketplace-events-form-meta');
        self::assertNotNull($fairMeta);
        self::assertStringStartsWith($fairDates . ' · ', trim(preg_replace('/\s+/', ' ', $fairMeta->text()) ?? ''));
        self::assertStringContainsString('Going somewhere else too?', $crawler->filter('.marketplace-events-form-hint')->text());
        self::assertCount(1, $crawler->filter('.marketplace-events-form a[href="/en/events"]'));

        self::assertCount(1, $this->eventStatements($browser), 'the form costs one query (GetMarketplaceEvents::forPlayer)');
    }

    public function testAddModalHasTheFieldToo(): void
    {
        $browser = $this->signedInAsSellerA();

        $crawler = $browser->request('GET', self::ADD_URL, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        $this->assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter(self::FIELD));
        // Inside the modal frame the link must leave the frame
        self::assertSame('_top', $crawler->filter('.marketplace-events-form a[href="/en/events"]')->attr('data-turbo-frame'));
    }

    public function testSellerGoingNowhereGetsTheReminderOnly(): void
    {
        $browser = $this->signedInAsSellerA();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE player_id = :player',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $crawler = $browser->request('GET', self::ADD_URL);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::FIELD));
        self::assertStringContainsString('Heading to a puzzle event?', $crawler->filter('.marketplace-events-form-note')->text());
        self::assertCount(1, $crawler->filter('.marketplace-events-form-note a[href="/en/events"]'));
    }

    public function testNonMemberPaysNoQuery(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', self::ADD_URL);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.marketplace-events-form'));
        self::assertSame([], $this->eventStatements($browser));
    }

    public function testAddingWithAnEventBringsTheNewListing(): void
    {
        $browser = $this->signedInAsSellerA();

        $crawler = $browser->request('GET', self::ADD_URL);
        $form = $crawler->filter('form[name="add_to_sell_swap_list_form"]')->form();
        $form['add_to_sell_swap_list_form[listingType]'] = 'sell';
        $form['add_to_sell_swap_list_form[price]'] = '12';
        $form['add_to_sell_swap_list_form[condition]'] = (string) $crawler->filter('select[name="add_to_sell_swap_list_form[condition]"] option')->eq(2)->attr('value');
        $this->submitWithEvents($browser, $form, [MarketplaceEventFixture::COMPETITION_SWAP_FAIR]);

        $this->assertResponseRedirects();

        $itemId = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT id FROM sell_swap_list_item WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzle' => PuzzleFixture::PUZZLE_2000],
        );
        self::assertIsString($itemId);
        self::assertSame([MarketplaceEventFixture::COMPETITION_SWAP_FAIR], $this->eventsOf($itemId));
    }

    public function testEditFormPreselectsTheEventsTheListingIsBroughtTo(): void
    {
        $browser = $this->signedInAsSellerA();

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', self::EDIT_URL);

        $this->assertResponseIsSuccessful();
        self::assertSame([CompetitionSeriesFixture::EDITION_OFFLINE_1, MarketplaceEventFixture::COMPETITION_SWAP_FAIR], $this->choices($crawler));
        self::assertSame([MarketplaceEventFixture::COMPETITION_SWAP_FAIR], $this->checked($crawler));
        self::assertCount(1, $this->eventStatements($browser), 'events + preselection in one query (GetMarketplaceEvents::forListingSeller)');

        $modal = $browser->request('GET', self::EDIT_URL, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertSame([MarketplaceEventFixture::COMPETITION_SWAP_FAIR], $this->checked($modal));
    }

    public function testSavingTheEditFormSyncsTheEvents(): void
    {
        $browser = $this->signedInAsSellerA();

        $crawler = $browser->request('GET', self::EDIT_URL);
        $form = $crawler->filter('form[name="add_to_sell_swap_list_form"]')->form();
        $this->submitWithEvents($browser, $form, [CompetitionSeriesFixture::EDITION_OFFLINE_1]);

        $this->assertResponseRedirects('/en/sell-swap-list/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame([CompetitionSeriesFixture::EDITION_OFFLINE_1], $this->eventsOf(SellSwapListItemFixture::SELLSWAP_01));
    }

    public function testSavingTheEditFormKeepsHistoryOfPastEvents(): void
    {
        $browser = $this->signedInAsSellerA();

        // SELLSWAP_07 is still marked for the past edition - not on the form, and saving keeps it
        $crawler = $browser->request('GET', '/en/edit-sell-swap-item/' . SellSwapListItemFixture::SELLSWAP_07);
        self::assertSame([], $this->checked($crawler));
        $browser->submit($crawler->filter('form[name="add_to_sell_swap_list_form"]')->form());

        $this->assertResponseRedirects();
        self::assertSame([CompetitionSeriesFixture::EDITION_PAST_ONLY_1], $this->eventsOf(SellSwapListItemFixture::SELLSWAP_07));
    }

    private function signedInAsSellerA(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        return $browser;
    }

    /**
     * @return list<string>
     */
    private function choices(Crawler $crawler): array
    {
        return $crawler->filter(self::FIELD)->each(static fn (Crawler $input): string => (string) $input->attr('value'));
    }

    /**
     * @return list<string>
     */
    private function checked(Crawler $crawler): array
    {
        return $crawler->filter(self::FIELD . ':checked')->each(static fn (Crawler $input): string => (string) $input->attr('value'));
    }

    /**
     * Submits the form as the browser would with exactly these "I'm bringing it to" boxes ticked.
     *
     * @param list<string> $competitionIds
     */
    private function submitWithEvents(KernelBrowser $browser, Form $form, array $competitionIds): void
    {
        $values = $form->getPhpValues();
        self::assertIsArray($values['add_to_sell_swap_list_form']);
        $values['add_to_sell_swap_list_form']['eventIds'] = $competitionIds;

        $browser->request($form->getMethod(), $form->getUri(), $values, server: ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /**
     * @return list<string>
     */
    private function eventStatements(KernelBrowser $browser): array
    {
        return array_values(array_filter(
            $this->executedSql($browser),
            static fn (string $statement): bool => str_contains($statement, 'going_participant'),
        ));
    }

    /**
     * @return list<string>
     */
    private function eventsOf(string $listItemId): array
    {
        /** @var list<string> $ids */
        $ids = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT competition_id FROM sell_swap_list_item_event WHERE sell_swap_list_item_id = :id ORDER BY competition_id',
            ['id' => $listItemId],
        );

        return $ids;
    }
}
