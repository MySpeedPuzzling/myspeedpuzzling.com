<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\EventJustJoined;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Where "I'm going" leads (docs/features/marketplace/11-events.md, F1 / F2). Czech Nationals 2024 is an in-person
 * marketplace event (+60 days) without a participant list, so "I'm going" joins at once.
 */
final class JoinMarketplaceFollowUpTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string NATIONALS = CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024;
    private const string NATIONALS_PAGE = '/en/events/czech-nationals-2024';
    private const string NATIONALS_PICKER = '/en/events/' . self::NATIONALS . '/what-i-bring?joined=1';
    // The flashes base.html.twig renders at the top of <main>
    private const string FLASH = '#main-content > .container > ';

    public function testMemberWithOffersGoesOnToThePicker(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/join-event/' . self::NATIONALS);

        $this->assertResponseRedirects(self::NATIONALS_PICKER);
        self::assertCount(1, $this->followUpStatements($browser), 'one statement decides F1 / F2');
        self::assertSame([], $this->flashes($browser, EventJustJoined::FLASH));

        $crawler = $browser->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString("You're going to Czech National Championship 2024!", $crawler->filter('.event-offers-picker-joined')->text());
        // The picker's own confirmation instead of the generic flash
        self::assertCount(0, $crawler->filter(self::FLASH . '.alert-success'));
    }

    public function testSelfJoinPostOfAMemberWithOffersGoesOnToThePicker(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/en/join-event/' . self::NATIONALS, ['self_join' => '1']);

        $this->assertResponseRedirects(self::NATIONALS_PICKER);
    }

    public function testPlayerWithoutMembershipGetsTheEventPageWithTheJustJoinedFlash(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/join-event/' . self::NATIONALS);

        $this->assertResponseRedirects(self::NATIONALS_PAGE);
        self::assertCount(1, $this->followUpStatements($browser));
        self::assertStringNotContainsString('published_listing.player_id', implode("\n", $this->followUpStatements($browser)), 'no listings check for a non-member');
        self::assertSame([self::NATIONALS], $this->flashes($browser, EventJustJoined::FLASH));
        self::assertSame(['You have successfully joined the event!'], $this->flashes($browser, 'success'));
    }

    public function testMemberWithoutPublishedOffersGetsTheEventPageWithTheJustJoinedFlash(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE sell_swap_list_item SET published_on_marketplace = false WHERE player_id = :player',
            ['player' => PlayerFixture::PLAYER_ADMIN],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/en/join-event/' . self::NATIONALS, ['self_join' => '1']);

        $this->assertResponseRedirects(self::NATIONALS_PAGE);
        self::assertSame([self::NATIONALS], $this->flashes($browser, EventJustJoined::FLASH));
        self::assertSame(['You have successfully joined the event!'], $this->flashes($browser, 'success'));
    }

    public function testOnlineEventKeepsTodaysRedirectWithoutAQuery(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/join-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);

        $this->assertResponseRedirects('/en/series/euro-jigsaw-jam-series/ejj-69-may-2026');
        self::assertSame([], $this->followUpStatements($browser));
        self::assertSame([], $this->flashes($browser, EventJustJoined::FLASH));
        self::assertSame(['You have successfully joined the event!'], $this->flashes($browser, 'success'));
    }

    public function testSwitchingTheClaimedListRowIsNoNewJoin(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // A new join of a member with offers: on to the picker
        $browser->request('POST', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024, ['self_join' => '1']);
        $this->assertResponseRedirects('/en/events/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/what-i-bring?joined=1');

        // "Change": claiming the organizer's row instead - already going, so just back to the event
        $this->startCountingQueries($browser);
        $browser->request('POST', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024, [
            'participant_id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
        ]);

        $this->assertResponseRedirects('/en/events/wjpc-2024');
        self::assertSame([], $this->followUpStatements($browser));
        self::assertSame([], $this->flashes($browser, EventJustJoined::FLASH));
        self::assertSame(['You have successfully joined the event!'], $this->flashes($browser, 'success'));
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT player_id FROM competition_participant WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
        ));
    }

    public function testSwitchWithoutMembershipGetsNoJustJoinedFlash(): void
    {
        $browser = self::createClient();
        // PLAYER_REGULAR is PARTICIPANT_CONNECTED of WJPC 2024 already
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024, [
            'participant_id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
        ]);

        $this->assertResponseRedirects('/en/events/wjpc-2024');
        self::assertSame([], $this->flashes($browser, EventJustJoined::FLASH));
        self::assertSame(['You have successfully joined the event!'], $this->flashes($browser, 'success'));
    }

    public function testUnapprovedEventIsNoMarketplaceEvent(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/join-event/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertSame([], $this->flashes($browser, EventJustJoined::FLASH));
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('what-i-bring', $location);
    }

    /**
     * @return list<string>
     */
    private function followUpStatements(KernelBrowser $browser): array
    {
        return array_values(array_filter(
            $this->executedSql($browser),
            static fn (string $statement): bool => str_contains($statement, 'has_published_listings'),
        ));
    }

    /**
     * @return list<mixed>
     */
    private function flashes(KernelBrowser $browser, string $type): array
    {
        /** @var Session $session */
        $session = $browser->getRequest()->getSession();

        return array_values($session->getFlashBag()->peek($type));
    }
}
