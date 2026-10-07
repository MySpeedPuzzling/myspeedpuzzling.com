<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\LiveResults;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The referee's live entry page (docs/features/competitions-management/live-results.md): organisers only, never cached
 * or indexed, rendered with the round's state, and `?entrant=` resolved to an active participant of the event.
 */
final class LiveResultsPageTest extends WebTestCase
{
    private const string PAGE = '/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testSignedOutVisitorsAreSentToSignIn(): void
    {
        $this->browser->request('GET', self::PAGE);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->browser->getResponse()->headers->get('Location'));
    }

    public function testOnlyTheEventsOrganisersMayOpenIt(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', self::PAGE);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownRoundIsNotFound(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', '/en/live-results/018d0020-0000-0000-0000-00000000ffff');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheOrganiserGetsThePageWithTheRoundsState(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $response = $this->browser->getResponse();
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));

        $root = $crawler->filter('[data-controller="live-results"]');
        self::assertCount(1, $root);
        self::assertSame(OfficialResultsFixture::ROUND_GROUP_A, $root->attr('data-live-results-round-id-value'));
        self::assertSame(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $root->attr('data-live-results-competition-id-value'));
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $root->attr('data-live-results-user-id-value'));
        self::assertSame('/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A, $root->attr('data-live-results-state-url-value'));
        self::assertSame('/en/official-results/rounds/00000000-0000-0000-0000-000000000000/changes', $root->attr('data-live-results-record-url-value'));
        self::assertSame('', $root->attr('data-live-results-entrant-value'));

        // Every text the controller writes comes translated from the page
        $messages = json_decode((string) $root->attr('data-live-results-messages-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);
        self::assertSame('All saved', $messages['syncSaved']);
        self::assertNotContains(null, $messages);
        foreach ($messages as $key => $text) {
            self::assertIsString($text);
            self::assertStringNotContainsString('live_results.', $text, sprintf('"%s" is not translated', $key));
        }

        $plurals = json_decode((string) $root->attr('data-live-results-plurals-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($plurals);
        self::assertSame(['message' => '{1}1 waiting|]1,Inf[%count% waiting', 'locale' => 'en'], $plurals['waiting']);

        // The round picker lists every round of the event, this one selected
        self::assertSame(['Group A', 'Group B', 'Pairs', 'Final', 'Pairs Final'], $crawler->filter('select[data-live-results-target="roundSelect"] option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame(OfficialResultsFixture::ROUND_GROUP_A, $crawler->filter('select[data-live-results-target="roundSelect"] option[selected]')->attr('value'));

        $state = $this->state($crawler);
        self::assertIsArray($state['entries']);
        self::assertSame(
            ['Anna Fast', 'Ben Steady', 'Cara Tied', 'Dan Unfinished', 'Eva Noshow', 'Filip Pending'],
            array_column($state['entries'], 'name'),
        );
        self::assertSame('/round-results/' . OfficialResultsFixture::ROUND_GROUP_A, $state['topic']);
        self::assertIsArray($state['round']);
        self::assertSame(1000, $state['round']['piecesCount']);
        self::assertIsString($state['serverNow']);

        // Solo round: a person is added, the table field is there (in person, tables used)
        self::assertCount(1, $crawler->filter('[data-live-results-target="addName"]'));
        self::assertCount(0, $crawler->filter('input[data-member]'));
        self::assertCount(1, $crawler->filter('[data-live-results-target="addTable"]'));
    }

    public function testARoundWithoutAnyOfficialResultsOpensAsWell(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', '/en/live-results/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        self::assertResponseIsSuccessful();
        $state = $this->state($crawler);
        self::assertIsArray($state['entries']);
        self::assertNotContains(true, array_map(static fn (mixed $entry): bool => is_array($entry) && $entry['result'] !== null, $state['entries']));
    }

    public function testAPairRoundAsksForMembers(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', '/en/live-results/' . OfficialResultsFixture::ROUND_PAIRS);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('input[data-member]'));
        $state = $this->state($crawler);
        self::assertIsArray($state['entries']);
        self::assertContains('Puzzle Sharks', array_column($state['entries'], 'name'));
    }

    public function testTheEntrantOfANameTagIsHandedToThePage(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE . '?entrant=' . OfficialResultsFixture::PARTICIPANT_BEN);

        $root = $crawler->filter('[data-controller="live-results"]');
        self::assertSame(OfficialResultsFixture::PARTICIPANT_BEN, $root->attr('data-live-results-entrant-value'));
        self::assertSame('Ben Steady', $root->attr('data-live-results-entrant-name-value'));
    }

    public function testAnEntrantOfAnotherEventOrNoEntrantAtAllIsIgnored(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach ([CompetitionParticipantFixture::PARTICIPANT_CONNECTED, 'not-a-uuid'] as $entrant) {
            $crawler = $this->browser->request('GET', self::PAGE . '?entrant=' . $entrant);

            self::assertResponseIsSuccessful();
            self::assertSame('', $crawler->filter('[data-controller="live-results"]')->attr('data-live-results-entrant-value'));
        }
    }

    public function testTheStateAnswerKeepsTheRoundsPrivateTopicInTheMercureCookie(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A);

        self::assertResponseIsSuccessful();
        self::assertContains('/round-results/' . OfficialResultsFixture::ROUND_GROUP_A, $this->subscribedTopics());
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Crawler $crawler): array
    {
        $state = json_decode($crawler->filter('script[data-live-results-target="initialState"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);

        /** @var array<string, mixed> $state */
        return $state;
    }

    /**
     * @return list<string>
     */
    private function subscribedTopics(): array
    {
        foreach ($this->browser->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() !== 'mercureAuthorization') {
                continue;
            }

            $parts = explode('.', (string) $cookie->getValue());
            $payload = json_decode((string) base64_decode(strtr($parts[1] ?? '', '-_', '+/'), true), true);

            if (is_array($payload) && is_array($payload['mercure'] ?? null) && is_array($payload['mercure']['subscribe'] ?? null)) {
                /** @var list<string> */
                return $payload['mercure']['subscribe'];
            }
        }

        self::fail('No Mercure subscriber cookie was set.');
    }
}
