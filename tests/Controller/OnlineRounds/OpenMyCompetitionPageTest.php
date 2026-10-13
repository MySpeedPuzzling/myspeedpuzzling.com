<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\ConnectCompetitionParticipant;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetEventAttendance;
use SpeedPuzzling\Web\Results\EventAttendance;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Open my competition page" on the event page (docs/features/online-competitions/README.md §5.4, stream C1): a
 * signed-in player connected to a participant of an event with online timing gets their personal link in the
 * taking-part card - read by GetEventAttendance in the statement it runs anyway (the same number of statements with or
 * without the button). The Online Test Cup is a draft: published here, the taking-part card shows only on a public page.
 */
final class OpenMyCompetitionPageTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string PAGE = '/en/events/online-test-cup';
    private const string BUTTON = '[data-online-competition-page]';

    public function testTheConnectedParticipantGetsTheirPersonalLink(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);
        $this->publishTheCup($browser);

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $button = $crawler->filter('#taking-part ' . self::BUTTON);
        self::assertCount(1, $button);
        self::assertSame('http://localhost/en/compete/' . OnlineRoundsFixture::TOKEN_CONNECTED, $button->attr('href'));
        // A full page load: the participant page lives without Turbo
        self::assertSame('false', $button->attr('data-turbo'));
        self::assertSame('Open my competition page', trim($button->text()));
    }

    public function testNobodyElseGetsIt(): void
    {
        $browser = self::createClient();
        $this->publishTheCup($browser);

        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $browser->getCrawler()->filter(self::BUTTON), 'a visitor');

        // The organiser, no participant
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', self::PAGE);
        self::assertCount(0, $browser->getCrawler()->filter(self::BUTTON), 'the organiser');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', self::PAGE);
        self::assertCount(0, $browser->getCrawler()->filter(self::BUTTON), 'another player');
    }

    /**
     * The attack the confirmation closes: anybody signed in may pick an unclaimed name from the organiser's list (the
     * "I'm going" picker) - that must never hand them the participant's personal link
     */
    public function testClaimingAParticipantFromTheListHandsOutNoLink(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->publishTheCup($browser);

        // Arthur Anonymous is unclaimed and holds a link the organiser hands out
        $browser->request('GET', '/en/join-event/' . OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP);
        $browser->request('POST', '/en/join-event/' . OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, ['participant_id' => OnlineRoundsFixture::PARTICIPANT_ANONYMOUS]);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $this->connection($browser)->fetchOne(
            'SELECT player_id FROM competition_participant WHERE id = :id',
            ['id' => OnlineRoundsFixture::PARTICIPANT_ANONYMOUS],
        ), 'claimed');

        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $browser->getCrawler()->filter(self::BUTTON));
        self::assertStringNotContainsString(OnlineRoundsFixture::TOKEN_ANONYMOUS, (string) $browser->getResponse()->getContent(), 'the token is nowhere in the page');
    }

    /**
     * Connected by the organiser (the participants sheet, an import): the link comes from the organiser, and once the
     * player opened it signed in - the page connects (confirms) by itself - the event page hands it to them too
     */
    public function testAPlayerTheOrganiserConnectedGetsTheButtonOnceTheyOpenedTheirLinkSignedIn(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->publishTheCup($browser);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ConnectCompetitionParticipant(
            OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP,
            PlayerFixture::PLAYER_WITH_FAVORITES,
            OnlineRoundsFixture::PARTICIPANT_ANONYMOUS,
        ));

        $browser->request('GET', self::PAGE);
        self::assertCount(0, $browser->getCrawler()->filter(self::BUTTON), 'not before they showed they hold the link');

        // Their own link, signed in: the page posts the connect itself ("auto") - it confirms the link
        $crawler = $browser->request('GET', '/en/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS);
        $connect = json_decode((string) $crawler->filter('.compete')->attr('data-compete-connect-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($connect);
        self::assertSame('auto', $connect['status'] ?? null);
        self::assertSame('/en/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS . '/connect', $connect['url'] ?? null);

        $browser->request('POST', '/en/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS . '/connect', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: '{}');
        $answer = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertSame('already_connected', $answer['status'] ?? null);

        $crawler = $browser->request('GET', self::PAGE);
        self::assertSame('http://localhost/en/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS, $crawler->filter('#taking-part ' . self::BUTTON)->attr('href'));

        // Confirmed: the participant page shows them connected, nothing more to post
        $crawler = $browser->request('GET', '/en/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS);
        $connect = json_decode((string) $crawler->filter('.compete')->attr('data-compete-connect-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($connect);
        self::assertSame('already_connected', $connect['status'] ?? null);
    }

    public function testAPlayerWhoJoinsByThemselvesGetsTheirLinkRightAway(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->publishTheCup($browser);

        $browser->request('POST', '/en/join-event/' . OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, ['self_join' => '1']);

        $token = $this->connection($browser)->fetchOne(
            'SELECT online_token FROM competition_participant WHERE competition_id = :competition AND player_id = :player AND deleted_at IS NULL',
            ['competition' => OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, 'player' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );
        self::assertIsString($token);

        $crawler = $browser->request('GET', self::PAGE);
        self::assertSame('http://localhost/en/compete/' . $token, $crawler->filter('#taking-part ' . self::BUTTON)->attr('href'));
    }

    public function testTheButtonCostsNoStatement(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->publishTheCup($browser);
        $without = $this->measure($browser);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $with = $this->measure($browser);

        self::assertCount(1, $browser->getCrawler()->filter(self::BUTTON));
        self::assertSame($without, $with, implode("\n", $this->executedSql($browser)));
    }

    public function testTheLinkNeedsAGoingParticipantWithALinkAndARoundWithOnlineTiming(): void
    {
        $browser = self::createClient();
        $competition = self::getContainer()->get(GetCompetitionEvents::class)->byId(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP);
        $connection = $this->connection($browser);

        $attendance = $this->attendance(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(OnlineRoundsFixture::PARTICIPANT_CONNECTED, $attendance->onlineParticipantId);
        self::assertSame(OnlineRoundsFixture::TOKEN_CONNECTED, $attendance->onlineToken);
        self::assertTrue($attendance->isGoing);
        self::assertTrue($competition->isOnline);

        self::assertNull($this->attendance(PlayerFixture::PLAYER_ADMIN)->onlineToken);
        self::assertNull($this->attendance(null)->onlineToken);

        // No round uses online timing any more
        $connection->executeStatement('DELETE FROM online_round WHERE id = :id', ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO]);
        self::assertNull($this->attendance(PlayerFixture::PLAYER_REGULAR)->onlineToken);
        self::assertTrue($this->attendance(PlayerFixture::PLAYER_REGULAR)->isGoing);
    }

    public function testARemovedParticipantHasNoLinkToOpen(): void
    {
        $browser = self::createClient();
        $this->connection($browser)->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE id = :id',
            ['id' => OnlineRoundsFixture::PARTICIPANT_CONNECTED],
        );

        $attendance = $this->attendance(PlayerFixture::PLAYER_REGULAR);
        self::assertNull($attendance->onlineToken);
        self::assertNull($attendance->onlineParticipantId);
        self::assertFalse($attendance->isGoing);
    }

    public function testAnEventThatManagesRegistrationReadsTheLinkInItsOwnStatement(): void
    {
        $browser = self::createClient();
        $this->connection($browser)->executeStatement(
            'UPDATE competition SET registration_managed = true, capacity = 10 WHERE id = :id',
            ['id' => OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP],
        );

        $attendance = $this->attendance(PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($attendance->registration);
        self::assertSame(OnlineRoundsFixture::TOKEN_CONNECTED, $attendance->onlineToken);

        // On the waitlist: not going, no link to open
        $this->connection($browser)->executeStatement(
            "UPDATE competition_participant SET registration_status = 'waitlisted' WHERE id = :id",
            ['id' => OnlineRoundsFixture::PARTICIPANT_CONNECTED],
        );
        self::assertNull($this->attendance(PlayerFixture::PLAYER_REGULAR)->onlineToken);
    }

    public function testAnInPersonEventReadsNoLink(): void
    {
        self::createClient();
        $wjpc = self::getContainer()->get(GetCompetitionEvents::class)->byId(CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertFalse($wjpc->isOnline);

        // PLAYER_REGULAR is going to WJPC 2024
        $attendance = self::getContainer()->get(GetEventAttendance::class)->forEvent($wjpc, PlayerFixture::PLAYER_REGULAR, true);
        self::assertTrue($attendance->isGoing);
        self::assertNull($attendance->onlineToken);
        self::assertNull($attendance->onlineParticipantId);
    }

    private function attendance(null|string $playerId): EventAttendance
    {
        $competition = self::getContainer()->get(GetCompetitionEvents::class)->byId(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP);

        return self::getContainer()->get(GetEventAttendance::class)->forEvent($competition, $playerId, true);
    }

    private function measure(KernelBrowser $browser): int
    {
        // The first request warms the caches
        $browser->request('GET', self::PAGE);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    /**
     * Test Cup #1 stays a draft - the published cups (from #2 on) show the taking-part card like any event
     */
    private function publishTheCup(KernelBrowser $browser): void
    {
        $this->connection($browser)->executeStatement(
            'UPDATE competition SET is_draft = false WHERE id = :id',
            ['id' => OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP],
        );
    }

    private function connection(KernelBrowser $browser): Connection
    {
        $connection = $browser->getContainer()->get(Connection::class);

        return $connection;
    }
}
