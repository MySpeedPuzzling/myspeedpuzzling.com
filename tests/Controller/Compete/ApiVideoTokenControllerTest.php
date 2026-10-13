<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Message\AnswerStudioInvite;
use SpeedPuzzling\Web\Message\InviteToStudio;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Value\StudioInviteAnswer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * `POST /api/compete/{token}/video-token` (contract phase-1b.md §3, §5): the participant's access token to the hall or
 * the studio of their current round - the camera role for the device holding their entry only, a second screen
 * watching, the studio only with a joined invitation; `video_unavailable` without a media server or with the round's
 * video off.
 */
final class ApiVideoTokenControllerTest extends WebTestCase
{
    use CompeteApiRequests;
    use LiveVideoTesting;

    private const string NOT_ENTERED_TOKEN = 'NoraNotEnteredToken222';
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string OLIVIA = OnlineRoundsFixture::PARTICIPANT_CONNECTED;

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

    public function testTheDeviceHoldingTheEntryPublishesItsCameraToTheHall(): void
    {
        self::turnRoundVideoOn($this->connection());

        $this->ask(OnlineRoundsFixture::TOKEN_CONNECTED, ['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED, 'room' => 'hall', 'role' => 'camera']);

        self::assertResponseIsSuccessful();
        self::assertPrivateNoStore($this->browser);
        self::assertNoCookie($this->browser);
        $answer = self::json($this->browser);
        self::assertSame(['serverNowMs', 'url', 'token', 'identity', 'room'], array_keys($answer));
        self::assertSame(self::LIVEKIT_TEST_PUBLIC_URL, $answer['url']);
        self::assertSame('p:' . self::OLIVIA, $answer['identity']);
        self::assertSame('round-' . self::ROUND, $answer['room']);

        self::assertIsString($answer['token']);
        $claims = self::liveKitClaims($answer['token']);
        self::assertSame(self::LIVEKIT_TEST_KEY, $claims['iss']);
        self::assertSame('p:' . self::OLIVIA, $claims['sub']);
        self::assertSame([
            'room' => 'round-' . self::ROUND,
            'roomJoin' => true,
            'canPublish' => true,
            'canPublishSources' => ['camera'],
            'canSubscribe' => true,
            'canPublishData' => false,
        ], $claims['video']);

        // The role is the camera's when not named
        $this->ask(OnlineRoundsFixture::TOKEN_CONNECTED, ['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED, 'room' => 'hall']);
        self::assertResponseIsSuccessful();
        self::assertSame('p:' . self::OLIVIA, self::json($this->browser)['identity']);
    }

    public function testAnotherDeviceOnlyWatches(): void
    {
        self::turnRoundVideoOn($this->connection());

        $this->ask(OnlineRoundsFixture::TOKEN_CONNECTED, ['deviceId' => 'laptop-1', 'room' => 'hall', 'role' => 'camera']);
        self::assertRefused($this->browser, 409, 'not_active_device');

        $this->ask(OnlineRoundsFixture::TOKEN_CONNECTED, ['deviceId' => 'laptop-1', 'room' => 'hall', 'role' => 'screen']);
        self::assertResponseIsSuccessful();
        $answer = self::json($this->browser);
        self::assertSame('p:' . self::OLIVIA . ':screen', $answer['identity']);
        self::assertIsString($answer['token']);
        $video = self::liveKitClaims($answer['token'])['video'];
        self::assertIsArray($video);
        self::assertFalse($video['canPublish']);
        self::assertTrue($video['canSubscribe']);

        // Arthur's phone never said it is ready - no device holds his entry yet
        $this->ask(OnlineRoundsFixture::TOKEN_ANONYMOUS, ['deviceId' => 'phone-a', 'room' => 'hall', 'role' => 'camera']);
        self::assertRefused($this->browser, 409, 'not_active_device');
    }

    public function testTheStudioOnlyWithAJoinedInvitation(): void
    {
        self::turnRoundVideoOn($this->connection());
        $camera = ['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED, 'room' => 'studio', 'role' => 'camera'];

        $this->ask(OnlineRoundsFixture::TOKEN_CONNECTED, $camera);
        self::assertRefused($this->browser, 409, 'studio_not_joined');

        $inviteId = $this->invite(self::OLIVIA);
        $this->ask(OnlineRoundsFixture::TOKEN_CONNECTED, $camera);
        self::assertRefused($this->browser, 409, 'studio_not_joined');

        $this->bus()->dispatch(new AnswerStudioInvite($inviteId, self::OLIVIA, StudioInviteAnswer::Join));
        $this->ask(OnlineRoundsFixture::TOKEN_CONNECTED, $camera);

        self::assertResponseIsSuccessful();
        $answer = self::json($this->browser);
        self::assertSame('studio-' . self::ROUND, $answer['room']);
        self::assertIsString($answer['token']);
        $video = self::liveKitClaims($answer['token'])['video'];
        self::assertIsArray($video);
        self::assertSame(['camera', 'microphone'], $video['canPublishSources']);

        // Arthur has no invitation of his own
        $this->ask(OnlineRoundsFixture::TOKEN_ANONYMOUS, ['deviceId' => 'phone-a', 'room' => 'studio', 'role' => 'screen']);
        self::assertRefused($this->browser, 409, 'studio_not_joined');
    }

    public function testTheRoundsVideoMustBeOn(): void
    {
        $this->ask(OnlineRoundsFixture::TOKEN_CONNECTED, ['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED, 'room' => 'hall']);

        self::assertRefused($this->browser, 409, 'video_unavailable');
    }

    public function testWithoutAMediaServerNothingIsMinted(): void
    {
        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $this->browser = self::createClient();
        self::turnRoundVideoOn($this->connection());

        $this->ask(OnlineRoundsFixture::TOKEN_CONNECTED, ['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED, 'room' => 'hall']);

        self::assertRefused($this->browser, 409, 'video_unavailable');
    }

    public function testRefusals(): void
    {
        self::turnRoundVideoOn($this->connection());

        $this->ask('NoSuchLinkAnywhere2222', ['deviceId' => 'phone-a', 'room' => 'hall']);
        self::assertRefused($this->browser, 404, 'unknown_link');

        foreach ([['room' => 'hall'], ['deviceId' => 'phone-a'], ['deviceId' => 'phone-a', 'room' => 'kitchen'], ['deviceId' => 'phone-a', 'room' => 'hall', 'role' => 'director'], ['deviceId' => '', 'room' => 'hall']] as $body) {
            $this->ask(OnlineRoundsFixture::TOKEN_CONNECTED, $body);
            self::assertRefused($this->browser, 422, 'invalid_fields');
        }

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'video-token'), [1, 2]);
        self::assertRefused($this->browser, 400, 'invalid_json');

        // Nora is not entered in the round
        $nora = $this->connection()->executeStatement('UPDATE competition_participant SET online_token = :token WHERE id = :id', [
            'token' => self::NOT_ENTERED_TOKEN,
            'id' => OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN,
        ]);
        self::assertSame(1, $nora);
        $this->ask(self::NOT_ENTERED_TOKEN, ['deviceId' => 'phone-n', 'room' => 'hall', 'role' => 'screen']);
        self::assertRefused($this->browser, 409, 'not_entered');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function ask(string $token, array $body): void
    {
        self::postJson($this->browser, self::api($token, 'video-token'), $body);
    }

    private function invite(string $participantId): string
    {
        $invite = $this->bus()->dispatch(new InviteToStudio(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, self::ROUND, $participantId, PlayerFixture::PLAYER_ADMIN))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StudioInvite::class, $invite);

        return $invite->id->toString();
    }

    private function bus(): MessageBusInterface
    {
        return $this->browser->getContainer()->get(MessageBusInterface::class);
    }

    private function connection(): Connection
    {
        return $this->browser->getContainer()->get(Connection::class);
    }
}
