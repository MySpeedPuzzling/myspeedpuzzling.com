<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Firebase\JWT\JWT;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Message\AnswerStudioInvite;
use SpeedPuzzling\Web\Message\EndStudioGuest;
use SpeedPuzzling\Web\Message\InviteToStudio;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\TestDouble\LiveKitHttpMock;
use SpeedPuzzling\Web\Value\StudioInviteAnswer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * `POST /-/livekit-webhook` (contract phase-1b.md §5 `livekit_webhook`): server to server, signed by LiveKit (401
 * otherwise), no session; a studio guest's camera device joining and leaving the studio keeps their time on air, every
 * other event, room and identity is acknowledged and left alone - twice the same changes nothing more. The studio's
 * safety (§8): anybody of a participant's in a studio without a joined invitation is removed at once.
 */
final class LiveKitWebhookControllerTest extends WebTestCase
{
    use LiveVideoTesting;

    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string OLIVIA = OnlineRoundsFixture::PARTICIPANT_CONNECTED;
    private const string URL = '/-/livekit-webhook';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        self::resetLiveVideoDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreLiveVideo();

        parent::tearDown();
    }

    public function testTheGuestsTimeOnAir(): void
    {
        $inviteId = $this->joinedInvite();
        $joinedAt = TestClock::timestamp() - 30;

        $this->deliver(self::event('participant_joined', 'studio-' . self::ROUND, 'p:' . self::OLIVIA, $joinedAt));

        self::assertResponseIsSuccessful();
        self::assertSame(['received' => true], $this->json());
        self::assertSame([], $this->browser->getResponse()->headers->getCookies());
        $row = $this->row($inviteId);
        self::assertSame('joined', $row['status']);
        self::assertSame(TestClock::now()->setTimestamp($joinedAt)->format('Y-m-d H:i:s'), $row['on_air_from']);

        // Delivered again: the first time counts
        $this->deliver(self::event('participant_joined', 'studio-' . self::ROUND, 'p:' . self::OLIVIA, $joinedAt + 20));
        self::assertSame(TestClock::now()->setTimestamp($joinedAt)->format('Y-m-d H:i:s'), $this->row($inviteId)['on_air_from']);

        // Back over a new connection: no leave
        $this->deliver(self::event('participant_left', 'studio-' . self::ROUND, 'p:' . self::OLIVIA, $joinedAt + 25, 'DUPLICATE_IDENTITY'));
        self::assertSame('joined', $this->row($inviteId)['status']);

        $this->deliver(self::event('participant_left', 'studio-' . self::ROUND, 'p:' . self::OLIVIA, $joinedAt + 28));
        self::assertResponseIsSuccessful();
        $row = $this->row($inviteId);
        self::assertSame('ended', $row['status']);
        self::assertSame(TestClock::now()->setTimestamp($joinedAt + 28)->format('Y-m-d H:i:s'), $row['on_air_to']);
    }

    public function testEverythingElseIsAcknowledgedAndLeftAlone(): void
    {
        $inviteId = $this->joinedInvite();

        $events = [
            self::event('participant_left', 'round-' . self::ROUND, 'p:' . self::OLIVIA),
            self::event('participant_left', 'studio-' . self::ROUND, 'p:' . self::OLIVIA . ':screen'),
            self::event('participant_left', 'studio-' . self::ROUND, 'm:' . PlayerFixture::PLAYER_ADMIN),
            self::event('participant_left', 'studio-not-a-round', 'p:' . self::OLIVIA),
            self::event('participant_left', 'studio-018d0060-0000-7000-8000-00000000ffff', 'p:' . self::OLIVIA),
            self::event('track_published', 'studio-' . self::ROUND, 'p:' . self::OLIVIA),
            ['event' => 'room_started', 'room' => ['name' => 'studio-' . self::ROUND], 'id' => 'EV_room'],
            ['event' => 'egress_ended', 'id' => 'EV_egress'],
        ];

        foreach ($events as $event) {
            $this->deliver($event);
            self::assertResponseIsSuccessful();
            self::assertSame(['received' => true], $this->json());
        }

        $row = $this->row($inviteId);
        self::assertSame('joined', $row['status']);
        self::assertNull($row['on_air_to']);
    }

    public function testAGuestOnAirStaysInTheStudio(): void
    {
        $this->joinedInvite();
        $logs = $this->captureLogs();

        $this->deliver(self::event('participant_joined', 'studio-' . self::ROUND, 'p:' . self::OLIVIA));
        // Her second screen watches beside her
        $this->deliver(self::event('participant_joined', 'studio-' . self::ROUND, 'p:' . self::OLIVIA . ':screen'));

        self::assertResponseIsSuccessful();
        self::assertSame([], LiveKitHttpMock::requests(), 'nobody removed');
        self::assertSame([], $this->removals($logs));
    }

    /**
     * A studio token alone never puts anybody on air: kept from an invitation the host ended, or never invited - out
     */
    public function testAParticipantInTheStudioWithoutAJoinedInvitationIsRemovedAtOnce(): void
    {
        $inviteId = $this->joinedInvite();
        $bus = $this->browser->getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new EndStudioGuest($inviteId, PlayerFixture::PLAYER_ADMIN));
        LiveKitHttpMock::reset();
        $logs = $this->captureLogs();

        // Back with the token of her ended invitation
        $this->deliver(self::event('participant_joined', 'studio-' . self::ROUND, 'p:' . self::OLIVIA));

        self::assertResponseIsSuccessful();
        self::assertSame(['received' => true], $this->json());
        self::assertSame([[
            'url' => self::LIVEKIT_TEST_API_URL . '/twirp/livekit.RoomService/RemoveParticipant',
            'body' => ['room' => 'studio-' . self::ROUND, 'identity' => 'p:' . self::OLIVIA],
        ]], $this->liveKitCalls());
        $row = $this->row($inviteId);
        self::assertSame('ended', $row['status']);
        self::assertNull($row['on_air_from'], 'never on air');

        $removals = $this->removals($logs);
        self::assertCount(1, $removals);
        self::assertSame(Level::Info, $removals[0]->level);
        self::assertSame(['round_id' => self::ROUND, 'identity' => 'p:' . self::OLIVIA], $removals[0]->context);

        // Never invited, a second screen without its guest, an identity no token of ours carries
        LiveKitHttpMock::reset();
        $this->deliver(self::event('participant_joined', 'studio-' . self::ROUND, 'p:' . OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));
        $this->deliver(self::event('participant_joined', 'studio-' . self::ROUND, 'p:' . self::OLIVIA . ':screen'));
        $this->deliver(self::event('participant_joined', 'studio-' . self::ROUND, 'p:somebody'));

        self::assertSame(
            ['p:' . OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, 'p:' . self::OLIVIA . ':screen', 'p:somebody'],
            array_map(static fn (array $call): mixed => is_array($call['body']) ? ($call['body']['identity'] ?? null) : null, $this->liveKitCalls()),
        );
    }

    public function testLeavingTheHallOrTheStudioOrJoiningTheHallRemovesNobody(): void
    {
        $this->captureLogs();

        $events = [
            self::event('participant_left', 'studio-' . self::ROUND, 'p:' . OnlineRoundsFixture::PARTICIPANT_ANONYMOUS),
            self::event('participant_joined', 'round-' . self::ROUND, 'p:' . OnlineRoundsFixture::PARTICIPANT_ANONYMOUS),
            self::event('participant_joined', 'studio-' . self::ROUND, 'm:' . PlayerFixture::PLAYER_ADMIN),
            self::event('participant_joined', 'studio-' . self::ROUND, 's:9f3a77c0d1e2b4a6'),
            self::event('participant_joined', 'studio-not-a-round', 'p:' . OnlineRoundsFixture::PARTICIPANT_ANONYMOUS),
        ];

        foreach ($events as $event) {
            $this->deliver($event);
            self::assertResponseIsSuccessful();
        }

        self::assertSame([], LiveKitHttpMock::requests());
    }

    public function testARemovalLiveKitRefusesIsAWarning(): void
    {
        $logs = $this->captureLogs();
        LiveKitHttpMock::queue(LiveKitHttpMock::answer(['code' => 'unavailable', 'msg' => 'shutting down'], 503));

        $this->deliver(self::event('participant_joined', 'studio-' . self::ROUND, 'p:' . OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));

        self::assertResponseIsSuccessful();
        $warnings = array_values(array_filter($logs->getRecords(), static fn (LogRecord $record): bool => $record->level === Level::Warning));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('may be on air', $warnings[0]->message);
    }

    public function testOnlyOurServersSignature(): void
    {
        $inviteId = $this->joinedInvite();
        $body = json_encode(self::event('participant_left', 'studio-' . self::ROUND, 'p:' . self::OLIVIA), JSON_THROW_ON_ERROR);

        $this->browser->request('POST', self::URL, server: ['CONTENT_TYPE' => 'application/webhook+json'], content: $body);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'invalid_signature'], $this->json());

        $this->browser->request('POST', self::URL, server: [
            'CONTENT_TYPE' => 'application/webhook+json',
            'HTTP_AUTHORIZATION' => self::signature($body, 'another-secret-that-is-long-enough-for-hs256'),
        ], content: $body);
        self::assertResponseStatusCodeSame(401);

        // Signed for another body
        $this->browser->request('POST', self::URL, server: [
            'CONTENT_TYPE' => 'application/webhook+json',
            'HTTP_AUTHORIZATION' => self::signature('{"event":"room_started"}'),
        ], content: $body);
        self::assertResponseStatusCodeSame(401);

        self::assertSame('joined', $this->row($inviteId)['status']);
        self::assertSame([], $this->browser->getResponse()->headers->getCookies());
    }

    public function testWithoutAMediaServerNothingIsVerified(): void
    {
        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $this->browser = self::createClient();

        $this->deliver(self::event('participant_joined', 'studio-' . self::ROUND, 'p:' . self::OLIVIA));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'video_unavailable'], $this->json());
    }

    private function captureLogs(): TestHandler
    {
        $this->browser->disableReboot();
        $handler = new TestHandler();
        $this->browser->getContainer()->get('logger')->pushHandler($handler);

        return $handler;
    }

    /**
     * @return list<LogRecord>
     */
    private function removals(TestHandler $logs): array
    {
        return array_values(array_filter(
            $logs->getRecords(),
            static fn (LogRecord $record): bool => str_contains($record->message, 'without a joined invitation - removed'),
        ));
    }

    /**
     * @return list<array{url: string, body: mixed}>
     */
    private function liveKitCalls(): array
    {
        return array_map(
            static fn (array $request): array => ['url' => $request['url'], 'body' => json_decode($request['body'], true)],
            LiveKitHttpMock::requests(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(string $name, string $room, string $identity, null|int $createdAt = null, null|string $disconnectReason = null): array
    {
        $participant = ['sid' => 'PA_1', 'identity' => $identity, 'state' => 'ACTIVE'];

        if ($disconnectReason !== null) {
            $participant['disconnectReason'] = $disconnectReason;
        }

        return [
            'event' => $name,
            'room' => ['sid' => 'RM_1', 'name' => $room],
            'participant' => $participant,
            'id' => 'EV_' . bin2hex(random_bytes(4)),
            // The protocol's JSON has 64-bit numbers as strings
            'createdAt' => (string) ($createdAt ?? TestClock::timestamp()),
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function deliver(array $event): void
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        $this->browser->request('POST', self::URL, server: [
            'CONTENT_TYPE' => 'application/webhook+json',
            'HTTP_AUTHORIZATION' => self::signature($body),
        ], content: $body);
    }

    private static function signature(string $body, string $secret = self::LIVEKIT_TEST_SECRET): string
    {
        return JWT::encode([
            'iss' => self::LIVEKIT_TEST_KEY,
            'nbf' => TestClock::timestamp(),
            'exp' => TestClock::timestamp() + 300,
            'sha256' => base64_encode(hash('sha256', $body, true)),
        ], $secret, 'HS256');
    }

    private function joinedInvite(): string
    {
        self::turnRoundVideoOn($this->browser->getContainer()->get(Connection::class));
        $bus = $this->browser->getContainer()->get(MessageBusInterface::class);
        $invite = $bus->dispatch(new InviteToStudio(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, self::ROUND, self::OLIVIA, PlayerFixture::PLAYER_ADMIN))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StudioInvite::class, $invite);
        $bus->dispatch(new AnswerStudioInvite($invite->id->toString(), self::OLIVIA, StudioInviteAnswer::Join));

        return $invite->id->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $inviteId): array
    {
        $row = $this->browser->getContainer()->get(Connection::class)->fetchAssociative('SELECT * FROM studio_invite WHERE id = :id', ['id' => $inviteId]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
