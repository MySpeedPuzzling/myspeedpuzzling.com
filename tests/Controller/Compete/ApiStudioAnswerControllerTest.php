<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Message\InviteToStudio;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryStudioInviteSignal;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * `POST /api/compete/{token}/studio-invites/{inviteId}` (contract phase-1b.md §4, §5): the participant joins or
 * declines an invitation to the studio from their personal link - only their own, only while it waits; the heartbeat's
 * signal goes with the answer.
 */
final class ApiStudioAnswerControllerTest extends WebTestCase
{
    use CompeteApiRequests;
    use LiveVideoTesting;

    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string OLIVIA = OnlineRoundsFixture::PARTICIPANT_CONNECTED;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        self::forgetCachedAccess($this->browser);
        self::resetLiveVideoDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreLiveVideo();

        parent::tearDown();
    }

    public function testJoiningPutsTheParticipantOnAirAndTheSignalGoes(): void
    {
        self::turnRoundVideoOn($this->connection());
        $inviteId = $this->invite(self::OLIVIA);
        (new InMemoryStudioInviteSignal())->raise(self::ROUND, self::OLIVIA, $inviteId, 120);

        $this->answer(OnlineRoundsFixture::TOKEN_CONNECTED, $inviteId, 'join');

        self::assertResponseIsSuccessful();
        self::assertPrivateNoStore($this->browser);
        self::assertNoCookie($this->browser);
        $answer = self::json($this->browser);
        self::assertSame(['serverNowMs', 'studio'], array_keys($answer));
        self::assertSame(['inviteId' => $inviteId, 'status' => 'joined'], $answer['studio']);
        self::assertSame('joined', $this->statusOf($inviteId));
        self::assertNull((new InMemoryStudioInviteSignal())->open(self::ROUND, self::OLIVIA));

        // A double tap is answered again
        $this->answer(OnlineRoundsFixture::TOKEN_CONNECTED, $inviteId, 'join');
        self::assertResponseIsSuccessful();

        // Changing the mind afterwards is not
        $this->answer(OnlineRoundsFixture::TOKEN_CONNECTED, $inviteId, 'decline');
        self::assertRefused($this->browser, 409, 'invite_not_open');
    }

    public function testDeclining(): void
    {
        self::turnRoundVideoOn($this->connection());
        $inviteId = $this->invite(self::OLIVIA);

        $this->answer(OnlineRoundsFixture::TOKEN_CONNECTED, strtoupper($inviteId), 'decline');

        self::assertResponseIsSuccessful();
        self::assertSame(['inviteId' => $inviteId, 'status' => 'declined'], self::json($this->browser)['studio']);
        self::assertSame('declined', $this->statusOf($inviteId));
    }

    public function testOnlyTheParticipantsOwnInvitationWhileItWaits(): void
    {
        self::turnRoundVideoOn($this->connection());
        $inviteId = $this->invite(self::OLIVIA);

        // Arthur's link, Olivia's invitation
        $this->answer(OnlineRoundsFixture::TOKEN_ANONYMOUS, $inviteId, 'join');
        self::assertRefused($this->browser, 404, 'invite_not_found');

        $this->answer(OnlineRoundsFixture::TOKEN_CONNECTED, '018d0060-0000-7000-8000-00000000ffff', 'join');
        self::assertRefused($this->browser, 404, 'invite_not_found');

        $this->answer(OnlineRoundsFixture::TOKEN_CONNECTED, 'not-an-invitation', 'join');
        self::assertRefused($this->browser, 404, 'invite_not_found');

        foreach ([[], ['answer' => 'maybe'], ['answer' => true]] as $body) {
            self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'studio-invites/' . $inviteId), $body);
            self::assertRefused($this->browser, 422, 'invalid_fields');
        }

        $this->connection()->executeStatement("UPDATE studio_invite SET invited_at = invited_at - interval '3 minutes' WHERE id = :id", ['id' => $inviteId]);
        $this->answer(OnlineRoundsFixture::TOKEN_CONNECTED, $inviteId, 'join');
        self::assertRefused($this->browser, 409, 'invite_expired');

        $this->answer('NoSuchLinkAnywhere2222', $inviteId, 'join');
        self::assertRefused($this->browser, 404, 'unknown_link');

        self::assertSame('invited', $this->statusOf($inviteId));
    }

    public function testJoiningOnceTheRoundsVideoWasTurnedOff(): void
    {
        self::turnRoundVideoOn($this->connection());
        $inviteId = $this->invite(self::OLIVIA);
        $this->connection()->update('online_round', ['video_enabled' => false], ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO], ['video_enabled' => 'boolean']);
        // The request below runs in the kernel that made the invitation - its entity manager read the round before
        $this->browser->getContainer()->get(EntityManagerInterface::class)->clear();

        $this->answer(OnlineRoundsFixture::TOKEN_CONNECTED, $inviteId, 'join');

        self::assertRefused($this->browser, 409, 'video_unavailable');
        self::assertSame('invited', $this->statusOf($inviteId));
    }

    public function testWithoutAMediaServerNothingIsAnswered(): void
    {
        self::turnRoundVideoOn($this->connection());
        $inviteId = $this->invite(self::OLIVIA);
        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $this->browser = self::createClient();

        $this->answer(OnlineRoundsFixture::TOKEN_CONNECTED, $inviteId, 'join');

        self::assertRefused($this->browser, 409, 'video_unavailable');
    }

    private function answer(string $token, string $inviteId, string $answer): void
    {
        self::postJson($this->browser, self::api($token, 'studio-invites/' . $inviteId), ['answer' => $answer]);
    }

    private function invite(string $participantId): string
    {
        $invite = $this->browser->getContainer()->get(MessageBusInterface::class)
            ->dispatch(new InviteToStudio(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, self::ROUND, $participantId, PlayerFixture::PLAYER_ADMIN))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StudioInvite::class, $invite);

        return $invite->id->toString();
    }

    private function statusOf(string $inviteId): mixed
    {
        return $this->connection()->fetchOne('SELECT status FROM studio_invite WHERE id = :id', ['id' => $inviteId]);
    }

    private function connection(): Connection
    {
        return $this->browser->getContainer()->get(Connection::class);
    }
}
