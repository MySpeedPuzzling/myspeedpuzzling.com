<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Messaging;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Services\EventDateFormatter;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\ParticipantSource;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Bring to event" in a conversation (docs/features/marketplace/11-events.md). CONVERSATION_MARKETPLACE: Michael Johnson
 * (PLAYER_WITH_FAVORITES) asks seller A (PLAYER_WITH_STRIPE) about SELLSWAP_01 ("Puzzle 1"), which A brings to the fair;
 * A also goes to the Prague edition.
 */
final class ConversationBringToEventTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string CONVERSATION = '/en/messages/' . ConversationFixture::CONVERSATION_MARKETPLACE;
    private const string BRING = '/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_01 . '/bring-to-event';
    private const string MENU = '#conversation-bring-to-event-' . SellSwapListItemFixture::SELLSWAP_01;
    private const string BUYER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string PRAGUE = CompetitionSeriesFixture::EDITION_OFFLINE_1;
    private const string FAIR = MarketplaceEventFixture::COMPETITION_SWAP_FAIR;

    public function testSellerGetsOneGroupPerEventWithTheBroughtOneChecked(): void
    {
        $browser = self::createClient();
        // The buyer goes to the fair too
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $participant = new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: 'Michael Johnson',
            country: 'de',
            competition: self::getContainer()->get(CompetitionRepository::class)->get(self::FAIR),
            source: ParticipantSource::SelfJoined,
        );
        $participant->connect(self::getContainer()->get(PlayerRepository::class)->get(self::BUYER), new DateTimeImmutable('-1 day'));
        $entityManager->persist($participant);
        $entityManager->flush();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', self::CONVERSATION);

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $this->menuStatements($browser), 'one query for the seller\'s events, the links and the buyer\'s attendance');

        $menu = $crawler->filter(self::MENU);
        self::assertStringContainsString('Bring to event', $menu->filter('button.dropdown-toggle')->text());

        $headers = $menu->filter('.dropdown-header')->each(static fn (Crawler $header): string => trim(preg_replace('/\s+/', ' ', $header->text()) ?? ''));
        self::assertCount(2, $headers);
        // Nearest first: Prague (+14 days), then the fair (+21) - only the fair has the buyer going too
        self::assertStringStartsWith('Puzzle Meetup Prague · Puzzle Meetup #1 · ', $headers[0]);
        self::assertStringNotContainsString('is going too', $headers[0]);
        self::assertStringStartsWith('Puzzle Swap Fair · ' . $this->eventDates(self::FAIR) . ' ', $headers[1]);
        self::assertStringStartsWith('Puzzle Meetup Prague · Puzzle Meetup #1 · ' . $this->eventDates(self::PRAGUE), $headers[0]);
        self::assertStringEndsWith('Michael Johnson is going too', $headers[1]);

        // The fair: already brought - checked, no form; Prague: a form
        self::assertCount(1, $menu->filter('.dropdown-item.disabled'));
        self::assertCount(1, $menu->filter(sprintf('form input[name="competition_id"][value="%s"]', self::FAIR)));
        self::assertCount(2, $menu->filter(sprintf('form input[name="competition_id"][value="%s"]', self::PRAGUE)));
        self::assertCount(2, $menu->filter(sprintf('form input[name="reserve_for_player_id"][value="%s"]', self::BUYER)));
        self::assertStringContainsString('Bring it and reserve it for Michael Johnson', $menu->text());
    }

    public function testBuyerSeesNoMenuAndPaysNoQuery(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::BUYER);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', self::CONVERSATION);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::MENU));
        self::assertSame([], $this->menuStatements($browser));
    }

    public function testSellerGoingNowhereSeesNoMenu(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE player_id = :player',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::CONVERSATION);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::MENU));
        // Reserve / Sold are still there
        self::assertCount(1, $crawler->filter('#conversation-listing-actions-' . SellSwapListItemFixture::SELLSWAP_01));
    }

    public function testBringingAnswersWithAStreamThatChecksTheEvent(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->bring($browser, self::PRAGUE, stream: true);

        $this->assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $browser->getResponse()->headers->get('Content-Type'));
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('target="conversation-bring-to-event-' . SellSwapListItemFixture::SELLSWAP_01 . '"', $content);
        self::assertStringNotContainsString('target="conversation-listing-actions-', $content);
        self::assertStringContainsString("Noted, you&#039;re bringing Puzzle 1 to Puzzle Meetup Prague · Puzzle Meetup #1.", $content);

        // Both events are brought now - both "Bring it" items checked
        $stream = new Crawler($content);
        self::assertCount(2, (new Crawler((string) $stream->filter('turbo-stream template')->first()->html()))->filter('.dropdown-item.disabled'));

        self::assertTrue($this->isBrought(SellSwapListItemFixture::SELLSWAP_01, self::PRAGUE));
        self::assertFalse(self::getContainer()->get(SellSwapListItemRepository::class)->get(SellSwapListItemFixture::SELLSWAP_01)->reserved);
    }

    public function testBringAndReserveAlsoSwapsTheReserveActions(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->bring($browser, self::PRAGUE, stream: true, reserveFor: self::BUYER);

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('target="conversation-listing-actions-' . SellSwapListItemFixture::SELLSWAP_01 . '"', $content);
        self::assertStringContainsString('/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_01 . '/unreserve', $content);
        // Reserved now - the menu offers no second reservation
        self::assertStringNotContainsString('name="reserve_for_player_id"', $content);

        $item = self::getContainer()->get(SellSwapListItemRepository::class)->get(SellSwapListItemFixture::SELLSWAP_01);
        self::assertTrue($item->reserved);
        self::assertSame(self::BUYER, $item->reservedForPlayerId?->toString());
        self::assertTrue($this->isBrought(SellSwapListItemFixture::SELLSWAP_01, self::PRAGUE));
    }

    public function testReservedListingIsOfferedNoReservation(): void
    {
        $browser = self::createClient();
        $this->reserveFor(PlayerFixture::PLAYER_ADMIN);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::CONVERSATION);

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::MENU));
        self::assertCount(0, $crawler->filter(self::MENU . ' input[name="reserve_for_player_id"]'));
        self::assertStringNotContainsString('Bring it and reserve it', $crawler->filter(self::MENU)->text());
    }

    public function testBringAndReserveFromAStaleMenuKeepsTheOtherReservation(): void
    {
        $browser = self::createClient();
        // Reserved for PLAYER_ADMIN in another tab after this menu was rendered
        $this->reserveFor(PlayerFixture::PLAYER_ADMIN);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->bring($browser, self::PRAGUE, stream: true, reserveFor: self::BUYER);

        $this->assertResponseIsSuccessful();
        self::assertTrue($this->isBrought(SellSwapListItemFixture::SELLSWAP_01, self::PRAGUE));
        $item = self::getContainer()->get(SellSwapListItemRepository::class)->get(SellSwapListItemFixture::SELLSWAP_01);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $item->reservedForPlayerId?->toString());
        // The page catches up: Reserve / Sold show the reservation, the menu offers none
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_01 . '/unreserve', $content);
        self::assertStringNotContainsString('name="reserve_for_player_id"', $content);
    }

    public function testReservingForSomebodyOutsideTheConversationIsNotFound(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->bring($browser, self::PRAGUE, stream: true, reserveFor: PlayerFixture::PLAYER_ADMIN);

        $this->assertResponseStatusCodeSame(404);
        self::assertFalse($this->isBrought(SellSwapListItemFixture::SELLSWAP_01, self::PRAGUE));
        self::assertFalse(self::getContainer()->get(SellSwapListItemRepository::class)->get(SellSwapListItemFixture::SELLSWAP_01)->reserved);
    }

    public function testWithoutTurboItRedirectsBackWithAFlash(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->bring($browser, self::PRAGUE, stream: false, referer: 'http://localhost' . self::CONVERSATION);

        $this->assertResponseRedirects('http://localhost' . self::CONVERSATION);
        self::assertTrue($this->isBrought(SellSwapListItemFixture::SELLSWAP_01, self::PRAGUE));

        $crawler = $browser->followRedirect();
        self::assertStringContainsString("Noted, you're bringing Puzzle 1 to Puzzle Meetup Prague · Puzzle Meetup #1.", $crawler->filter('#main-content > .container > .alert-success')->text());
    }

    public function testOnlyTheSellerMayBringIt(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::BUYER);

        $this->bring($browser, self::PRAGUE, stream: true);

        $this->assertResponseStatusCodeSame(403);
        self::assertFalse($this->isBrought(SellSwapListItemFixture::SELLSWAP_01, self::PRAGUE));
    }

    public function testPostFromAnotherSiteIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->bring($browser, self::PRAGUE, stream: true, origin: 'https://evil.example');

        $this->assertResponseStatusCodeSame(403);
        self::assertFalse($this->isBrought(SellSwapListItemFixture::SELLSWAP_01, self::PRAGUE));
    }

    public function testEventTheSellerDoesNotGoToIsNotFound(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->bring($browser, CompetitionFixture::COMPETITION_WJPC_2024, stream: true);

        $this->assertResponseStatusCodeSame(404);
    }

    private function bring(
        KernelBrowser $browser,
        string $competitionId,
        bool $stream,
        null|string $reserveFor = null,
        string $origin = 'http://localhost',
        null|string $referer = null,
    ): void {
        $server = ['HTTP_ORIGIN' => $origin];

        if ($stream) {
            $server['HTTP_ACCEPT'] = 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml';
        }

        if ($referer !== null) {
            $server['HTTP_REFERER'] = $referer;
        }

        $browser->request('POST', self::BRING, [
            '_token' => 'csrf-token',
            'competition_id' => $competitionId,
            'reserve_for_player_id' => $reserveFor ?? '',
            'context' => 'conversation',
            'other_player_id' => self::BUYER,
        ], server: $server);
    }

    /**
     * The locale-aware date every marketplace-at-events label uses (event_dates())
     */
    private function eventDates(string $competitionId): string
    {
        $event = self::getContainer()->get(GetMarketplaceEvents::class)->byId($competitionId);

        return self::getContainer()->get(EventDateFormatter::class)->format($event->dateFrom, $event->dateTo, 'en');
    }

    private function reserveFor(string $playerId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE sell_swap_list_item SET reserved = true, reserved_at = NOW(), reserved_for_player_id = :player WHERE id = :id',
            ['player' => $playerId, 'id' => SellSwapListItemFixture::SELLSWAP_01],
        );
    }

    /**
     * @return list<string>
     */
    private function menuStatements(KernelBrowser $browser): array
    {
        return array_values(array_filter(
            $this->executedSql($browser),
            static fn (string $statement): bool => str_contains($statement, 'bringing_event'),
        ));
    }

    private function isBrought(string $listItemId, string $competitionId): bool
    {
        return self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT 1 FROM sell_swap_list_item_event WHERE sell_swap_list_item_id = :item AND competition_id = :competition',
            ['item' => $listItemId, 'competition' => $competitionId],
        ) !== false;
    }
}
