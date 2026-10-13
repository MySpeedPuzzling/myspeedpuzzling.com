<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Services\OnlineRounds\InMemoryOnlinePresence;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccessLight;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryStudioInviteSignal;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * `POST /api/compete/{token}/heartbeat` - presence, a clock sample, the hint to fetch the state again, the takeover
 * (README §5.3, §5.4, §5.6) and, while live video is available, the open invitation to the studio (contract
 * phase-1b.md §4). Never Postgres once the link is cached.
 */
final class ApiHeartbeatControllerTest extends WebTestCase
{
    use CompeteApiRequests;
    use LiveVideoTesting;
    use QueryCountAssertions;

    private const string NOT_ENTERED_TOKEN = 'NoraNotEnteredToken222';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        self::forgetCachedAccess($this->browser, self::NOT_ENTERED_TOKEN);
        self::resetLiveVideoDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreLiveVideo();

        parent::tearDown();
    }

    public function testABeatIsThePresenceOfTheParticipantInTheirRound(): void
    {
        $before = self::nowMs();
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), self::beat(OnlineRoundsFixture::DEVICE_CONNECTED));

        self::assertResponseIsSuccessful();
        self::assertPrivateNoStore($this->browser);
        self::assertNoCookie($this->browser);

        $answer = self::json($this->browser);
        self::assertSame(['serverNowMs', 'stateHash', 'roundId', 'takenOverBy', 'studioInviteId'], array_keys($answer));
        self::assertIsInt($answer['serverNowMs']);
        self::assertGreaterThanOrEqual($before, $answer['serverNowMs']);
        self::assertSame($this->roundStateHash(), $answer['stateHash']);
        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, $answer['roundId']);
        // The device holding the entry itself
        self::assertNull($answer['takenOverBy']);
        // Not invited to the studio
        self::assertNull($answer['studioInviteId']);

        $presence = $this->presence();
        self::assertSame([OnlineRoundsFixture::PARTICIPANT_CONNECTED], array_keys($presence));
        $beat = $presence[OnlineRoundsFixture::PARTICIPANT_CONNECTED];
        self::assertSame(OnlineRoundsFixture::DEVICE_CONNECTED, $beat['deviceId']);
        self::assertSame(['ok' => true, 'w' => 1920, 'h' => 1080, 'fps' => 30.0], $beat['camera']);
        self::assertSame(['offsetMs' => -12.5, 'rttMs' => 38.0], $beat['clock']);
        self::assertSame(['events' => 0, 'media' => 2], $beat['outbox']);
        self::assertIsInt($beat['lastSeenMs']);
        self::assertGreaterThanOrEqual($before, $beat['lastSeenMs']);
    }

    public function testAnotherDeviceHearsWhichOneHoldsTheEntry(): void
    {
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), self::beat('laptop-second-screen'));

        self::assertResponseIsSuccessful();
        self::assertSame(OnlineRoundsFixture::DEVICE_CONNECTED, self::json($this->browser)['takenOverBy']);
    }

    public function testAPhoneBeforeItsFirstEventIsHeldByNobody(): void
    {
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'heartbeat'), self::beat('phone-a'));

        self::assertResponseIsSuccessful();
        $answer = self::json($this->browser);
        self::assertNull($answer['takenOverBy']);
        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, $answer['roundId']);
        self::assertArrayHasKey(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, $this->presence());
    }

    /**
     * A participant not entered in the round is nobody the round's control room waits for - the answer still helps
     */
    public function testAParticipantNotEnteredInTheRoundLeavesNoPresence(): void
    {
        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $participant = $entityManager->find(CompetitionParticipant::class, OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN);
        self::assertNotNull($participant);
        $participant->assignOnlineToken(self::NOT_ENTERED_TOKEN, new DateTimeImmutable());
        $entityManager->flush();

        self::postJson($this->browser, self::api(self::NOT_ENTERED_TOKEN, 'heartbeat'), self::beat('phone-n'));

        self::assertResponseIsSuccessful();
        $answer = self::json($this->browser);
        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, $answer['roundId']);
        self::assertSame($this->roundStateHash(), $answer['stateHash']);
        self::assertSame([], $this->presence());
    }

    public function testTheHashFollowsTheRoundsClock(): void
    {
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'heartbeat'), self::beat('phone-a'));
        $waiting = self::json($this->browser)['stateHash'];

        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $round = $entityManager->find(CompetitionRound::class, OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        self::assertNotNull($round);
        $round->startStopwatch(new DateTimeImmutable('+10 seconds'));
        $entityManager->flush();
        // The round's hash is shared for ROUND_HASH_TTL_SECONDS - not waited for here
        $this->browser->getContainer()->get(CacheInterface::class)->delete(OnlineAccessLight::ROUND_HASH_KEY_PREFIX . OnlineRoundsFixture::ROUND_ONLINE_SOLO);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'heartbeat'), self::beat('phone-a'));

        $scheduled = self::json($this->browser)['stateHash'];
        self::assertNotSame($waiting, $scheduled);
        self::assertSame(RoundClock::fromRound($round, new DateTimeImmutable())->stateHash(), $scheduled);
    }

    /**
     * Postgres is asked once a minute per link at most - a heartbeat of a cached link costs no statement, with an
     * invitation to the studio open too (its signal is in redis-state)
     */
    public function testAHeartbeatCostsNoStatementOnceTheLinkIsKnown(): void
    {
        (new InMemoryStudioInviteSignal())->raise(OnlineRoundsFixture::ROUND_ONLINE_SOLO, OnlineRoundsFixture::PARTICIPANT_CONNECTED, '018d0060-0000-7000-8000-000000000001', 120);
        $this->startCountingQueries($this->browser);
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), self::beat(OnlineRoundsFixture::DEVICE_CONNECTED));
        self::assertResponseIsSuccessful();
        // The link, the competition's online rounds (the round's clock comes with them), the entry, the online entry
        self::assertCount(4, $this->executedSql($this->browser), 'A heartbeat of a link not cached yet');

        $this->startCountingQueries($this->browser);
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), self::beat(OnlineRoundsFixture::DEVICE_CONNECTED));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->executedSql($this->browser), 'A heartbeat of a cached link never touches Postgres');
        self::assertSame('018d0060-0000-7000-8000-000000000001', self::json($this->browser)['studioInviteId']);
    }

    /**
     * A data message the phone missed costs nothing: its next beat names the invitation waiting for its answer - only
     * its own, only while it waits
     */
    public function testTheBeatCarriesTheOpenInvitationToTheStudio(): void
    {
        $signal = new InMemoryStudioInviteSignal();
        $signal->raise(OnlineRoundsFixture::ROUND_ONLINE_SOLO, OnlineRoundsFixture::PARTICIPANT_CONNECTED, '018d0060-0000-7000-8000-000000000001', 120);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), self::beat(OnlineRoundsFixture::DEVICE_CONNECTED));
        self::assertSame('018d0060-0000-7000-8000-000000000001', self::json($this->browser)['studioInviteId']);

        // Arthur has none
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'heartbeat'), self::beat('phone-a'));
        self::assertNull(self::json($this->browser)['studioInviteId']);

        // Answered or ended: gone
        $signal->clear(OnlineRoundsFixture::ROUND_ONLINE_SOLO, OnlineRoundsFixture::PARTICIPANT_CONNECTED, '018d0060-0000-7000-8000-000000000001');
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), self::beat(OnlineRoundsFixture::DEVICE_CONNECTED));
        self::assertNull(self::json($this->browser)['studioInviteId']);
    }

    /**
     * No media server (production until it has one): the answer is exactly what it was before live video
     */
    public function testWithoutAMediaServerTheBeatIsAsBeforeLiveVideo(): void
    {
        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $this->browser = self::createClient();
        (new InMemoryStudioInviteSignal())->raise(OnlineRoundsFixture::ROUND_ONLINE_SOLO, OnlineRoundsFixture::PARTICIPANT_CONNECTED, '018d0060-0000-7000-8000-000000000001', 120);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), self::beat(OnlineRoundsFixture::DEVICE_CONNECTED));

        self::assertResponseIsSuccessful();
        self::assertSame(['serverNowMs', 'stateHash', 'roundId', 'takenOverBy'], array_keys(self::json($this->browser)));
    }

    public function testRefusals(): void
    {
        self::postJson($this->browser, self::api('NoSuchLinkAnywhere2222', 'heartbeat'), self::beat('phone-a'));
        self::assertRefused($this->browser, 404, 'unknown_link');

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), ['visible' => true]);
        self::assertRefused($this->browser, 422, 'invalid_fields');

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), [1, 2, 3]);
        self::assertRefused($this->browser, 400, 'invalid_json');

        $this->browser->request('POST', self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), content: '{"deviceId": ');
        self::assertRefused($this->browser, 400, 'invalid_json');
    }

    /**
     * @return array<string, mixed>
     */
    private static function beat(string $deviceId): array
    {
        return [
            'deviceId' => $deviceId,
            'roundId' => OnlineRoundsFixture::ROUND_ONLINE_SOLO,
            'visible' => true,
            'camera' => ['ok' => true, 'w' => 1920, 'h' => 1080, 'fps' => 30],
            'detector' => ['ok' => false, 'fps' => null, 'mode' => 'off'],
            'clock' => ['offsetMs' => -12.5, 'rttMs' => 38],
            'outbox' => ['events' => 0, 'media' => 2],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function presence(): array
    {
        $presence = $this->browser->getContainer()->get(InMemoryOnlinePresence::class);

        return $presence->forRound(OnlineRoundsFixture::ROUND_ONLINE_SOLO);
    }

    private function roundStateHash(): string
    {
        $round = $this->browser->getContainer()->get(EntityManagerInterface::class)->find(CompetitionRound::class, OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        self::assertNotNull($round);

        return RoundClock::fromRound($round, new DateTimeImmutable())->stateHash();
    }
}
