<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `GET /api/compete/{token}/state` - the state a participant's page lives on (implementation-plan.md §3.1).
 */
final class ApiStateControllerTest extends WebTestCase
{
    use CompeteApiRequests;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        self::forgetCachedAccess($this->browser);
    }

    public function testTheStateOfAConnectedParticipantsLink(): void
    {
        $before = self::nowMs();
        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'state'));

        self::assertResponseIsSuccessful();
        self::assertPrivateNoStore($this->browser);
        self::assertNoCookie($this->browser);

        $state = self::json($this->browser);
        self::assertSame(['serverNowMs', 'asOfMs', 'participant', 'competition', 'round', 'entry', 'mercure'], array_keys($state));
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
