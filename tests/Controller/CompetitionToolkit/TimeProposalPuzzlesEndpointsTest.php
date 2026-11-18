<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals\PickFinalScenario;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals\TimeProposalScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\Update;

/**
 * The puzzle choice on the wire (competition toolkit v2 C2): `puzzles` with the tap, the "Change puzzles" endpoint
 * (`competitor_time_proposal_puzzles`), what the judges get (round puzzle ids - round state and Mercure) and what the
 * competitor's phone gets (places by label - never an id or a hidden puzzle's name).
 */
final class TimeProposalPuzzlesEndpointsTest extends WebTestCase
{
    use TimeProposalScenario;
    use PickFinalScenario;

    private const string PROPOSE = '/en/competitor-proposals/rounds/' . OfficialResultsFixture::ROUND_FINAL;
    private const string FINAL_STATE = '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_FINAL;

    private KernelBrowser $browser;
    private string $blueBox;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        self::runningRounds();
        $this->blueBox = self::pickFinal(self::db());
    }

    public function testTheChoiceRidesAlongTheTap(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $proposalId = self::newProposalId();

        $this->post(self::PROPOSE, [...$this->body($proposalId), 'puzzles' => [['position' => 2, 'label' => 'Blue box'], ['position' => 1, 'label' => 'A']]]);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertSame('proposed', $answer['status']);
        self::assertArrayNotHasKey('puzzlesRefused', $answer);
        self::assertSame([['position' => 2, 'label' => 'Blue box'], ['position' => 1, 'label' => 'A']], self::dig($answer, 'proposal', 'puzzles'));
        $raw = (string) $this->browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::PICK_SECRET, $raw);
        self::assertStringNotContainsString(self::PICK_SECRET_NAME, $raw);

        // The judges: round puzzle ids to pre-select - Mercure and the round state alike
        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertSame([$this->blueBox, self::PICK_SECRET], self::dig(self::payload($updates[0]), 'proposals', 0, 'puzzles'));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->browser->request('GET', self::FINAL_STATE);
        self::assertSame([$this->blueBox, self::PICK_SECRET], self::dig($this->json(), 'proposals', 0, 'puzzles'));
    }

    public function testAnUnreadableChoiceNeverCostsTheTap(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $proposalId = self::newProposalId();

        $this->post(self::PROPOSE, [...$this->body($proposalId), 'puzzles' => [['roundPuzzleId' => $this->blueBox]]]);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertSame('proposed', $answer['status']);
        self::assertNull($answer['reason']);
        self::assertSame('invalid_puzzles', $answer['puzzlesRefused']);
        self::assertIsString($answer['puzzlesMessage']);
        self::assertIsArray($answer['proposal']);
        self::assertArrayNotHasKey('puzzles', $answer['proposal']);
        self::assertNull(self::proposalRow($proposalId)['puzzle_choice']);
    }

    public function testAChoiceTheRoundCannotUseIsSaidNextToTheStoredTime(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->post(self::PROPOSE, [...$this->body(), 'puzzles' => [['position' => 2, 'label' => 'B']]]);

        self::assertResponseIsSuccessful();
        self::assertSame('proposed', $this->json()['status']);
        self::assertSame('puzzles_changed', $this->json()['puzzlesRefused']);
        self::assertSame(1, self::proposalCount());
    }

    public function testARoundWithoutAChoiceSaysSoNextToTheStoredTime(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->post('/en/competitor-proposals/rounds/' . OfficialResultsFixture::ROUND_PAIRS, [...$this->body(), 'entry' => self::UNNAMED_PAIR, 'puzzles' => [['position' => 1, 'label' => 'A']]]);

        self::assertResponseIsSuccessful();
        self::assertSame('proposed', $this->json()['status']);
        self::assertSame('puzzles_not_used', $this->json()['puzzlesRefused']);
    }

    public function testTheCompetitorChangesTheChoiceAndTheJudgesAreTold(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $proposalId = self::newProposalId();
        $this->post(self::PROPOSE, $this->body($proposalId));

        $this->post(self::puzzlesUrl($proposalId), ['puzzles' => [['position' => 3, 'label' => 'C']]]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('chosen', $this->json()['status']);
        self::assertSame([['position' => 3, 'label' => 'C']], self::dig($this->json(), 'proposal', 'puzzles'));
        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertSame('official_results.proposals', self::payload($updates[0])['type']);
        self::assertSame([self::PICK_PUBLIC], self::dig(self::payload($updates[0]), 'proposals', 0, 'puzzles'));

        // A relabel since the phone's state: refused, nothing of the proposal shown, nobody told
        $this->post(self::puzzlesUrl($proposalId), ['puzzles' => [['position' => 2, 'label' => 'B']]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('puzzles_changed', $this->json()['reason']);
        self::assertNull($this->json()['proposal']);
        self::assertSame([], self::hub()->getPublishedUpdates());

        // Cleared
        $this->post(self::puzzlesUrl($proposalId), ['puzzles' => null]);
        $cleared = $this->json();
        self::assertSame('chosen', $cleared['status']);
        self::assertIsArray($cleared['proposal']);
        self::assertArrayNotHasKey('puzzles', $cleared['proposal']);

        // The competitor's own state shows the places only
        $this->post(self::puzzlesUrl($proposalId), ['puzzles' => [['position' => 1, 'label' => 'A']]]);
        $this->browser->request('GET', '/competitor-state/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/p/' . OfficialResultsFixture::PARTICIPANT_ANNA);
        self::assertResponseIsSuccessful();
        $state = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('"puzzles":[{"position":1,"label":"A"}]', $state);
        self::assertStringNotContainsString(self::PICK_SECRET, $state);
        self::assertStringNotContainsString(self::PICK_SECRET_NAME, $state);
    }

    public function testNobodySignedInIsAskedToSignIn(): void
    {
        $this->post(self::puzzlesUrl(self::newProposalId()), ['puzzles' => null]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('sign_in_required', $this->json()['error']);
    }

    public function testAChoiceFromAnotherSiteIsRefused(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->post(self::puzzlesUrl(self::newProposalId()), ['puzzles' => null], origin: 'https://evil.example');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('invalid_csrf_token', $this->json()['error']);
    }

    public function testTheChangeEndpointsRefusals(): void
    {
        $proposalId = self::newProposalId();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->post(self::PROPOSE, $this->body($proposalId));

        $this->post(self::puzzlesUrl($proposalId), ['puzzles' => [['position' => 3, 'label' => 'C']], 'expectedPlayerId' => PlayerFixture::PLAYER_REGULAR]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('another_account', $this->json()['error']);

        // Never read as "clear the choice": a missing key and unreadable puzzles are 400
        $this->post(self::puzzlesUrl($proposalId), ['expectedPlayerId' => PlayerFixture::PLAYER_ADMIN]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_puzzles', $this->json()['error']);
        $this->post(self::puzzlesUrl($proposalId), ['puzzles' => 'A']);
        self::assertResponseStatusCodeSame(400);

        $this->post(self::puzzlesUrl(self::newProposalId()), ['puzzles' => null]);
        self::assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->post(self::puzzlesUrl($proposalId), ['puzzles' => [['position' => 3, 'label' => 'C']]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('not_your_entry', $this->json()['reason']);
        self::assertNull($this->json()['proposal']);
        self::assertNull(self::proposalRow($proposalId)['puzzle_choice']);
    }

    public function testChoosingHasItsOwnBudget(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $proposalId = self::newProposalId();
        $this->post(self::PROPOSE, $this->body($proposalId));

        for ($i = 0; $i < 30; $i++) {
            $this->post(self::puzzlesUrl($proposalId), ['puzzles' => [['position' => 1 + $i % 3, 'label' => ['A', 'Blue box', 'C'][$i % 3]]]]);
            self::assertResponseIsSuccessful();
        }

        $this->post(self::puzzlesUrl($proposalId), ['puzzles' => null]);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->json()['error']);

        // The tap's budget is untouched: a resend is still answered
        $this->post(self::PROPOSE, $this->body($proposalId));
        self::assertSame('known', $this->json()['status']);
    }

    private static function puzzlesUrl(string $proposalId): string
    {
        return '/en/competitor-proposals/' . $proposalId . '/puzzles';
    }

    /**
     * @return array<string, mixed>
     */
    private function body(null|string $proposalId = null): array
    {
        return [
            'proposalId' => $proposalId ?? self::newProposalId(),
            'entry' => self::ANNA_FINAL,
            'tappedAtMs' => self::nowMs() - 2000,
            'offsetMs' => -41.5,
            'rttMs' => 120,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $url, array $body, string $origin = 'http://localhost'): void
    {
        $this->browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
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
