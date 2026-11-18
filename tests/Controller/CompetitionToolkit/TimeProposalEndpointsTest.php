<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetCompetitionTagEntries;
use SpeedPuzzling\Web\Results\CompetitionTagEntry;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals\TimeProposalScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Update;

/**
 * The competitor's time proposal endpoints and what the judges' tools get of proposals (competition toolkit P1):
 * answers, refusals, the round state's `proposals` and team `lineupId`, and the `official_results.proposals` Mercure
 * message - published after the commit only, one payload for the organisers' and the referees' topic without anything
 * of a linked player's profile. The test hub forgets its updates at every request (kernel reset) - each assertion is
 * about the request before it.
 */
final class TimeProposalEndpointsTest extends WebTestCase
{
    use TimeProposalScenario;

    private const string SHARKS = 'team:' . OfficialResultsFixture::TEAM_SHARKS;

    private const string PROPOSE = '/en/competitor-proposals/rounds/' . OfficialResultsFixture::ROUND_FINAL;
    private const string PROPOSE_PAIRS = '/en/competitor-proposals/rounds/' . OfficialResultsFixture::ROUND_PAIRS;
    private const string FINAL_STATE = '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_FINAL;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        self::runningRounds();
    }

    public function testNobodySignedInIsAskedToSignIn(): void
    {
        $this->post(self::PROPOSE, $this->body(self::ANNA_FINAL));

        self::assertResponseStatusCodeSame(401);
        self::assertSame('sign_in_required', $this->json()['error']);
    }

    public function testAProposalFromAnotherSiteIsRefused(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->post(self::PROPOSE, $this->body(self::ANNA_FINAL), origin: 'https://evil.example');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('invalid_csrf_token', $this->json()['error']);
        self::assertSame(0, self::proposalCount());
    }

    public function testTheCompetitorProposesAndTheJudgesAreTold(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $proposalId = self::newProposalId();

        $this->post(self::PROPOSE, $this->body(self::ANNA_FINAL, $proposalId));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        $answer = $this->json();
        self::assertSame('proposed', $answer['status']);
        self::assertNull($answer['reason']);
        self::assertIsArray($answer['proposal']);
        self::assertSame($proposalId, $answer['proposal']['id']);
        self::assertSame('pending', $answer['proposal']['status']);
        self::assertSame(598, $answer['proposal']['proposedSeconds']);
        // The competitor's own view: no link moment
        self::assertSame(['participantId' => OfficialResultsFixture::PARTICIPANT_ANNA, 'name' => 'Anna Fast'], $answer['proposal']['proposedBy']);

        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertSame(['/round-results/' . OfficialResultsFixture::ROUND_FINAL, '/round-results/' . OfficialResultsFixture::ROUND_FINAL . '/referees'], $updates[0]->getTopics());
        self::assertTrue($updates[0]->isPrivate());
        $payload = self::payload($updates[0]);
        self::assertSame('official_results.proposals', $payload['type']);
        self::assertIsArray($payload['proposals']);
        self::assertSame([$proposalId], array_column($payload['proposals'], 'id'));
        self::assertSame('participant_round:' . OfficialResultsFixture::ENTRY_FINAL_ANNA, self::dig($payload, 'proposals', 0, 'entry'));
        self::assertFalse(self::dig($payload, 'proposals', 0, 'late'));
        // Linked 20 days ago - no "linked recently" note for the judge
        self::assertNull(self::dig($payload, 'proposals', 0, 'proposedBy', 'linkedAt'));

        // A resend answers the same, tells nobody again
        $this->post(self::PROPOSE, $this->body(self::ANNA_FINAL, $proposalId));
        self::assertSame('known', $this->json()['status']);
        self::assertSame([], self::hub()->getPublishedUpdates());
    }

    /**
     * Reliability hardening T4: the phone's answer does not wait for the hub - the judges' push follows the response.
     */
    public function testTheAnswerIsSentBeforeTheJudgesAreTold(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->browser->disableReboot();
        $publishedWhenAnswered = null;
        self::getContainer()->get('event_dispatcher')->addListener(KernelEvents::RESPONSE, static function () use (&$publishedWhenAnswered): void {
            $publishedWhenAnswered = count(self::hub()->getPublishedUpdates());
        }, -1000);

        $this->post(self::PROPOSE, $this->body(self::ANNA_FINAL, self::newProposalId()));

        self::assertSame('proposed', $this->json()['status']);
        self::assertSame(0, $publishedWhenAnswered);
        self::assertCount(1, self::hub()->getPublishedUpdates());
    }

    public function testARefusalIsSaidAndToldToNobody(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->post(self::PROPOSE, $this->body(self::ANNA_FINAL));

        self::assertResponseStatusCodeSame(403);
        $answer = $this->json();
        self::assertSame('refused', $answer['status']);
        self::assertSame('not_your_entry', $answer['reason']);
        self::assertIsString($answer['message']);
        self::assertNull($answer['proposal']);
        self::assertSame([], self::hub()->getPublishedUpdates());
    }

    /**
     * Competition toolkit v2 C1b: the competitor pages of an event that is not publicly visible are its team's only, so
     * is the Stop button - a competitor proposes nothing into an event waiting for approval (404 `proposals_off`, final
     * on the phone, nothing stored), its team may rehearse; once approved the competitor proposes again
     */
    public function testNobodyOffTheTeamProposesIntoAnEventThatIsNotPublic(): void
    {
        self::db()->executeStatement('UPDATE competition SET approved_at = NULL WHERE id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->post(self::PROPOSE_PAIRS, $this->body(self::UNNAMED_PAIR));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('proposals_off', $this->json()['error']);
        self::assertSame(0, self::proposalCount());
        self::assertSame([], self::hub()->getPublishedUpdates());

        // The team (an admin, Anna) rehearses
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->post(self::PROPOSE, $this->body(self::ANNA_FINAL));
        self::assertResponseIsSuccessful();
        self::assertSame('proposed', $this->json()['status']);

        self::db()->executeStatement('UPDATE competition SET approved_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->post(self::PROPOSE_PAIRS, $this->body(self::UNNAMED_PAIR));
        self::assertResponseIsSuccessful('No canary: the competitor cannot propose into the approved event either.');
        self::assertSame('proposed', $this->json()['status']);
    }

    /**
     * Reliability hardening F1 (D1): a tap stored on the phone for one player, sent after another player signed in on
     * the same browser, is neither stored nor refused - 409 `another_account` keeps it on the phone; so is an Undo
     */
    public function testATapOfAnotherPlayerThanTheSignedInOneStaysOnThePhone(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $proposalId = self::newProposalId();

        $this->post(self::PROPOSE, [...$this->body(self::ANNA_FINAL, $proposalId), 'expectedPlayerId' => PlayerFixture::PLAYER_ADMIN]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('another_account', $this->json()['error']);
        self::assertSame(0, self::proposalCount());

        $this->post('/en/competitor-proposals/' . $proposalId . '/withdraw', ['expectedPlayerId' => PlayerFixture::PLAYER_ADMIN]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('another_account', $this->json()['error']);

        // The tap's own player
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->post(self::PROPOSE, [...$this->body(self::ANNA_FINAL, $proposalId), 'expectedPlayerId' => PlayerFixture::PLAYER_ADMIN]);

        self::assertResponseIsSuccessful();
        self::assertSame('proposed', $this->json()['status']);
    }

    public function testAnUnreadableProposalIs400(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->post(self::PROPOSE, ['proposalId' => self::newProposalId(), 'entry' => self::ANNA_FINAL, 'tappedAtMs' => 'soon']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_proposal', $this->json()['error']);
    }

    /**
     * Published only after the commit: a write that rolls back tells nobody (a test-only trigger makes the insert fail)
     */
    public function testAProposalThatRollsBackIsPublishedToNobody(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        self::db()->executeStatement("CREATE FUNCTION test_fail_time_proposal() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'test failure'; END \$\$");
        self::db()->executeStatement('CREATE TRIGGER test_fail_time_proposal BEFORE INSERT ON competition_time_proposal FOR EACH ROW EXECUTE FUNCTION test_fail_time_proposal()');

        $this->post(self::PROPOSE, $this->body(self::ANNA_FINAL));

        self::assertResponseStatusCodeSame(500);
        self::assertSame([], self::hub()->getPublishedUpdates());
        self::assertSame(0, self::proposalCount());
    }

    public function testTheCompetitorWithdrawsAndTheJudgesAreTold(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $proposalId = self::newProposalId();
        $this->post(self::PROPOSE, $this->body(self::ANNA_FINAL, $proposalId));

        $this->post('/en/competitor-proposals/' . $proposalId . '/withdraw', []);

        self::assertResponseIsSuccessful();
        self::assertSame('withdrawn', $this->json()['status']);
        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        $payload = self::payload($updates[0]);
        self::assertIsArray($payload['proposals']);
        self::assertSame('withdrawn', self::dig($payload, 'proposals', 0, 'status'));

        // Somebody else's undo shows nothing of it
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->post('/en/competitor-proposals/' . $proposalId . '/withdraw', []);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->json()['proposal']);

        $this->post('/en/competitor-proposals/' . Uuid::uuid7()->toString() . '/withdraw', []);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The referees' topic carries organiser-recorded names only (R §1.2, §1.6): a private player who proposes for
     * their pair leaves nothing of their profile in the payload both topics get
     */
    public function testTheRefereesTopicGetsNothingOfAPrivateProposersProfile(): void
    {
        // Jane Smith (#player2, private) linked to Eva of the unnamed pair a day before the start
        self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_PRIVATE, TestClock::now()->modify('-1 day'));
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_PRIVATE);

        $this->post(self::PROPOSE_PAIRS, $this->body(self::UNNAMED_PAIR));
        self::assertResponseIsSuccessful();

        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertContains('/round-results/' . OfficialResultsFixture::ROUND_PAIRS . '/referees', $updates[0]->getTopics());
        $data = $updates[0]->getData();
        self::assertStringContainsString('Eva Noshow', $data);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, $data);
        self::assertStringNotContainsString('Jane Smith', $data);
        self::assertStringNotContainsStringIgnoringCase('player2', $data);
    }

    public function testTheRoundStateListsProposalsOnlyWhileTheCompetitionTakesThem(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $proposalId = self::newProposalId();
        $this->post(self::PROPOSE, $this->body(self::ANNA_FINAL, $proposalId));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->browser->request('GET', self::FINAL_STATE);
        $state = $this->json();
        self::assertIsArray($state['proposals']);
        self::assertSame([$proposalId], array_column($state['proposals'], 'id'));
        // The judges' view names the judge once decided and the link moment when recent
        $proposedBy = self::dig($state, 'proposals', 0, 'proposedBy');
        self::assertIsArray($proposedBy);
        self::assertArrayHasKey('linkedAt', $proposedBy);

        self::proposalsOn(false);
        $this->browser->request('GET', self::FINAL_STATE);
        self::assertArrayNotHasKey('proposals', $this->json());
    }

    public function testTeamEntriesCarryTheirLineUpOnlyWithCompetitorPagesOrTags(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_PAIRS);
        $entries = $this->json()['entries'];
        self::assertIsArray($entries);
        $sharks = array_values(array_filter($entries, static fn (mixed $entry): bool => is_array($entry) && $entry['ref'] === self::SHARKS))[0];
        self::assertSame(
            \SpeedPuzzling\Web\Value\CompetitionLineupId::of(OfficialResultsFixture::COMPETITION_RESULTS_CUP, [OfficialResultsFixture::PARTICIPANT_ANNA, OfficialResultsFixture::PARTICIPANT_BEN]),
            $sharks['lineupId'],
        );

        // A member on the waitlist (H1): listed no more, but the line-up still counts them - the tag route's rule
        // (GetCompetitionTagEntries::ofLineup(): every member not removed), so the printed tag still opens the pair
        self::db()->executeStatement("UPDATE competition_participant SET registration_status = 'waitlisted' WHERE id = :id", ['id' => OfficialResultsFixture::PARTICIPANT_BEN]);
        $this->browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_PAIRS);
        $entries = $this->json()['entries'];
        self::assertIsArray($entries);
        $sharksWaiting = array_values(array_filter($entries, static fn (mixed $entry): bool => is_array($entry) && $entry['ref'] === self::SHARKS))[0];
        self::assertIsArray($sharksWaiting['members']);
        self::assertSame([OfficialResultsFixture::PARTICIPANT_ANNA], array_column($sharksWaiting['members'], 'participantId'));
        self::assertSame($sharks['lineupId'], $sharksWaiting['lineupId']);
        $tagEntries = self::getContainer()->get(GetCompetitionTagEntries::class)->ofLineup(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $sharks['lineupId']);
        self::assertContains(self::SHARKS, array_map(static fn (CompetitionTagEntry $entry): string => (string) $entry->entry?->toString(), $tagEntries));
        self::db()->executeStatement('UPDATE competition_participant SET registration_status = NULL WHERE id = :id', ['id' => OfficialResultsFixture::PARTICIPANT_BEN]);

        // A person's entry never has one
        $this->browser->request('GET', self::FINAL_STATE);
        $final = $this->json()['entries'];
        self::assertIsArray($final);
        self::assertIsArray($final[0]);
        self::assertArrayNotHasKey('lineupId', $final[0]);

        self::proposalsOn(false);
        $this->browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_PAIRS);
        $entries = $this->json()['entries'];
        self::assertIsArray($entries);
        self::assertIsArray($entries[0]);
        self::assertArrayNotHasKey('lineupId', $entries[0]);
    }

    public function testAJudgesConfirmationIsPublishedWithTheEntry(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $proposalId = self::newProposalId();
        $this->post(self::PROPOSE, $this->body(self::ANNA_FINAL, $proposalId));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->post('/en/official-results/rounds/' . OfficialResultsFixture::ROUND_FINAL . '/changes', [
            'changes' => [self::resultChange(self::ANNA_FINAL, null, ['seconds' => 598], $proposalId)],
        ], token: 'csrf-token');

        self::assertResponseIsSuccessful();
        $outcomes = $this->json()['outcomes'];
        self::assertIsArray($outcomes);
        self::assertIsArray($outcomes[0]);
        self::assertSame(['id' => $proposalId, 'status' => 'confirmed'], $outcomes[0]['proposal']);

        $types = array_map(static fn (Update $update): mixed => self::payload($update)['type'], self::hub()->getPublishedUpdates());
        self::assertSame(['official_results.entries', 'official_results.proposals'], $types);
        $last = self::payload(self::hub()->getPublishedUpdates()[1]);
        self::assertIsArray($last['proposals']);
        self::assertSame('confirmed', self::dig($last, 'proposals', 0, 'status'));
        self::assertSame(598, self::dig($last, 'proposals', 0, 'decided', 'seconds'));
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, self::dig($last, 'proposals', 0, 'decided', 'byPlayerId'));
    }

    public function testAStopwatchResetTellsTheJudgesTheProposalsAreGone(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $proposalId = self::newProposalId();
        $this->post(self::PROPOSE, $this->body(self::ANNA_FINAL, $proposalId));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->browser->request('POST', '/en/reset-round-stopwatch/' . OfficialResultsFixture::ROUND_FINAL, ['_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $proposals = array_values(array_filter(self::hub()->getPublishedUpdates(), static fn (Update $update): bool => (self::payload($update)['type'] ?? null) === 'official_results.proposals'));
        self::assertCount(1, $proposals);
        $payload = self::payload($proposals[0]);
        self::assertIsArray($payload['proposals']);
        self::assertSame('superseded', self::dig($payload, 'proposals', 0, 'status'));
        self::assertSame('stopwatch_reset', self::dig($payload, 'proposals', 0, 'decided', 'reason'));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $entry, null|string $proposalId = null): array
    {
        return [
            'proposalId' => $proposalId ?? self::newProposalId(),
            'entry' => $entry,
            'tappedAtMs' => self::nowMs() - 2000,
            'offsetMs' => -41.5,
            'rttMs' => 120,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $url, array $body, string $origin = 'http://localhost', string $token = 'csrf-token'): void
    {
        $this->browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(Update $update): array
    {
        $decoded = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function dig(mixed $data, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($data);
            self::assertArrayHasKey($key, $data);
            $data = $data[$key];
        }

        return $data;
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }
}
