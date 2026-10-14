<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\AnswerStudioInvite;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccessLight;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryStudioInviteSignal;
use SpeedPuzzling\Web\Tests\TestDouble\LiveKitHttpMock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\StudioInviteAnswer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * "Invite to the studio" and "Thank you" (contract phase-1b.md §4, §5 `online_studio_invite`, `online_studio_end`): the
 * invitation's rules answered in JSON, and what follows the commit - the data message to the participant's phone and
 * the heartbeat's signal after an invitation, the guest's publishing taken away in the studio after an end. A LiveKit
 * that fails never fails the request.
 */
final class OnlineStudioControllersTest extends WebTestCase
{
    use LiveVideoTesting;

    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string OLIVIA = OnlineRoundsFixture::PARTICIPANT_CONNECTED;
    private const string INVITE = '/en/online-rounds/' . self::ROUND . '/studio-invites';

    protected function setUp(): void
    {
        self::resetLiveVideoDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreLiveVideo();

        parent::tearDown();
    }

    public function testAnInvitationReachesTheParticipantsPhone(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::turnRoundVideoOn($this->connection($browser));

        $this->post($browser, self::INVITE, ['participantId' => self::OLIVIA]);

        self::assertResponseIsSuccessful();
        $invite = $this->json($browser)['invite'] ?? null;
        self::assertIsArray($invite);
        self::assertSame(['id', 'participantId', 'status', 'invitedAtMs', 'expiresAtMs', 'onAirFromMs', 'onAirToMs'], array_keys($invite));
        self::assertIsString($invite['id']);
        self::assertSame(self::OLIVIA, $invite['participantId']);
        self::assertSame('invited', $invite['status']);
        self::assertIsInt($invite['invitedAtMs']);
        self::assertSame($invite['invitedAtMs'] + 120_000, $invite['expiresAtMs']);
        self::assertNull($invite['onAirFromMs']);

        // The data message to Olivia's camera device in the hall
        $requests = LiveKitHttpMock::requests();
        self::assertCount(1, $requests);
        self::assertSame(self::LIVEKIT_TEST_API_URL . '/twirp/livekit.RoomService/SendData', $requests[0]['url']);
        $body = json_decode($requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('round-' . self::ROUND, $body['room']);
        self::assertSame(['p:' . self::OLIVIA], $body['destination_identities']);
        self::assertSame('studio', $body['topic']);
        self::assertSame('RELIABLE', $body['kind']);
        self::assertIsString($body['data']);
        self::assertSame(['inviteId' => $invite['id']], json_decode((string) base64_decode($body['data'], true), true));

        // ... and the heartbeat's signal: Olivia's next beat carries it
        self::assertSame($invite['id'], (new InMemoryStudioInviteSignal())->open(self::ROUND, self::OLIVIA));
        $browser->getContainer()->get(CacheInterface::class)->delete(OnlineAccessLight::cacheKey(OnlineRoundsFixture::TOKEN_CONNECTED));
        $browser->request('POST', '/api/compete/' . OnlineRoundsFixture::TOKEN_CONNECTED . '/heartbeat', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame($invite['id'], $this->json($browser)['studioInviteId']);
    }

    public function testAMessageThatDoesNotReachThePhoneFailsNothing(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::turnRoundVideoOn($this->connection($browser));
        LiveKitHttpMock::queue(LiveKitHttpMock::answer(['code' => 'unavailable', 'msg' => 'the server is shutting down'], 503));

        $this->post($browser, self::INVITE, ['participantId' => self::OLIVIA]);

        self::assertResponseIsSuccessful();
        self::assertSame('invited', $this->connection($browser)->fetchOne('SELECT status FROM studio_invite WHERE participant_id = :id', ['id' => self::OLIVIA]));
    }

    public function testTheInvitationsRules(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $this->post($browser, self::INVITE, ['participantId' => self::OLIVIA]);
        self::assertRefusal($browser, 409, 'video_unavailable');

        self::turnRoundVideoOn($this->connection($browser));
        $this->post($browser, self::INVITE, ['participantId' => self::OLIVIA]);
        self::assertResponseIsSuccessful();
        $first = $this->json($browser)['invite'] ?? null;
        self::assertIsArray($first);

        $this->post($browser, self::INVITE, ['participantId' => strtoupper(self::OLIVIA)]);
        self::assertRefusal($browser, 409, 'already_invited');
        $open = $this->json($browser)['invite'] ?? null;
        self::assertIsArray($open);
        self::assertSame($first['id'], $open['id']);

        $this->post($browser, self::INVITE, ['participantId' => OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN]);
        self::assertRefusal($browser, 409, 'not_entered');

        $this->post($browser, self::INVITE, ['participantId' => OfficialResultsFixture::PARTICIPANT_ANNA]);
        self::assertRefusal($browser, 404, 'participant_not_found');

        foreach ([[], ['participantId' => 'olivia'], ['participantId' => 7]] as $body) {
            $this->post($browser, self::INVITE, $body);
            self::assertRefusal($browser, 422, 'invalid_fields');
        }

        self::assertSame(1, $this->connection($browser)->fetchOne('SELECT COUNT(*) FROM studio_invite'));
    }

    public function testOnlyTheRoundsOrganisersWithTheCsrfHeader(): void
    {
        $browser = self::createClient();
        self::turnRoundVideoOn($this->connection($browser));

        $this->post($browser, self::INVITE, ['participantId' => self::OLIVIA]);
        self::assertRefusal($browser, 401, 'sign_in_required');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $this->post($browser, self::INVITE, ['participantId' => self::OLIVIA]);
        self::assertRefusal($browser, 403, 'forbidden');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->post($browser, self::INVITE, ['participantId' => self::OLIVIA], origin: 'https://elsewhere.example');
        self::assertRefusal($browser, 403, 'invalid_csrf_token');

        self::assertSame([], LiveKitHttpMock::requests());
        self::assertSame(0, $this->connection($browser)->fetchOne('SELECT COUNT(*) FROM studio_invite'));
    }

    public function testTheThankYouTakesTheGuestsPublishingAway(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::turnRoundVideoOn($this->connection($browser));
        $inviteId = $this->invite($browser);
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new AnswerStudioInvite($inviteId, self::OLIVIA, StudioInviteAnswer::Join));
        LiveKitHttpMock::reset();

        $this->post($browser, self::end($inviteId), []);

        self::assertResponseIsSuccessful();
        $invite = $this->json($browser)['invite'] ?? null;
        self::assertIsArray($invite);
        self::assertSame('ended', $invite['status']);
        self::assertIsInt($invite['onAirToMs']);

        // Off air at once (her tracks unpublished for everybody), then out of the studio - a studio token alone never
        // brings her back (contract phase-1b.md §8)
        $requests = LiveKitHttpMock::requests();
        self::assertCount(2, $requests);
        self::assertSame(self::LIVEKIT_TEST_API_URL . '/twirp/livekit.RoomService/UpdateParticipant', $requests[0]['url']);
        self::assertSame([
            'room' => 'studio-' . self::ROUND,
            'identity' => 'p:' . self::OLIVIA,
            'permission' => ['can_subscribe' => true, 'can_publish' => false, 'can_publish_data' => false, 'can_publish_sources' => [], 'hidden' => false],
        ], json_decode($requests[0]['body'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(self::LIVEKIT_TEST_API_URL . '/twirp/livekit.RoomService/RemoveParticipant', $requests[1]['url']);
        self::assertSame([
            'room' => 'studio-' . self::ROUND,
            'identity' => 'p:' . self::OLIVIA,
        ], json_decode($requests[1]['body'], true, flags: JSON_THROW_ON_ERROR));

        // Again: nothing more to take away
        LiveKitHttpMock::reset();
        $this->post($browser, self::end($inviteId), []);
        self::assertResponseIsSuccessful();
        self::assertSame([], LiveKitHttpMock::requests());
    }

    public function testEndingAnInvitationNobodyAnsweredWithdrawsItAndClearsTheSignal(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::turnRoundVideoOn($this->connection($browser));
        $inviteId = $this->invite($browser);
        LiveKitHttpMock::reset();

        $this->post($browser, self::end($inviteId), []);

        self::assertResponseIsSuccessful();
        $invite = $this->json($browser)['invite'] ?? null;
        self::assertIsArray($invite);
        self::assertSame('ended', $invite['status']);
        self::assertSame([], LiveKitHttpMock::requests(), 'never on air - nothing to take away');
        self::assertNull((new InMemoryStudioInviteSignal())->open(self::ROUND, self::OLIVIA));
    }

    public function testAGuestLiveKitCannotReachIsStillThanked(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::turnRoundVideoOn($this->connection($browser));
        $inviteId = $this->invite($browser);
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new AnswerStudioInvite($inviteId, self::OLIVIA, StudioInviteAnswer::Join));
        LiveKitHttpMock::queue(LiveKitHttpMock::answer(['code' => 'internal', 'msg' => 'boom'], 500));

        $this->post($browser, self::end($inviteId), []);

        self::assertResponseIsSuccessful();
        self::assertSame('ended', $this->connection($browser)->fetchOne('SELECT status FROM studio_invite WHERE id = :id', ['id' => $inviteId]));
    }

    /**
     * The permission change fails, the removal works: off air anyway. The guest already gone: nothing to remove.
     */
    public function testTheGuestIsRemovedEvenWhenThePermissionChangeFails(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::turnRoundVideoOn($this->connection($browser));
        $inviteId = $this->invite($browser);
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new AnswerStudioInvite($inviteId, self::OLIVIA, StudioInviteAnswer::Join));
        LiveKitHttpMock::reset();
        LiveKitHttpMock::queue(LiveKitHttpMock::answer(['code' => 'internal', 'msg' => 'boom'], 500));

        $this->post($browser, self::end($inviteId), []);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['UpdateParticipant', 'RemoveParticipant'],
            array_map(static fn (array $request): string => basename($request['url']), LiveKitHttpMock::requests()),
        );
    }

    public function testAGuestNoLongerInTheStudioIsNotRemoved(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::turnRoundVideoOn($this->connection($browser));
        $inviteId = $this->invite($browser);
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new AnswerStudioInvite($inviteId, self::OLIVIA, StudioInviteAnswer::Join));
        LiveKitHttpMock::reset();
        LiveKitHttpMock::queue(LiveKitHttpMock::answer(['code' => 'not_found', 'msg' => 'participant not found'], 404));

        $this->post($browser, self::end($inviteId), []);

        self::assertResponseIsSuccessful();
        self::assertSame(['UpdateParticipant'], array_map(static fn (array $request): string => basename($request['url']), LiveKitHttpMock::requests()));
        self::assertSame('ended', $this->connection($browser)->fetchOne('SELECT status FROM studio_invite WHERE id = :id', ['id' => $inviteId]));
    }

    public function testEndingNeedsAnInvitationOfTheRoundAndAMediaServer(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::turnRoundVideoOn($this->connection($browser));

        $this->post($browser, self::end('018d0060-0000-7000-8000-00000000ffff'), []);
        self::assertRefusal($browser, 404, 'invite_not_found');

        $inviteId = $this->invite($browser);
        // The same invitation through another round of another event: not found
        $this->post($browser, '/en/online-rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/studio-invites/' . $inviteId . '/end', []);
        self::assertResponseStatusCodeSame(404);

        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $this->post($browser, self::end($inviteId), []);
        self::assertRefusal($browser, 409, 'video_unavailable');

        $this->post($browser, self::INVITE, ['participantId' => self::OLIVIA]);
        self::assertRefusal($browser, 409, 'video_unavailable');
    }

    private static function end(string $inviteId): string
    {
        return self::INVITE . '/' . $inviteId . '/end';
    }

    private function invite(KernelBrowser $browser): string
    {
        $this->post($browser, self::INVITE, ['participantId' => self::OLIVIA]);
        self::assertResponseIsSuccessful();
        $invite = $this->json($browser)['invite'] ?? null;
        self::assertIsArray($invite);
        self::assertIsString($invite['id']);

        return $invite['id'];
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    private function assertRefusal(KernelBrowser $browser, int $status, string $error): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame($error, $this->json($browser)['error'] ?? null);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(KernelBrowser $browser, string $url, array $body, string $origin = 'http://localhost'): void
    {
        $browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
            'HTTP_REFERER' => $origin . '/',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR));
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
        return $browser->getContainer()->get(Connection::class);
    }
}
