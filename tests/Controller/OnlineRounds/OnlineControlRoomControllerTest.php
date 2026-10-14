<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\OnlineRounds\FakeOnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\InMemoryOnlinePresence;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * The control room of an online round (online_control_room + online_live, docs/features/online-competitions/,
 * stream C2): who may open it (COMPETITION_EDIT + ONLINE_ROUNDS_MANAGE), the page and its state, the JSON every poll
 * reads - the round's clock, the participants with presence, readiness, finish and the latest photo - and the round
 * tools that lead to it.
 */
final class OnlineControlRoomControllerTest extends WebTestCase
{
    use OnlineReviewSeeding;
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'ONLINE_ROUNDS_FOR_ORGANISERS';
    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string ROUND_WITHOUT = '018d0050-0000-0000-0000-0000000001c2';
    private const string PAGE = '/en/online-rounds/' . self::ROUND . '/control';
    private const string LIVE = '/en/online-rounds/' . self::ROUND . '/live.json';
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testGuestsAreSentToSignInAndTheJsonSaysSo(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::PAGE);
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));

        $browser->request('GET', self::LIVE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'sign_in_required'], $this->json($browser));
    }

    public function testAdminsOpenItWhileTheFlagIsOff(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('Control room', $crawler->filter('h1')->text());

        // The page's first state is what online_live answers
        $state = json_decode($crawler->filter('[data-online-control-target="initialState"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsArray($state['round'] ?? null);
        self::assertIsArray($state['participants'] ?? null);
        self::assertSame(self::ROUND, $state['round']['id'] ?? null);
        self::assertSame('waiting', $state['round']['status'] ?? null);
        self::assertSame(['Arthur Anonymous', 'Olivia Online'], array_column($state['participants'], 'name'));

        // Every action is the round stopwatch's own route
        $urls = json_decode((string) $crawler->filter('[data-controller="online-control"]')->attr('data-online-control-urls-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($urls);
        self::assertSame('/en/round-stopwatch/' . self::ROUND . '/schedule', $urls['schedule'] ?? null);
        self::assertSame('/en/round-stopwatch/' . self::ROUND . '/cancel', $urls['cancel'] ?? null);
        self::assertSame('/en/stop-round-stopwatch/' . self::ROUND, $urls['stop'] ?? null);
        self::assertSame(self::LIVE, $urls['live'] ?? null);
        self::assertSame('/-/time', $urls['time'] ?? null);
        self::assertCount(1, $crawler->filter('[data-online-control-target="endDialog"]'), '"End now" asks in the page');
    }

    public function testAnOrganiserOpensItOnlyWhileTheFlagIsOn(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $browser = self::createClient();
        $this->connection($browser)->insert('competition_maintainer', ['competition_id' => self::COMPETITION, 'player_id' => self::ORGANISER]);
        TestingLogin::asPlayer($browser, self::ORGANISER);

        $browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);
        $browser->request('GET', self::LIVE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $this->json($browser));

        self::ensureKernelShutdown();
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ORGANISER);

        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        $browser->request('GET', self::LIVE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
    }

    public function testOtherPlayersAreRefusedWhateverTheFlag(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $browser = self::createClient();
        // Olivia's player - a participant, no organiser
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);
        $browser->request('GET', self::LIVE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testARoundWithoutOnlineTimingHasNoControlRoom(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/online-rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/control');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/online-rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/live.json', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'round_not_online'], $this->json($browser));

        $browser->request('GET', '/en/online-rounds/018d0050-0000-0000-0000-00000000dead/live.json', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'round_not_found'], $this->json($browser));
    }

    public function testTheLiveStateHasTheClockAndEveryParticipant(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->disableReboot();

        $startMs = $this->startOnlineRound(1200);
        $onlineEntry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $tapId = $this->finishTap($onlineEntry, $startMs + 1_100_000);
        $this->finishTap($onlineEntry, $startMs + 1_150_000);
        $undoneId = $this->finishTap($onlineEntry, $startMs + 1_160_000);
        $this->undoTap($onlineEntry, $undoneId, $startMs + 1_165_000);
        $older = $this->evidence($onlineEntry, OnlineMediaKind::Periodic, $startMs + 30_000);
        $latest = $this->evidence($onlineEntry, OnlineMediaKind::Periodic, $startMs + 60_000);
        // Registered, never reported uploaded - not shown
        $this->evidence($onlineEntry, OnlineMediaKind::Periodic, $startMs + 90_000, uploaded: false);
        $nowMs = (int) floor(microtime(true) * 1000);
        $this->presence()->beat(self::ROUND, OnlineRoundsFixture::PARTICIPANT_CONNECTED, [
            'deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED,
            'visible' => true,
            'camera' => ['ok' => true, 'w' => 1920, 'h' => 1080, 'fps' => 30.0],
            'clock' => ['offsetMs' => -12.5, 'rttMs' => 38.0],
            'outbox' => ['events' => 0, 'media' => 2],
            'detector' => null,
        ], $nowMs);
        // A laptop open on the same link - kept apart, never her presence
        $this->presence()->beat(self::ROUND, OnlineRoundsFixture::PARTICIPANT_CONNECTED, ['deviceId' => 'laptop', 'visible' => false], $nowMs - 5_000, holdsEntry: false);

        $browser->request('GET', self::LIVE, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $state = $this->json($browser);

        self::assertIsInt($state['serverNowMs'] ?? null);
        self::assertIsInt($state['asOfMs'] ?? null);
        self::assertTrue($state['presenceAvailable'] ?? null);
        self::assertSame([
            'id' => self::ROUND,
            'name' => 'Online Round',
            'minutesLimit' => 60,
            'status' => 'running',
            'stopwatchStatus' => 'running',
            'startedAtMs' => $startMs,
            'stoppedAtMs' => null,
            'endsAtMs' => $startMs + 3_600_000,
        ], array_diff_key((array) $state['round'], ['stateHash' => true]));
        self::assertIsArray($state['mercure']);
        self::assertSame(['/round-stopwatch/' . self::ROUND], $state['mercure']['topics'] ?? null);

        $participants = $state['participants'];
        self::assertIsArray($participants);
        self::assertSame(['Arthur Anonymous', 'Olivia Online'], array_column($participants, 'name'));

        [$arthur, $olivia] = $participants;
        self::assertIsArray($arthur);
        self::assertFalse($arthur['seen'], 'his phone never made contact');
        self::assertNull($arthur['presence']);
        self::assertNull($arthur['otherDevice']);
        self::assertNull($arthur['finish']);
        self::assertNull($arthur['photo']);

        self::assertIsArray($olivia);
        self::assertSame(OnlineRoundsFixture::PARTICIPANT_CONNECTED, $olivia['participantId']);
        self::assertSame(OnlineRoundsFixture::ENTRY_CONNECTED, $olivia['entryId']);
        self::assertTrue($olivia['seen']);
        self::assertIsInt($olivia['readyAtMs']);
        self::assertSame(OnlineRoundsFixture::DEVICE_CONNECTED, $olivia['activeDeviceId']);
        // Whole floats travel as JSON integers
        self::assertSame(38, $olivia['readyClockRttMs']);
        self::assertSame([
            'lastSeenMs' => $nowMs,
            'deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED,
            'visible' => true,
            'camera' => ['ok' => true, 'w' => 1920, 'h' => 1080, 'fps' => 30],
            'clock' => ['offsetMs' => -12.5, 'rttMs' => 38],
            'outbox' => ['events' => 0, 'media' => 2],
            'detector' => null,
        ], $olivia['presence']);
        // Phase 2: the round's detection (off in the fixture) and no candidate
        self::assertSame('off', $state['detection'] ?? null);
        self::assertArrayHasKey('candidate', $olivia);
        self::assertNull($olivia['candidate']);
        self::assertSame(['deviceId' => 'laptop', 'lastSeenMs' => $nowMs - 5_000], $olivia['otherDevice']);
        // The last tap not taken back
        self::assertIsArray($olivia['finish']);
        self::assertSame($startMs + 1_150_000, $olivia['finish']['momentMs']);
        self::assertNotSame($tapId, $olivia['finish']['eventId']);
        self::assertSame('pending', $olivia['review']);
        self::assertSame([], $olivia['flags']);
        // The latest uploaded periodic photo, with a short-lived URL
        self::assertIsArray($olivia['photo']);
        self::assertSame($latest->id->toString(), $olivia['photo']['mediaId']);
        self::assertNotSame($older->id->toString(), $olivia['photo']['mediaId']);
        self::assertSame($startMs + 60_000, $olivia['photo']['takenMs']);
        self::assertIsString($olivia['photo']['url']);
        self::assertStringStartsWith(FakeOnlineMediaStorage::BASE_URL . '/primary/online/', $olivia['photo']['url']);
        self::assertStringContainsString('X-Fake-Expires=120', $olivia['photo']['url']);
    }

    /**
     * A run is one start of the round clock: after a Reset and a new start, a tap of the run that was is no finish -
     * neither while the new round runs nor while it waits for its start
     */
    public function testATapOfAnEarlierRunIsNoFinish(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->disableReboot();

        $startMs = $this->startOnlineRound(600);
        $onlineEntry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $this->finishTap($onlineEntry, $startMs - 1_800_000);

        $browser->request('GET', self::LIVE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertNoFinish($this->olivia($browser));

        $this->onlineRound()->resetStopwatch();
        $this->entityManager()->flush();

        $browser->request('GET', self::LIVE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertNoFinish($this->olivia($browser));

        // A tap of this run is one
        $startMs = $this->startOnlineRound(300);
        // The requests cleared the entity manager - read it again
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $startMs + 120_000);
        $browser->request('GET', self::LIVE, server: ['HTTP_ACCEPT' => 'application/json']);
        $finish = $this->olivia($browser)['finish'] ?? null;
        self::assertIsArray($finish);
        self::assertSame($startMs + 120_000, $finish['momentMs']);
    }

    /**
     * Phase 2 (detection on): the latest finish candidate of the run with how many the run has - an earlier run's is
     * none -, the round's detection mode and the phone's detector as its heartbeat reports it (types checked again)
     */
    public function testTheLiveStateCarriesTheDetection(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->disableReboot();

        $this->detectionMode(OnlineDetectionMode::On);
        $startMs = $this->startOnlineRound(1200);
        $onlineEntry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        // An earlier run's raise, then two of this run
        $this->finishCandidate($onlineEntry, $startMs - 60_000);
        $this->finishCandidate($onlineEntry, $startMs + 900_000);
        $latestId = $this->finishCandidate($onlineEntry, $startMs + 1_000_000, receivedAtMs: $startMs + 1_000_800);
        $nowMs = (int) floor(microtime(true) * 1000);
        $this->presence()->beat(self::ROUND, OnlineRoundsFixture::PARTICIPANT_CONNECTED, [
            'deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED,
            'visible' => true,
            'detector' => ['ok' => true, 'fps' => 4.8, 'mode' => 'on', 'state' => 'ready', 'inferenceMs' => 92.5, 'view' => 'angled'],
        ], $nowMs);

        $browser->request('GET', self::LIVE, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertSame('on', $this->json($browser)['detection'] ?? null);
        $olivia = $this->olivia($browser);
        self::assertSame(['eventId' => $latestId, 'momentMs' => $startMs + 1_000_000, 'receivedAtMs' => $startMs + 1_000_800, 'count' => 2], $olivia['candidate']);
        self::assertNull($olivia['finish'], 'no tap - the page decides a candidate is a finish (detection on)');
        $presence = $olivia['presence'];
        self::assertIsArray($presence);
        self::assertSame(['ok' => true, 'fps' => 4.8, 'mode' => 'on', 'state' => 'ready', 'inferenceMs' => 92.5, 'view' => 'angled'], $presence['detector']);

        // What a phone made up is not believed
        $this->presence()->beat(self::ROUND, OnlineRoundsFixture::PARTICIPANT_CONNECTED, [
            'deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED,
            'detector' => ['ok' => 'yes', 'fps' => 'fast', 'state' => 'superb', 'view' => 'sideways'],
        ], $nowMs);
        $browser->request('GET', self::LIVE, server: ['HTTP_ACCEPT' => 'application/json']);
        $presence = $this->olivia($browser)['presence'];
        self::assertIsArray($presence);
        self::assertSame(['ok' => null, 'fps' => null, 'mode' => null, 'state' => null, 'inferenceMs' => null, 'view' => null], $presence['detector']);
    }

    /**
     * @param array<string, mixed> $participant
     */
    private static function assertNoFinish(array $participant): void
    {
        self::assertArrayHasKey('finish', $participant);
        self::assertNull($participant['finish']);
    }

    /**
     * @return array<string, mixed>
     */
    private function olivia(KernelBrowser $browser): array
    {
        $participants = $this->json($browser)['participants'] ?? null;
        self::assertIsArray($participants);

        foreach ($participants as $participant) {
            if (is_array($participant) && ($participant['participantId'] ?? null) === OnlineRoundsFixture::PARTICIPANT_CONNECTED) {
                /** @var array<string, mixed> $participant */
                return $participant;
            }
        }

        self::fail('Olivia is not in the control room');
    }

    /**
     * The "Online timing" page of a round that uses it links its control room and its review; a round that does not
     * use online timing has neither
     */
    public function testTheOnlineTimingPageLeadsToTheControlRoomAndTheReview(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/online-rounds/' . self::ROUND . '/settings');

        self::assertResponseIsSuccessful();
        self::assertSame('/en/online-rounds/' . self::ROUND . '/control', $crawler->filter('[data-online-tool="control"]')->attr('href'));
        self::assertSame('Control room', trim($crawler->filter('[data-online-tool="control"]')->text()));
        self::assertSame('/en/online-rounds/' . self::ROUND . '/review', $crawler->filter('[data-online-tool="review"]')->attr('href'));
        self::assertSame('Review', trim($crawler->filter('[data-online-tool="review"]')->text()));

        $this->connection($browser)->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, slug)
             VALUES (:id, :competition, 'Round Without', 30, NOW() + INTERVAL '25 days', 'round-without')",
            ['id' => self::ROUND_WITHOUT, 'competition' => self::COMPETITION],
        );

        $crawler = $browser->request('GET', '/en/online-rounds/' . self::ROUND_WITHOUT . '/settings');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-online-tool="control"]'));
        self::assertCount(0, $crawler->filter('[data-online-tool="review"]'));
    }

    public function testTheRoundToolsLeadToTheControlRoomAndTheReview(): void
    {
        self::bootKernel();
        $request = Request::create('/en/manage-event-rounds/x');
        $request->setLocale('en');
        self::getContainer()->get(RequestStack::class)->push($request);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        $tools = $this->roundTools(self::ROUND, true);
        self::assertSame('/en/online-rounds/' . self::ROUND . '/control?return=/en/x&return_title=Rounds', $tools['online-control'] ?? null);
        self::assertSame('/en/online-rounds/' . self::ROUND . '/review?return=/en/x&return_title=Rounds', $tools['online-review'] ?? null);

        // Any other round - none
        self::assertArrayNotHasKey('online-control', $this->roundTools(OfficialResultsFixture::ROUND_GROUP_A, false));
        self::assertArrayNotHasKey('online-review', $this->roundTools(OfficialResultsFixture::ROUND_GROUP_A, true));
    }

    /**
     * @return array<string, string> tool => href
     */
    private function roundTools(string $roundId, bool $isOnline): array
    {
        $html = self::getContainer()->get(Environment::class)->render('official_results/_round_tool_links.html.twig', [
            'round_id' => $roundId,
            'is_online' => $isOnline,
            'return_url' => '/en/x',
            'return_title' => 'Rounds',
        ]);

        $tools = [];

        foreach ((new Crawler('<div>' . $html . '</div>'))->filter('[data-round-tool]') as $link) {
            \assert($link instanceof \DOMElement);
            $tools[$link->getAttribute('data-round-tool')] = urldecode($link->getAttribute('href'));
        }

        return $tools;
    }

    private function presence(): InMemoryOnlinePresence
    {
        return self::getContainer()->get(InMemoryOnlinePresence::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $browser): array
    {
        $decoded = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function connection(KernelBrowser $browser): Connection
    {
        $connection = $browser->getContainer()->get(Connection::class);

        return $connection;
    }
}
