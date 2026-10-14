<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Message\AnswerStudioInvite;
use SpeedPuzzling\Web\Message\InviteToStudio;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\StudioInviteAnswer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * `GET /api/compete/{token}/state` - the state a participant's page lives on (implementation-plan.md §3.1), and while
 * live video is available the participant's invitation to the studio (contract phase-1b.md §4).
 */
final class ApiStateControllerTest extends WebTestCase
{
    use CompeteApiRequests;
    use LiveVideoTesting;

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

    public function testTheStateOfAConnectedParticipantsLink(): void
    {
        $before = self::nowMs();
        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'state'));

        self::assertResponseIsSuccessful();
        self::assertPrivateNoStore($this->browser);
        self::assertNoCookie($this->browser);

        $state = self::json($this->browser);
        self::assertSame(['serverNowMs', 'asOfMs', 'participant', 'competition', 'round', 'entry', 'mercure', 'studio'], array_keys($state));
        self::assertIsInt($state['serverNowMs']);
        self::assertGreaterThanOrEqual($before, $state['serverNowMs']);
        self::assertIsInt($state['asOfMs']);
        self::assertLessThanOrEqual($state['serverNowMs'], $state['asOfMs']);

        self::assertSame([
            'id' => OnlineRoundsFixture::PARTICIPANT_CONNECTED,
            'name' => 'Olivia Online',
            'connectedPlayer' => ['id' => PlayerFixture::PLAYER_REGULAR, 'name' => PlayerFixture::PLAYER_REGULAR_NAME, 'code' => 'player1'],
        ], $state['participant']);
        self::assertSame(['id' => OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, 'name' => 'Online Test Cup'], $state['competition']);

        $round = $state['round'];
        self::assertIsArray($round);
        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, $round['id']);
        self::assertSame('waiting', $round['status']);
        self::assertSame(3000, $round['piecesTotal']);
        self::assertSame(60, $round['minutesLimit']);
        self::assertNull($round['startedAtMs']);
        self::assertIsString($round['stateHash']);

        self::assertSame(['confirmedMomentMs' => null, 'candidates' => 0, 'taps' => 0, 'reportedPieces' => null, 'review' => 'pending'], $state['entry']);

        $mercure = $state['mercure'];
        self::assertIsArray($mercure);
        self::assertSame(['/round-stopwatch/' . OnlineRoundsFixture::ROUND_ONLINE_SOLO], $mercure['topics']);

        // The round's video is off - no studio
        self::assertNull($state['studio']);
    }

    public function testTheStateCarriesTheParticipantsInvitationToTheStudio(): void
    {
        $connection = $this->browser->getContainer()->get(Connection::class);
        self::turnRoundVideoOn($connection);

        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'state'));
        self::assertNull(self::json($this->browser)['studio'], 'never invited');

        $bus = $this->browser->getContainer()->get(MessageBusInterface::class);
        $invite = $bus->dispatch(new InviteToStudio(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, OnlineRoundsFixture::ROUND_ONLINE_SOLO, OnlineRoundsFixture::PARTICIPANT_CONNECTED, PlayerFixture::PLAYER_ADMIN))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StudioInvite::class, $invite);
        $inviteId = $invite->id->toString();

        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'state'));
        self::assertSame(['inviteId' => $inviteId, 'status' => 'invited'], self::json($this->browser)['studio']);

        // Somebody else's state knows nothing of it
        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'state'));
        self::assertNull(self::json($this->browser)['studio']);

        // Nobody answered for two minutes: expired, checked on read
        $connection->executeStatement("UPDATE studio_invite SET invited_at = invited_at - interval '121 seconds' WHERE id = :id", ['id' => $inviteId]);
        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'state'));
        self::assertSame(['inviteId' => $inviteId, 'status' => 'expired'], self::json($this->browser)['studio']);
        self::assertSame('invited', $connection->fetchOne('SELECT status FROM studio_invite WHERE id = :id', ['id' => $inviteId]), 'reading writes nothing');

        $connection->executeStatement("UPDATE studio_invite SET invited_at = invited_at + interval '121 seconds' WHERE id = :id", ['id' => $inviteId]);
        $this->browser->getContainer()->get(MessageBusInterface::class)->dispatch(new AnswerStudioInvite($inviteId, OnlineRoundsFixture::PARTICIPANT_CONNECTED, StudioInviteAnswer::Join));
        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'state'));
        self::assertSame(['inviteId' => $inviteId, 'status' => 'joined'], self::json($this->browser)['studio']);
    }

    /**
     * No media server (production until it has one): the state is exactly what it was before live video
     */
    public function testWithoutAMediaServerTheStateIsAsBeforeLiveVideo(): void
    {
        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $this->browser = self::createClient();

        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'state'));

        self::assertResponseIsSuccessful();
        self::assertSame(['serverNowMs', 'asOfMs', 'participant', 'competition', 'round', 'entry', 'mercure'], array_keys(self::json($this->browser)));
    }

    public function testAnAnonymousLinkOfADraftCompetitionWorks(): void
    {
        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'state'));

        self::assertResponseIsSuccessful();
        $state = self::json($this->browser);
        $participant = $state['participant'];
        self::assertIsArray($participant);
        self::assertSame(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, $participant['id']);
        self::assertNull($participant['connectedPlayer']);
        self::assertNoCookie($this->browser);
    }

    public function testAnUnknownLinkIsA404InJson(): void
    {
        $this->browser->request('GET', self::api('NoSuchLinkAnywhere2222', 'state'));
        self::assertRefused($this->browser, 404, 'unknown_link');

        // Not even shaped like a link - never reaches the database
        $this->browser->request('GET', self::api('not-a-link', 'state'));
        self::assertRefused($this->browser, 404, 'unknown_link');
    }

    public function testARemovedParticipantsLinkOpensNothing(): void
    {
        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $participant = $entityManager->find(CompetitionParticipant::class, OnlineRoundsFixture::PARTICIPANT_ANONYMOUS);
        self::assertNotNull($participant);
        $participant->softDelete(new DateTimeImmutable());
        $entityManager->flush();

        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'state'));

        self::assertRefused($this->browser, 404, 'unknown_link');
    }

    /**
     * A signed-in player's phone sends its session cookie along - the API neither reads nor renews it
     */
    public function testASignedInPlayersCookiesAreLeftAlone(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        // loginUser() hands the token to the booted kernel itself - the next request boots a fresh one, which knows
        // the player only from the session cookie, as a real request does
        $this->browser->request('GET', '/-/time');

        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'state'));

        self::assertResponseIsSuccessful();
        self::assertNoCookie($this->browser);
    }
}
