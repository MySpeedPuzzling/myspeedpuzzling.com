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

/**
 * The referee's live entry page (docs/features/competitions-management/live-results.md): organisers and referees only,
 * never cached or indexed, rendered without any data of the round or its reader (competition toolkit C1 - the page
 * fills itself from official_results_round_state?viewer=1 and asks for the event's people when quick add needs them),
 * `?entrant=` resolved to an active participant of the event, `?table=` / `?entry=` handed over when well-formed.
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

    public function testTheOrganiserGetsADataFreePageThatFillsItselfFromTheState(): void
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
        // The state (and the event's people) are asked for by the page - placeholders, filled in by the controller
        self::assertSame('/en/official-results/rounds/00000000-0000-0000-0000-000000000000', $root->attr('data-live-results-state-url-value'));
        self::assertSame('/en/official-results/rounds/00000000-0000-0000-0000-000000000000/event-people', $root->attr('data-live-results-event-people-url-value'));
        self::assertSame('/en/official-results/rounds/00000000-0000-0000-0000-000000000000/changes', $root->attr('data-live-results-record-url-value'));
        self::assertSame('', $root->attr('data-live-results-entrant-value'));
        self::assertSame('', $root->attr('data-live-results-table-value'));
        self::assertSame('', $root->attr('data-live-results-entry-value'));

        // Competition toolkit C1: nothing of the round, its event or the reader is in the page itself
        $html = $crawler->filter('[data-controller="live-results"]')->outerHtml();
        foreach ([PlayerFixture::PLAYER_WITH_STRIPE, OfficialResultsFixture::COMPETITION_RESULTS_CUP, 'Results Cup', 'Group A', 'Group B', 'Anna Fast', 'Gina Quick', '/round-results/', 'initialState', 'eventPeople'] as $data) {
            self::assertStringNotContainsString($data, $html, $data . ' is data of the round or its reader');
        }
        self::assertCount(0, $root->filter('[data-live-results-target="roundSelect"] option[value]:not([value=""])'));
        // The organiser tools are there for everybody, hidden until the state says the reader is an organiser
        self::assertCount(2, $root->filter('[data-live-results-target="organiserLink"][hidden]'));
        self::assertCount(1, $root->filter('[data-live-results-target="readiness"][hidden]'));
        self::assertCount(1, $root->filter('[data-live-results-target="addTableField"][hidden]'));

        // Every text the controller writes comes translated from the page
        $messages = json_decode((string) $root->attr('data-live-results-messages-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);
        self::assertSame('All saved', $messages['syncSaved']);
        self::assertSame('The person on this name tag is not in %round%.', $messages['scanNotInRound']);
        self::assertSame('Nobody sits at table %number% in %round%.', $messages['tableEmpty']);
        self::assertSame('Member __N__', $messages['addMember']);
        self::assertNotContains(null, $messages);
        foreach ($messages as $key => $text) {
            self::assertIsString($text);
            self::assertStringNotContainsString('live_results.', $text, sprintf('"%s" is not translated', $key));
        }

        $plurals = json_decode((string) $root->attr('data-live-results-plurals-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($plurals);
        self::assertSame(['message' => '{1}1 waiting|[0,Inf[%count% waiting', 'locale' => 'en'], $plurals['waiting']);

        // My tables (judge-speed J3): the chip row and the To-do list stay hidden until this phone has tables for the event
        self::assertCount(1, $root->filter('[data-live-results-target="myTablesRow"][hidden]'));
        self::assertCount(1, $root->filter('[data-live-results-target="todoBlock"][hidden]'));
        self::assertCount(1, $root->filter('[data-live-results-target="recentMore"][hidden]'));
        self::assertCount(1, $root->filter('[data-live-results-prefs] [data-live-results-target="myTablesInput"]'));
        self::assertSame('My tables %tables%', $messages['myTablesChip']);
        self::assertSame(['message' => '{1}+ 1 at another table|[0,Inf[+ %count% at other tables', 'locale' => 'en'], $plurals['otherTables']);
    }

    public function testTheStateFillsThePageWithTheRoundsAndTheReader(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $state = $this->state(OfficialResultsFixture::ROUND_GROUP_A);

        // Who reads it: the outbox's owner, and the organiser tools shown
        self::assertSame(['playerId' => PlayerFixture::PLAYER_WITH_STRIPE, 'organiser' => true], $state['viewer']);
        // The round picker lists every round of the event
        self::assertIsArray($state['rounds']);
        self::assertSame(['Group A', 'Group B', 'Pairs', 'Final', 'Pairs Final'], array_column($state['rounds'], 'name'));
        self::assertIsArray($state['entries']);
        self::assertSame(
            ['Anna Fast', 'Ben Steady', 'Cara Tied', 'Dan Unfinished', 'Eva Noshow', 'Filip Pending'],
            array_column($state['entries'], 'name'),
        );
        self::assertSame('/round-results/' . OfficialResultsFixture::ROUND_GROUP_A, $state['topic']);
        self::assertIsArray($state['round']);
        self::assertSame(1000, $state['round']['piecesCount']);
        // Solo round with tables: a person is added, the table field shown (live_entry_page.js pageSettings())
        self::assertSame('solo', $state['round']['category']);
        self::assertFalse($state['round']['tableNumbersOff']);
        self::assertIsArray($state['competition']);
        self::assertFalse($state['competition']['isOnline']);
        self::assertIsString($state['serverNow']);
    }

    public function testTheViewerAndTheEntriesAreOptIn(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // The other consumers (desk, sheet, seating) read the state as before - no viewer
        $plain = $this->state(OfficialResultsFixture::ROUND_GROUP_A, '');
        self::assertArrayNotHasKey('viewer', $plain);
        self::assertArrayHasKey('entries', $plain);

        foreach (['?viewer=0', '?viewer=true', '?entries=1'] as $query) {
            $state = $this->state(OfficialResultsFixture::ROUND_GROUP_A, $query);
            self::assertArrayNotHasKey('viewer', $state, $query);
            self::assertArrayHasKey('entries', $state, $query);
        }

        // ?entries=0: the clock and the token, nothing of the entries
        $light = $this->state(OfficialResultsFixture::ROUND_GROUP_A, '?entries=0');
        self::assertArrayNotHasKey('entries', $light);
        self::assertArrayNotHasKey('viewer', $light);
        self::assertSame(['serverNow', 'serverNowMs', 'topic', 'competition', 'round', 'rounds', 'mercure'], array_keys($light));

        $both = $this->state(OfficialResultsFixture::ROUND_GROUP_A, '?viewer=1&entries=0');
        self::assertSame(['serverNow', 'serverNowMs', 'topic', 'competition', 'round', 'rounds', 'mercure', 'viewer'], array_keys($both));
    }

    public function testARoundWithoutAnyOfficialResultsOpensAsWell(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('GET', '/en/live-results/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        self::assertResponseIsSuccessful();

        $state = $this->state(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        self::assertIsArray($state['entries']);
        self::assertNotContains(true, array_map(static fn (mixed $entry): bool => is_array($entry) && $entry['result'] !== null, $state['entries']));
        self::assertIsArray($state['viewer']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $state['viewer']['playerId']);
    }

    public function testAPairRoundAsksForMembers(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', '/en/live-results/' . OfficialResultsFixture::ROUND_PAIRS);

        self::assertResponseIsSuccessful();
        // The member fields are put in by the controller (2 for a pair) - the field set is there, hidden
        self::assertCount(1, $crawler->filter('[data-live-results-target="addMembersField"][hidden]'));
        $state = $this->state(OfficialResultsFixture::ROUND_PAIRS);
        self::assertIsArray($state['round']);
        self::assertSame('duo', $state['round']['category']);
        self::assertIsArray($state['entries']);
        self::assertContains('Puzzle Sharks', array_column($state['entries'], 'name'));
    }

    public function testQuickAddKnowsTheEventsPeopleWhoAreNoEntryOfTheRoundYet(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Group A: the people of Group B are in the event, not in this round
        $solo = $this->eventPeople(OfficialResultsFixture::ROUND_GROUP_A);
        self::assertSame(['Gina Quick', 'Hugo Slow', 'Ivan Last'], array_column($solo, 'name'));
        self::assertSame(OfficialResultsFixture::PARTICIPANT_GINA, $solo[0]['participantId']);
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));

        // Pairs: everybody in a pair already except Ivan
        self::assertSame(['Ivan Last'], array_column($this->eventPeople(OfficialResultsFixture::ROUND_PAIRS), 'name'));
    }

    public function testTheEventsPeopleAreForWhoeverMayEnterResultsOnly(): void
    {
        $this->browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/event-people');
        self::assertResponseStatusCodeSame(401);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/event-people');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventPeople(string $roundId): array
    {
        $this->browser->request('GET', '/en/official-results/rounds/' . $roundId . '/event-people');
        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertIsArray($answer['people']);

        /** @var list<array<string, mixed>> $people */
        $people = $answer['people'];

        return $people;
    }

    public function testTheEntrantOfANameTagIsHandedToThePage(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE . '?entrant=' . OfficialResultsFixture::PARTICIPANT_BEN);

        $root = $crawler->filter('[data-controller="live-results"]');
        self::assertSame(OfficialResultsFixture::PARTICIPANT_BEN, $root->attr('data-live-results-entrant-value'));
        // The name is the round state's (or the event's people's) - never the page's
        self::assertNull($root->attr('data-live-results-entrant-name-value'));
        self::assertStringNotContainsString('Ben Steady', (string) $this->browser->getResponse()->getContent());
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

    public function testATableOrAnEntryToOpenIsHandedToThePage(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE . '?table=12');
        self::assertResponseIsSuccessful();
        self::assertSame('12', $crawler->filter('[data-controller="live-results"]')->attr('data-live-results-table-value'));

        $ref = 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP;
        $crawler = $this->browser->request('GET', self::PAGE . '?entry=' . strtoupper($ref));
        self::assertSame(strtolower($ref), $crawler->filter('[data-controller="live-results"]')->attr('data-live-results-entry-value'));

        $crawler = $this->browser->request('GET', self::PAGE . '?entry=team:' . OfficialResultsFixture::ENTRY_A_FILIP);
        self::assertSame('team:' . OfficialResultsFixture::ENTRY_A_FILIP, $crawler->filter('[data-controller="live-results"]')->attr('data-live-results-entry-value'));
    }

    public function testAMalformedTableOrEntryIsIgnored(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach (['?table=0', '?table=12a', '?table=10000', '?table=-1', '?entry=person:' . OfficialResultsFixture::ENTRY_A_FILIP, '?entry=team:x', '?entry=%3Cscript%3E'] as $query) {
            $crawler = $this->browser->request('GET', self::PAGE . $query);

            self::assertResponseIsSuccessful();
            $root = $crawler->filter('[data-controller="live-results"]');
            self::assertSame('', $root->attr('data-live-results-table-value'), $query);
            self::assertSame('', $root->attr('data-live-results-entry-value'), $query);
        }
    }

    public function testThePageFollowsTheRoundWithItsOwnTokenNotTheCookie(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        // Never in a URL (proxies log them) - the page sends it as a header
        self::assertCount(0, $crawler->filter('[data-live-results-mercure-url-value]'));

        $mercure = $this->state(OfficialResultsFixture::ROUND_GROUP_A)['mercure'] ?? null;
        self::assertIsArray($mercure);
        self::assertSame(['/round-results/' . OfficialResultsFixture::ROUND_GROUP_A, '/round-stopwatch/' . OfficialResultsFixture::ROUND_GROUP_A], $mercure['topics']);
        self::assertIsString($mercure['token']);
    }

    /**
     * The round state as the live entry page asks for it (`?viewer=1`).
     *
     * @return array<string, mixed>
     */
    private function state(string $roundId, string $query = '?viewer=1'): array
    {
        $this->browser->request('GET', '/en/official-results/rounds/' . $roundId . $query);
        self::assertResponseIsSuccessful();
        $state = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);

        /** @var array<string, mixed> $state */
        return $state;
    }
}
