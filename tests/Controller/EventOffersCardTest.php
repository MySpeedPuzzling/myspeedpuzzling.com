<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\EventJustJoined;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * The marketplace card on event and edition pages (docs/features/marketplace/11-events.md, "E2") for every kind of
 * viewer, against the Puzzle Swap Fair of .claude/fixtures.md: seller A (PLAYER_WITH_STRIPE, member) brings 2 of 7
 * published offers, seller B (PLAYER_ADMIN, member) goes with 5 published and nothing marked, buyer C
 * (PLAYER_REGULAR, no membership, no offers) goes too.
 */
final class EventOffersCardTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string FAIR_URL = '/en/events/' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR_SLUG;
    private const string FAIR = MarketplaceEventFixture::COMPETITION_SWAP_FAIR;
    private const string OFFLINE_EDITION_URL = '/en/series/puzzle-meetup-prague/puzzle-meetup-1';
    private const string ONLINE_EDITION_URL = '/en/series/euro-jigsaw-jam-series/ejj-69-may-2026';
    private const string PAST_EDITION_URL = '/en/series/berlin-puzzle-cup/berlin-puzzle-cup-2026';
    private const string WJPC_URL = '/en/events/wjpc-2024';

    private const string CARD = '[data-testid="event-offers"]';
    private const string SUMMARY_STATEMENT = 'WITH event_offer AS';

    public function testGuestSeesWhatIsComing(): void
    {
        $browser = self::createClient();

        $card = $this->card($browser->request('GET', self::FAIR_URL));

        self::assertStringContainsString('Thinking about buying, selling or swapping a puzzle here? Great!', $card->filter('h2')->text());
        self::assertSame(
            '2 puzzlers going to this event are bringing 2 puzzles, and they can bring any of 10 more puzzles if you ask. Meet in person, no shipping.',
            $card->filter('.event-offers-card-text')->text(),
        );
        self::assertSame('/en/marketplace?event=' . self::FAIR, $card->filter('a.btn-primary')->attr('href'));
        self::assertSame("See what's coming", $card->filter('a.btn-primary')->text());
        // Bringing sellers first
        self::assertSame(
            [PlayerFixture::PLAYER_WITH_STRIPE_NAME, 'Admin User'],
            $card->filter('.event-offers-card-face')->each(static fn (Crawler $face): string => (string) $face->attr('title')),
        );
        self::assertCount(0, $card->filter('.event-offers-card-footer'));
        self::assertCount(0, $card->filter('.event-offers-card-highlighted'));
        self::assertStringNotContainsString('event-offers-card-highlighted', (string) $card->attr('class'));
    }

    public function testGoingBuyerWithoutMembershipGetsNoOwnLine(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $card = $this->card($browser->request('GET', self::FAIR_URL));

        self::assertStringContainsString('2 puzzlers going to this event are bringing 2 puzzles', $card->text());
        self::assertCount(0, $card->filter('.event-offers-card-footer'));
    }

    public function testGoingSellerWithMarkedOffersCanChangeThem(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $footer = $this->card($browser->request('GET', self::FAIR_URL))->filter('.event-offers-card-footer');

        self::assertStringContainsString("You're bringing 2 puzzles.", $footer->text());
        self::assertSame('Change', $footer->filter('a')->text());
        self::assertSame('/en/events/' . self::FAIR . '/what-i-bring', $footer->filter('a')->attr('href'));
    }

    public function testGoingSellerWithNothingMarkedIsAskedWhatTheyPack(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $footer = $this->card($browser->request('GET', self::FAIR_URL))->filter('.event-offers-card-footer');

        self::assertStringContainsString('Bringing any puzzles?', $footer->text());
        self::assertSame("Choose what you'll pack", $footer->filter('a')->text());
        self::assertSame('/en/events/' . self::FAIR . '/what-i-bring', $footer->filter('a')->attr('href'));
    }

    public function testSellerNotGoingIsInvitedToGoToo(): void
    {
        $browser = self::createClient();
        // B does not go to the edition, A does - with 7 offers and nothing marked
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $card = $this->card($browser->request('GET', self::OFFLINE_EDITION_URL));

        self::assertSame(
            'Nobody has packed anything yet, but 1 puzzler going has 7 puzzles you can ask to bring. Meet in person, no shipping.',
            $card->filter('.event-offers-card-text')->text(),
        );
        self::assertSame('/en/marketplace?event=' . CompetitionSeriesFixture::EDITION_OFFLINE_1, $card->filter('a.btn-primary')->attr('href'));
        self::assertSame(
            'Going too? Click “I\'m going” and you can bring puzzles from your list.',
            $card->filter('.event-offers-card-footer')->text(),
        );
        self::assertCount(0, $card->filter('.event-offers-card-footer a'));
    }

    public function testOnlyBringingAndOneSeller(): void
    {
        $browser = self::createClient();
        // B stays home, A packs everything
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE competition_participant SET deleted_at = NOW() WHERE id = :id', ['id' => MarketplaceEventFixture::PARTICIPANT_FAIR_SELLER_B]);
        $database->executeStatement(
            "INSERT INTO sell_swap_list_item_event (sell_swap_list_item_id, competition_id, added_at)
             SELECT id, :competition, NOW() FROM sell_swap_list_item WHERE player_id = :player AND id NOT IN (SELECT sell_swap_list_item_id FROM sell_swap_list_item_event WHERE competition_id = :competition)",
            ['competition' => self::FAIR, 'player' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $card = $this->card($browser->request('GET', self::FAIR_URL));

        self::assertSame(
            '1 puzzler going to this event is bringing 7 puzzles. Meet in person, no shipping.',
            $card->filter('.event-offers-card-text')->text(),
        );
        self::assertCount(1, $card->filter('.event-offers-card-face'));
    }

    public function testSellerSeesTheEmptyCardWhereNothingIsComing(): void
    {
        $browser = self::createClient();
        // Nobody going to WJPC 2024 sells anything; A does not go there
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $card = $this->card($browser->request('GET', self::WJPC_URL));

        self::assertStringContainsString('event-offers-card-empty', (string) $card->attr('class'));
        self::assertSame('Thinking about selling or swapping a puzzle here? Great!', $card->filter('h2')->text());
        self::assertStringContainsString('Nobody has marked puzzles for this event yet.', $card->filter('.event-offers-card-text')->text());
        // Not going: no button, "I'm going" first
        self::assertCount(0, $card->filter('a.btn'));
        self::assertSame('Click “I\'m going” first, then choose what you\'ll bring.', $card->filter('.event-offers-card-footer')->text());
    }

    /**
     * Browser verification of PR #136: an event that manages its registration has "Register", not "I'm going" - the
     * card says so, and a full event asks for a spot first.
     */
    public function testOnAnEventThatManagesRegistrationTheCardSaysRegister(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => CompetitionFixture::COMPETITION_WJPC_2024]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $card = $this->card($browser->request('GET', self::WJPC_URL));

        self::assertSame('Register first, then choose what you\'ll bring.', $card->filter('.event-offers-card-footer')->text());

        // Full: joining is the waitlist now - a spot first
        $database->executeStatement('UPDATE competition SET capacity = 1 WHERE id = :id', ['id' => CompetitionFixture::COMPETITION_WJPC_2024]);
        $card = $this->card($browser->request('GET', self::WJPC_URL));

        self::assertSame('Once you have a spot at this event, choose what you\'ll bring.', $card->filter('.event-offers-card-footer')->text());
    }

    public function testGoingSellerNeverGetsTheEmptyCard(): void
    {
        $browser = self::createClient();
        // B joins WJPC 2024, where nothing was coming: B's own offers are on the way now - the empty card is for
        // events where nothing at all is
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO competition_participant (id, name, country, source, player_id, competition_id, connected_at) VALUES ('018d0014-0000-0000-0000-0000000000f1', 'Admin User', 'cz', 'self_joined', :player, :competition, NOW())",
            ['player' => PlayerFixture::PLAYER_ADMIN, 'competition' => CompetitionFixture::COMPETITION_WJPC_2024],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $card = $this->card($browser->request('GET', self::WJPC_URL));

        self::assertStringNotContainsString('event-offers-card-empty', (string) $card->attr('class'));
        self::assertStringContainsString('1 puzzler going has 5 puzzles you can ask to bring.', $card->filter('.event-offers-card-text')->text());
        self::assertStringContainsString('Bringing any puzzles?', $card->filter('.event-offers-card-footer')->text());
    }

    public function testNobodyButASellerGetsACardWhereNothingIsComing(): void
    {
        $browser = self::createClient();
        $browser->request('GET', self::WJPC_URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::CARD);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::WJPC_URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::CARD);
    }

    public function testJustJoinedNonMemberGetsTheHighlightedCardOnce(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $this->flashJustJoined($browser, self::FAIR);

        $card = $this->card($browser->request('GET', self::FAIR_URL));

        self::assertStringContainsString('event-offers-card-highlighted', (string) $card->attr('class'));
        self::assertSame('Fancy taking a new puzzle home from the event?', $card->filter('h2')->text());
        self::assertSame(
            '2 puzzlers going to this event are bringing 2 puzzles, and they can bring any of 10 more puzzles if you ask. Meet in person, no shipping. Find one you like and ask the seller to keep it for you.',
            $card->filter('.event-offers-card-text')->text(),
        );
        $footer = $card->filter('.event-offers-card-footer');
        self::assertSame('Want to sell or swap your own puzzles here too? That comes with membership.', $footer->text());
        self::assertSame('/en/membership', $footer->filter('a')->attr('href'));
        self::assertSame('membership', $footer->filter('a')->text());

        // The flash is taken - the next visit is an ordinary one
        $card = $this->card($browser->request('GET', self::FAIR_URL));
        self::assertStringNotContainsString('event-offers-card-highlighted', (string) $card->attr('class'));
        self::assertCount(0, $card->filter('.event-offers-card-footer'));
    }

    public function testJustJoinedMemberWithoutOffersIsPointedToTheirList(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE sell_swap_list_item SET published_on_marketplace = false WHERE player_id = :player',
            ['player' => PlayerFixture::PLAYER_ADMIN],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->flashJustJoined($browser, self::FAIR);

        $card = $this->card($browser->request('GET', self::FAIR_URL));

        self::assertStringContainsString('event-offers-card-highlighted', (string) $card->attr('class'));
        // B's offers are off the marketplace: A alone now
        self::assertStringContainsString('1 puzzler going to this event is bringing 2 puzzles, and can bring any of 5 more puzzles if you ask.', $card->text());
        $footer = $card->filter('.event-offers-card-footer');
        self::assertSame('Have puzzles to sell or swap? Add them to your sell/swap list.', $footer->text());
        self::assertSame('/en/sell-swap-list/' . PlayerFixture::PLAYER_ADMIN, $footer->filter('a')->attr('href'));
    }

    public function testJustJoinedFlashOfAnotherEventIsKeptForThatEvent(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        // Joined the edition in one tab, opened the fair in another
        $this->flashJustJoined($browser, CompetitionSeriesFixture::EDITION_OFFLINE_1);

        $card = $this->card($browser->request('GET', self::FAIR_URL));
        self::assertStringNotContainsString('event-offers-card-highlighted', (string) $card->attr('class'));

        $card = $this->card($browser->request('GET', self::OFFLINE_EDITION_URL));
        self::assertStringContainsString('event-offers-card-highlighted', (string) $card->attr('class'));
    }

    public function testCardSitsBetweenTheAttendanceRowAndTheParticipants(): void
    {
        $browser = self::createClient();

        foreach ([self::FAIR_URL, self::OFFLINE_EDITION_URL] as $url) {
            $content = $browser->request('GET', $url)->html();
            self::assertResponseIsSuccessful();

            $attendance = strpos($content, 'href="/en/join-event/');
            $card = strpos($content, 'data-testid="event-offers"');
            $participants = strpos($content, 'data-live-name-value="CompetitionParticipants"');

            self::assertNotFalse($attendance, $url);
            self::assertNotFalse($card, $url);
            self::assertNotFalse($participants, $url);
            self::assertGreaterThan($attendance, $card, "{$url}: the card comes after the \"I'm going\" row");
            self::assertLessThan($participants, $card, "{$url}: the card comes before the participants");
        }
    }

    public function testOnlineEditionHasNoCardAndRunsNoQueryForIt(): void
    {
        $browser = self::createClient();
        // B goes to the online edition and has 5 published offers
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->startCountingQueries($browser);
        $browser->request('GET', self::ONLINE_EDITION_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::CARD);
        self::assertSame(0, $this->summaryStatementRuns($browser));
    }

    public function testPastEventHasNoCardAndRunsNoQueryForIt(): void
    {
        $browser = self::createClient();
        // A went to the past edition and still has SELLSWAP_07 marked for it
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $browser->request('GET', self::PAST_EDITION_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::CARD);
        self::assertSame(0, $this->summaryStatementRuns($browser));
    }

    public function testEventThatIsNotPublicHasNoCard(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET approved_at = NULL WHERE id = :id',
            ['id' => self::FAIR],
        );

        $this->startCountingQueries($browser);
        $browser->request('GET', self::FAIR_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::CARD);
        self::assertSame(0, $this->summaryStatementRuns($browser));
    }

    /**
     * Performance budget (docs/features/marketplace/11-events.md): a marketplace event page pays exactly one statement
     * for the card, the viewer's line included; the same page of an event that does not qualify pays nothing.
     */
    public function testTheCardCostsExactlyOneQuery(): void
    {
        foreach ([null, PlayerFixture::PLAYER_WITH_STRIPE] as $viewer) {
            $browser = self::createClient();
            self::getContainer()->get(Connection::class)->executeStatement(
                'UPDATE competition SET is_online = false WHERE id = :id',
                ['id' => self::FAIR],
            );

            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }

            // Warm-up: whatever the page caches, both counted requests find it cached
            $browser->request('GET', self::FAIR_URL);

            $this->startCountingQueries($browser);
            $browser->request('GET', self::FAIR_URL);
            self::assertSelectorExists(self::CARD);
            self::assertSame(1, $this->summaryStatementRuns($browser));
            $qualifying = $this->queryCount($browser);

            // The very same event moved online: no card, one query less
            self::getContainer()->get(Connection::class)->executeStatement(
                'UPDATE competition SET is_online = true WHERE id = :id',
                ['id' => self::FAIR],
            );

            $this->startCountingQueries($browser);
            $browser->request('GET', self::FAIR_URL);
            self::assertSelectorNotExists(self::CARD);
            self::assertSame(0, $this->summaryStatementRuns($browser));
            self::assertSame($qualifying - 1, $this->queryCount($browser), $viewer === null ? 'guest' : 'signed-in seller');

            self::ensureKernelShutdown();
        }
    }

    private function card(Crawler $crawler): Crawler
    {
        self::assertResponseIsSuccessful();
        $card = $crawler->filter(self::CARD);
        self::assertCount(1, $card, 'The marketplace card is not on the page.');

        return $card;
    }

    private function summaryStatementRuns(KernelBrowser $browser): int
    {
        return count(array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => str_contains($sql, self::SUMMARY_STATEMENT),
        ));
    }

    /**
     * What the join flow (F2) leaves in the session - put in place of the signed-in browser's session.
     */
    private function flashJustJoined(KernelBrowser $browser, string $competitionId): void
    {
        $session = $browser->getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        $cookie = $browser->getCookieJar()->get($session->getName());
        self::assertNotNull($cookie, 'Sign in first - the flash goes into the signed-in session.');

        $session->setId($cookie->getValue());
        $session->start();
        $session->getFlashBag()->add(EventJustJoined::FLASH, $competitionId);
        $session->save();
    }
}
