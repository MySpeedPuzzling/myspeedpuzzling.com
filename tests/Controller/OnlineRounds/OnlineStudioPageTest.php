<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Message\AnswerStudioInvite;
use SpeedPuzzling\Web\Message\EndStudioGuest;
use SpeedPuzzling\Web\Message\InviteToStudio;
use SpeedPuzzling\Web\Message\RecordStudioGuestPresence;
use SpeedPuzzling\Web\Services\AnnouncementModals\ResolveAnnouncementModal;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\StudioInviteAnswer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The host's studio page (`online_studio`, contract phase-1b.md §5, stream VM): the organisers of the event for whom
 * online rounds are on (COMPETITION_EDIT + ONLINE_ROUNDS_MANAGE), rounds with online timing only; without live video it
 * says why and nothing more; with it the page carries the studio's addresses and texts. Asked for JSON it answers every
 * participant's latest invitation with its status as of now, in a constant number of statements.
 */
final class OnlineStudioPageTest extends WebTestCase
{
    use LiveVideoTesting;
    use OnlineReviewSeeding;
    use OverridesFeatureFlagEnv;
    use QueryCountAssertions;

    private const string FLAG = 'ONLINE_ROUNDS_FOR_ORGANISERS';
    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string OLIVIA = OnlineRoundsFixture::PARTICIPANT_CONNECTED;
    private const string PAGE = '/en/online-rounds/' . self::ROUND . '/studio';
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    protected function setUp(): void
    {
        self::resetLiveVideoDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();
        $this->restoreLiveVideo();

        parent::tearDown();
    }

    public function testGuestsAreSentToSignInAndTheJsonSaysSo(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::PAGE);
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));

        $browser->request('GET', self::PAGE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'sign_in_required'], $this->json($browser));
    }

    public function testOnlyTheEventsOrganisersForWhomOnlineRoundsAreOn(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $browser = self::createClient();
        self::turnRoundVideoOn($this->connection($browser));
        $this->connection($browser)->insert('competition_maintainer', ['competition_id' => self::COMPETITION, 'player_id' => self::ORGANISER]);

        // Olivia's player - a participant, no organiser
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);
        $browser->request('GET', self::PAGE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $this->json($browser));

        // An organiser of the event while online rounds are for admins only
        TestingLogin::asPlayer($browser, self::ORGANISER);
        $browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);

        // Admins always
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();

        self::ensureKernelShutdown();
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ORGANISER);
        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        $browser->request('GET', self::PAGE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
    }

    public function testOnlyRoundsWithOnlineTiming(): void
    {
        $browser = $this->signedIn();

        $browser->request('GET', '/en/online-rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/studio');
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', '/en/online-rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/studio', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'round_not_online'], $this->json($browser));

        $browser->request('GET', '/en/online-rounds/018d0050-0000-0000-0000-00000000dead/studio');
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', '/en/online-rounds/018d0050-0000-0000-0000-00000000dead/studio', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'round_not_found'], $this->json($browser));
    }

    public function testWithoutLiveVideoThePageSaysWhy(): void
    {
        // The round's video off
        $browser = $this->signedIn();
        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSame('off', $crawler->filter('[data-online-studio-missing]')->attr('data-online-studio-missing'));
        self::assertStringContainsString('Live video is off for this round', $crawler->filter('[data-online-studio-missing]')->text());
        self::assertSame('/en/online-rounds/' . self::ROUND . '/settings', $crawler->filter('[data-online-studio-missing] a')->first()->attr('href'));
        self::assertCount(0, $crawler->filter('[data-controller="online-studio"]'), 'no studio without video');
        $browser->request('GET', self::PAGE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'video_unavailable'], $this->json($browser));

        // No media server at all
        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $browser = $this->signedIn();
        self::turnRoundVideoOn($this->connection($browser));
        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSame('unavailable', $crawler->filter('[data-online-studio-missing]')->attr('data-online-studio-missing'));
        self::assertStringContainsString('not available on this server', $crawler->filter('[data-online-studio-missing]')->text());
        self::assertCount(0, $crawler->filter('[data-controller="online-studio"]'));
        $browser->request('GET', self::PAGE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testWithLiveVideoThePageHasTheStudio(): void
    {
        $browser = $this->signedIn();
        self::turnRoundVideoOn($this->connection($browser));

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('Studio', $crawler->filter('h1')->text());

        $studio = $crawler->filter('[data-controller="online-studio"]');
        self::assertCount(1, $studio);
        $urls = json_decode((string) $studio->attr('data-online-studio-urls-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            'token' => '/en/online-rounds/' . self::ROUND . '/video-token',
            'studio' => '/en/online-rounds/' . self::ROUND . '/studio',
            'end' => '/en/online-rounds/' . self::ROUND . '/studio-invites/00000000-0000-0000-0000-000000000000/end',
        ], $urls);
        self::assertSame('00000000-0000-0000-0000-000000000000', $studio->attr('data-online-studio-placeholder-value'));
        self::assertNotSame('', (string) $studio->attr('data-online-studio-csrf-token-value'));

        $texts = json_decode((string) $studio->attr('data-online-studio-texts-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);
        self::assertSame(['message' => '{0}Nobody is watching yet|{1}1 person is watching|]1,Inf[%count% people are watching', 'locale' => 'en'], $texts['audience'] ?? null);
        self::assertSame('Thank you', $texts['thank_you'] ?? null);

        // The host goes on air with a click; the control room is one click away
        self::assertSame('Start camera and microphone', trim($crawler->filter('[data-online-studio-target="goLive"]')->text()));
        self::assertSame('/en/online-rounds/' . self::ROUND . '/control', $crawler->filter('[data-online-tool="control"]')->attr('href'));

        // Never interrupted by an announcement modal
        self::assertContains('online_studio', ResolveAnnouncementModal::QUIET_ROUTES);
    }

    public function testTheJsonHasEveryParticipantsLatestInvitation(): void
    {
        $browser = $this->signedIn();
        $browser->disableReboot();
        self::turnRoundVideoOn($this->connection($browser));
        $bus = $browser->getContainer()->get(MessageBusInterface::class);

        // Olivia: invited, joined, on air
        $olivia = $this->invite($bus, self::OLIVIA);
        $bus->dispatch(new AnswerStudioInvite($olivia, self::OLIVIA, StudioInviteAnswer::Join));
        $onAirFrom = new DateTimeImmutable('-30 seconds');
        $bus->dispatch(new RecordStudioGuestPresence(self::ROUND, self::OLIVIA, true, $onAirFrom));

        // Arthur: invited three minutes ago, never answered - expired as of now, whatever is stored
        $arthur = Uuid::uuid7()->toString();
        $this->connection($browser)->insert('studio_invite', [
            'id' => $arthur,
            'round_id' => self::ROUND,
            'participant_id' => OnlineRoundsFixture::PARTICIPANT_ANONYMOUS,
            'invited_by_id' => PlayerFixture::PLAYER_ADMIN,
            'invited_at' => (new DateTimeImmutable('-3 minutes'))->format('Y-m-d H:i:s'),
            'status' => 'invited',
        ]);

        $browser->request('GET', self::PAGE, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $state = $this->json($browser);
        self::assertIsInt($state['serverNowMs'] ?? null);
        $invites = $state['invites'] ?? null;
        self::assertIsArray($invites);
        self::assertSame(['Arthur Anonymous', 'Olivia Online'], array_column($invites, 'name'), 'by name');

        [$expired, $onAir] = $invites;
        self::assertIsArray($expired);
        self::assertSame($arthur, $expired['id']);
        self::assertSame(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, $expired['participantId']);
        self::assertSame('expired', $expired['status']);
        self::assertSame('gb', $expired['country']);
        self::assertIsInt($expired['invitedAtMs']);
        self::assertSame($expired['invitedAtMs'] + 120_000, $expired['expiresAtMs']);
        self::assertNull($expired['answeredAtMs']);

        self::assertIsArray($onAir);
        self::assertSame(['id', 'participantId', 'name', 'country', 'status', 'invitedAtMs', 'expiresAtMs', 'answeredAtMs', 'onAirFromMs', 'onAirToMs'], array_keys($onAir));
        self::assertSame($olivia, $onAir['id']);
        self::assertSame('joined', $onAir['status']);
        self::assertIsInt($onAir['answeredAtMs']);
        self::assertSame($onAirFrom->getTimestamp() * 1000, $onAir['onAirFromMs']);
        self::assertNull($onAir['onAirToMs']);

        // Thanked, invited again: the newer invitation replaces the one shown
        $bus->dispatch(new EndStudioGuest($olivia, PlayerFixture::PLAYER_ADMIN));
        $again = $this->invite($bus, self::OLIVIA);
        $browser->request('GET', self::PAGE, server: ['HTTP_ACCEPT' => 'application/json']);
        $invites = $this->json($browser)['invites'] ?? null;
        self::assertIsArray($invites);
        self::assertSame([$arthur, $again], array_column($invites, 'id'));
        self::assertSame(['expired', 'invited'], array_column($invites, 'status'));
    }

    public function testTheJsonCostsTheSameForManyInvitationsAsForOne(): void
    {
        $browser = $this->signedIn();
        self::turnRoundVideoOn($this->connection($browser));
        $bus = $browser->getContainer()->get(MessageBusInterface::class);
        $this->invite($bus, self::OLIVIA);

        $one = $this->statementsOf($browser);

        for ($i = 0; $i < 12; $i++) {
            $entry = $this->enterParticipant(sprintf('Invented Finisher %02d', $i));
            $this->invite($bus, $entry->participant->id->toString());
        }

        $many = $this->statementsOf($browser);

        self::assertSame($one, $many, implode("\n", $this->executedSql($browser)));
        $invites = $this->json($browser)['invites'] ?? null;
        self::assertIsArray($invites);
        self::assertCount(13, $invites);
    }

    private function invite(MessageBusInterface $bus, string $participantId): string
    {
        $invite = $bus->dispatch(new InviteToStudio(self::COMPETITION, self::ROUND, $participantId, PlayerFixture::PLAYER_ADMIN))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StudioInvite::class, $invite);

        return $invite->id->toString();
    }

    private function statementsOf(KernelBrowser $browser): int
    {
        $browser->request('GET', self::PAGE, server: ['HTTP_ACCEPT' => 'application/json']);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::PAGE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }

    private function signedIn(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        return $browser;
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
