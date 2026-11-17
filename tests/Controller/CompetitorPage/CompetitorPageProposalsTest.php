<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitorPage;

use SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals\TimeProposalScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Done - propose my time" on the public competitor page (competition toolkit B8, docs/features/competitions-management/
 * competitor-pages.md "Propose my time"): the page carries the Stop button's endpoints and the round its `canPropose`
 * + the competitor's own latest proposal only for the signed-in linked competitor on their own entry with time
 * proposals on - never for somebody else, a guest or an organiser looking at the page; a guest on a linked entrant's
 * page is told to sign in (before the round starts) without anything saying who. Results Cup: Anna (linked to
 * PLAYER_ADMIN 20 days ago) plays the Final.
 */
final class CompetitorPageProposalsTest extends WebTestCase
{
    use TimeProposalScenario;

    private const string ANNA_PAGE = '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_ANNA;
    private const string ANNA_STATE = '/competitor-state/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_ANNA;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
    }

    public function testTheCompetitorOnTheirOwnRunningEntryGetsTheStopButton(): void
    {
        self::runningRounds();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', self::ANNA_PAGE);
        self::assertResponseIsSuccessful();
        $root = $crawler->filter('[data-competitor-page]');
        self::assertSame('/en/competitor-proposals/rounds/00000000-0000-0000-0000-000000000000', $root->attr('data-competitor-page-propose-url-value'));
        self::assertSame('/en/competitor-proposals/00000000-0000-0000-0000-000000000000/withdraw', $root->attr('data-competitor-page-withdraw-url-value'));
        self::assertNotEmpty($root->attr('data-competitor-page-csrf-token-value'));
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $root->attr('data-competitor-page-viewer-value'));
        /** @var array<string, string> $labels */
        $labels = json_decode((string) $root->attr('data-competitor-page-proposal-labels-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Propose %time%?', $labels['confirm_question'] ?? null);

        $final = $this->stateRound($this->pageState($crawler), F::ROUND_FINAL);
        self::assertTrue($final['canPropose']);
        self::assertArrayHasKey('proposal', $final);
        self::assertNull($final['proposal']);
        self::assertCount(0, $crawler->filter('[data-competitor-signin-hint]'));

        // The same in the JSON, on the entry page too (a tag at your own seat works like your person page)
        $this->browser->request('GET', self::ANNA_STATE);
        self::assertTrue($this->stateRound($this->json(), F::ROUND_FINAL)['canPropose']);
        $this->browser->request('GET', '/competitor-state/' . F::COMPETITION_RESULTS_CUP . '/e/' . F::ENTRY_FINAL_ANNA);
        self::assertTrue($this->stateRound($this->json(), F::ROUND_FINAL)['canPropose']);
    }

    public function testTheCompetitorSeesTheirOwnProposalWithoutTheJudgesIdentity(): void
    {
        self::runningRounds();
        $proposalId = self::newProposalId(-3000);
        self::propose(self::ANNA_FINAL, proposalId: $proposalId, tappedAgoMs: 3000);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('GET', self::ANNA_STATE);
        $proposal = $this->stateRound($this->json(), F::ROUND_FINAL)['proposal'] ?? null;
        self::assertIsArray($proposal);
        self::assertSame($proposalId, $proposal['id']);
        self::assertSame('pending', $proposal['status']);
        self::assertSame(597, $proposal['proposedSeconds']);

        // The judge confirms it with another value: "Confirmed as …" - who decided never reaches the phone
        self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 600], $proposalId)]);
        $this->browser->request('GET', self::ANNA_STATE);
        $content = (string) $this->browser->getResponse()->getContent();
        $proposal = $this->stateRound($this->json(), F::ROUND_FINAL)['proposal'] ?? null;
        self::assertIsArray($proposal);
        self::assertSame('confirmed', $proposal['status']);
        self::assertIsArray($proposal['decided']);
        self::assertSame(600, $proposal['decided']['seconds']);
        self::assertArrayNotHasKey('byPlayerId', $proposal['decided']);
        self::assertArrayNotHasKey('byName', $proposal['decided']);
        self::assertIsArray($proposal['proposedBy']);
        self::assertArrayNotHasKey('linkedAt', $proposal['proposedBy']);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_STRIPE, $content);
    }

    /**
     * Toolkit H3: the judge's Undo of a confirmation leaves the phone a proposal that lost its confirmation, the entry
     * without a result and the Stop button back (canPropose) - and a new proposal goes through
     */
    public function testAnUndoneConfirmationGivesTheCompetitorTheButtonBack(): void
    {
        self::runningRounds();
        $proposalId = self::newProposalId(-3000);
        self::propose(self::ANNA_FINAL, proposalId: $proposalId, tappedAgoMs: 3000);
        self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 597], $proposalId)]);
        self::record([self::resultChange(self::ANNA_FINAL, ['seconds' => 597], null)]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('GET', self::ANNA_STATE);
        $round = $this->stateRound($this->json(), F::ROUND_FINAL);
        self::assertTrue($round['canPropose']);
        self::assertIsArray($round['entry']);
        self::assertNull($round['entry']['result'] ?? null);
        $proposal = $round['proposal'] ?? null;
        self::assertIsArray($proposal);
        self::assertSame($proposalId, $proposal['id']);
        self::assertSame('superseded', $proposal['status']);
        self::assertIsArray($proposal['decided']);
        self::assertSame('result_cleared', $proposal['decided']['reason']);

        self::assertSame('proposed', self::propose(self::ANNA_FINAL, tappedAgoMs: 1000)->status);
    }

    public function testAPairMemberProposesForTheirPair(): void
    {
        self::runningRounds();
        self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_REGULAR, roundId: F::ROUND_PAIRS);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        // Filip's person page: his pair's round is his own entry, with the pair's pending proposal
        $this->browser->request('GET', '/competitor-state/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_FILIP);
        $pairs = $this->stateRound($this->json(), F::ROUND_PAIRS);
        self::assertTrue($pairs['canPropose']);
        self::assertIsArray($pairs['proposal']);
        self::assertSame('pending', $pairs['proposal']['status']);
        self::assertSame(self::UNNAMED_PAIR, $pairs['proposal']['entry']);
    }

    public function testNobodyElseGetsTheButtonNorTheProposal(): void
    {
        self::runningRounds();
        self::propose(self::ANNA_FINAL);

        // Somebody else signed in, an organiser of the event, a guest
        foreach ([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE, null] as $viewer) {
            $this->browser->restart();
            $this->browser->disableReboot();
            if ($viewer !== null) {
                TestingLogin::asPlayer($this->browser, $viewer);
            }

            $crawler = $this->browser->request('GET', self::ANNA_PAGE);
            self::assertResponseIsSuccessful();
            $label = $viewer ?? 'guest';
            self::assertNull($crawler->filter('[data-competitor-page]')->attr('data-competitor-page-propose-url-value'), $label);
            self::assertNull($crawler->filter('[data-competitor-page]')->attr('data-competitor-page-csrf-token-value'), $label);

            $this->browser->request('GET', self::ANNA_STATE);
            $content = (string) $this->browser->getResponse()->getContent();
            self::assertStringNotContainsString('canPropose', $content, $label);
            self::assertStringNotContainsString('"proposal"', $content, $label);
        }
    }

    public function testWithProposalsOffNothingChanges(): void
    {
        self::runningRounds();
        self::proposalsOn(false);
        self::db()->executeStatement('UPDATE competition SET competitor_pages = true WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', self::ANNA_PAGE);
        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('[data-competitor-page]')->attr('data-competitor-page-propose-url-value'));
        $this->browser->request('GET', self::ANNA_STATE);
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringNotContainsString('canPropose', $content);
        self::assertStringNotContainsString('signInToPropose', $content);
    }

    public function testAGuestOnALinkedEntrantsPageIsAskedToSignInBeforeTheStartOnly(): void
    {
        self::proposalsOn();

        $crawler = $this->browser->request('GET', self::ANNA_PAGE);
        self::assertResponseIsSuccessful();
        $hint = $this->card($crawler, F::ROUND_FINAL)->filter('[data-competitor-signin-hint]');
        self::assertCount(1, $hint);
        self::assertSame('/login?return=' . self::ANNA_PAGE, $hint->filter('a')->attr('href'));
        self::assertStringNotContainsString(PlayerFixture::PLAYER_ADMIN, $hint->html(), 'nothing says who is linked');
        self::assertTrue($this->stateRound($this->pageState($crawler), F::ROUND_FINAL)['signInToPropose']);

        // Ivan has no linked profile: nobody could propose there
        $crawler = $this->browser->request('GET', '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_IVAN);
        self::assertCount(0, $crawler->filter('[data-competitor-signin-hint]'));

        // The round runs: no nag (the JSON keeps the fact; the page's controller hides a hint drawn before)
        self::stopwatch(F::ROUND_FINAL, TestClock::now()->modify('-1 minute'));
        $crawler = $this->browser->request('GET', self::ANNA_PAGE);
        self::assertCount(0, $this->card($crawler, F::ROUND_FINAL)->filter('[data-competitor-signin-hint]'));

        // Signed in as somebody else: no hint either
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        self::stopwatch(F::ROUND_FINAL, null);
        $crawler = $this->browser->request('GET', self::ANNA_PAGE);
        self::assertCount(0, $crawler->filter('[data-competitor-signin-hint]'));
    }

    public function testTheCurrentRoundCardIsMarkedForPhones(): void
    {
        self::runningRounds();

        $crawler = $this->browser->request('GET', self::ANNA_PAGE);
        // The running Final is the current round - first on a phone (CSS order on .is-current), last in the HTML order
        self::assertStringContainsString('is-current', (string) $this->card($crawler, F::ROUND_FINAL)->attr('class'));
    }

    private function card(Crawler $crawler, string $roundId): Crawler
    {
        $card = $crawler->filter('[data-competitor-round="' . $roundId . '"]');
        self::assertCount(1, $card);

        return $card;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageState(Crawler $crawler): array
    {
        /** @var array<string, mixed> $state */
        $state = json_decode((string) $crawler->filter('[data-competitor-page]')->attr('data-competitor-page-state-value'), true, flags: JSON_THROW_ON_ERROR);

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $json */
        $json = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $json;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function stateRound(array $state, string $roundId): array
    {
        /** @var list<array<string, mixed>> $rounds */
        $rounds = $state['rounds'] ?? [];
        foreach ($rounds as $round) {
            if (($round['roundId'] ?? null) === $roundId) {
                return $round;
            }
        }

        self::fail('No round ' . $roundId);
    }
}
