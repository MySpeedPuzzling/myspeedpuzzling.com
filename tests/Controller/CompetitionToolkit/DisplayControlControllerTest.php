<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplaysPresence;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\CreatesCompetitionDisplays;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The live control of an event's displays (competition toolkit v2 L1, displays-standings.md §5.3): the page, its JSON
 * state and the message endpoint are the organisers' (COMPETITION_TOOLKIT_MANAGE on the event - never a referee); a
 * display shows the message only with a Message element, every other design answers exactly as before.
 */
final class DisplayControlControllerTest extends WebTestCase
{
    use CreatesCompetitionDisplays;
    use QueryCountAssertions;

    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string PAGE = '/en/display-control/' . self::EVENT;
    private const string STATE = self::PAGE . '/state';
    private const string MESSAGE = self::PAGE . '/message';

    public function testTheOrganiserSetsAMessageAndTheDisplaysShowIt(): void
    {
        $browser = $this->browser(self::ORGANISER);
        $withMessage = self::createDisplay(self::EVENT, [['type' => 'message', 'props' => ['whenEmpty' => 'text', 'emptyText' => 'Welcome']], ['type' => 'timer']], OfficialResultsFixture::ROUND_GROUP_A);
        $withoutMessage = self::createDisplay(self::EVENT, [['type' => 'timer']], OfficialResultsFixture::ROUND_GROUP_A);
        [$rawBefore] = self::displayState($browser, $withoutMessage);

        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="display-control"]');
        self::assertSame('no-store, private', $browser->getResponse()->headers->get('Cache-Control'));

        [, $state] = self::displayState($browser, $withMessage);
        self::assertSame(['message' => null, 'spotlight' => null], $state['control']);

        $answer = $this->post($browser, self::MESSAGE, ['text' => "Break -\nnext round 14:30"]);
        self::assertResponseIsSuccessful();
        self::assertSame(['message' => 'Break - next round 14:30', 'recentMessages' => ['Break - next round 14:30']], $answer);

        [, $state] = self::displayState($browser, $withMessage);
        self::assertSame('Break - next round 14:30', self::at($state, 'control.message'));

        // A design without a Message element: no `control` key, the answer as it was (the clock aside)
        [$rawAfter, $other] = self::displayState($browser, $withoutMessage);
        self::assertArrayNotHasKey('control', $other);
        self::assertStringNotContainsString('Break', $rawAfter);
        self::assertSame(self::withoutClock($rawBefore), self::withoutClock($rawAfter));

        $browser->request('GET', self::STATE);
        self::assertResponseIsSuccessful();
        $control = self::json($browser);
        self::assertSame('Break - next round 14:30', $control['message']);
        self::assertSame(['Break - next round 14:30'], $control['recentMessages']);
        self::assertSame(2, $control['displays']);
        self::assertSame(['message' => 1, 'spotlight' => 0], $control['elements']);
        self::assertIsString($control['roundId']);

        // Clear: the displays show their own text again, the recent one stays
        self::assertSame(['message' => null, 'recentMessages' => ['Break - next round 14:30']], $this->post($browser, self::MESSAGE, ['text' => null]));
        [, $state] = self::displayState($browser, $withMessage);
        self::assertNull(self::at($state, 'control.message'));
    }

    public function testTheMessageCostsNoStatementOnTheDisplay(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(self::EVENT, [['type' => 'message'], ['type' => 'timer']], OfficialResultsFixture::ROUND_GROUP_A);

        $this->startCountingQueries($browser);
        self::displayState($browser, $token);
        self::assertSame(3, $this->queryCount($browser));
    }

    public function testRefusals(): void
    {
        $browser = $this->browser(self::ORGANISER);

        // No display yet
        self::assertSame(['error' => 'no_displays'], $this->post($browser, self::MESSAGE, ['text' => 'Hello']));
        self::assertResponseStatusCodeSame(422);

        self::createDisplay(self::EVENT, [['type' => 'message']]);
        self::assertSame(['error' => 'too_long'], $this->post($browser, self::MESSAGE, ['text' => str_repeat('x', 201)]));
        self::assertResponseStatusCodeSame(422);

        $this->post($browser, self::MESSAGE, ['text' => ['not', 'a', 'text']]);
        self::assertResponseStatusCodeSame(400);

        // No CSRF header, not JSON
        $browser->request('POST', self::MESSAGE, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: '{"text":"x"}');
        self::assertResponseStatusCodeSame(403);
        $browser->request('POST', self::MESSAGE, ['text' => 'x'], server: self::headers());
        self::assertResponseStatusCodeSame(415);

        self::assertFalse(self::getContainer()->get(Connection::class)->fetchOne('SELECT message FROM competition_display_control WHERE competition_id = :id', ['id' => self::EVENT]));
    }

    public function testOnlyTheEventsOrganisers(): void
    {
        $browser = self::createClient();
        self::createDisplay(self::EVENT, [['type' => 'message']]);

        // A referee of the event enters results - the live control is the organisers'
        $referee = PlayerFixture::PLAYER_REGULAR;
        self::getContainer()->get(Connection::class)->insert('competition_referee', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => self::EVENT,
            'player_id' => $referee,
            'added_by_id' => self::ORGANISER,
            'added_at' => '2026-10-01 10:00:00',
        ]);

        TestingLogin::asPlayer($browser, $referee);
        $browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);
        $browser->request('GET', self::STATE);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $this->post($browser, self::MESSAGE, ['text' => 'Hijacked']));
        self::assertResponseStatusCodeSame(403);

        // An admin may
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
    }

    public function testSignedOutIsAskedToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::STATE);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'sign_in_required'], $this->post($browser, self::MESSAGE, ['text' => 'x']));
        $browser->request('GET', self::PAGE);
        self::assertResponseRedirects();
    }

    public function testTheDisplaysListLinksTheLiveControlOnceThereIsADisplay(): void
    {
        $browser = $this->browser(self::ORGANISER);
        $browser->request('GET', '/en/manage-displays/' . self::EVENT);
        self::assertSelectorNotExists('[data-display-control-link]');

        self::createDisplay(self::EVENT, [['type' => 'timer']]);
        $browser->request('GET', '/en/manage-displays/' . self::EVENT);
        self::assertSelectorExists('[data-display-control-link][href="' . self::PAGE . '"]');
    }

    public function testOneTapPutsAnEntryInTheSpotlightOfItsRoundsDisplays(): void
    {
        $browser = $this->browser(self::ORGANISER);
        $chosen = ['type' => 'spotlight', 'props' => ['pick' => 'chosen', 'show' => ['table', 'name', 'flag', 'result', 'rank']]];
        $groupB = self::createDisplay(self::EVENT, [$chosen], OfficialResultsFixture::ROUND_GROUP_B);
        $groupBLive = self::createDisplay(self::EVENT, [['type' => 'spotlight', 'props' => ['source' => 'live']]], OfficialResultsFixture::ROUND_GROUP_B);
        $groupA = self::createDisplay(self::EVENT, [$chosen], OfficialResultsFixture::ROUND_GROUP_A);
        $spotlightUrl = '/en/display-control/round/' . OfficialResultsFixture::ROUND_GROUP_B . '/spotlight';

        [, $state] = self::displayState($browser, $groupB);
        self::assertSame(['message' => null, 'spotlight' => null], $state['control']);

        // The picker: Group B's entries with the organiser's names, by the round asked for
        $browser->request('GET', self::STATE . '?round=' . OfficialResultsFixture::ROUND_GROUP_B);
        $control = self::json($browser);
        self::assertSame(OfficialResultsFixture::ROUND_GROUP_B, $control['roundId']);
        self::assertSame(['message' => 0, 'spotlight' => 3], $control['elements']);
        self::assertIsArray($control['entries']);
        self::assertContains('Gina Quick', array_column($control['entries'], 'name'));
        self::assertNull($control['spotlight']);

        // Gina is the private PLAYER_PRIVATE ("Jane Smith"): the organiser's name only, everywhere
        $gina = 'participant_round:' . OfficialResultsFixture::ENTRY_B_GINA;
        $answer = $this->post($browser, $spotlightUrl, ['entryRef' => $gina]);
        self::assertResponseIsSuccessful();
        self::assertSame(['spotlight' => ['entryRef' => $gina, 'name' => 'Gina Quick', 'table' => 1]], $answer);

        // Group B is not published: who and where, no time and no rank - unless the element is live
        [$raw, $state] = self::displayState($browser, $groupB);
        self::assertSame('Gina Quick', self::at($state, 'control.spotlight.name'));
        self::assertNull(self::at($state, 'control.spotlight.rank'));
        self::assertNull(self::at($state, 'control.spotlight.resultSeconds'));
        $key = self::at($state, 'control.spotlight.key');
        self::assertIsString($key);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $key);
        self::assertStringNotContainsString('Jane Smith', $raw);
        self::assertStringNotContainsString(OfficialResultsFixture::ENTRY_B_GINA, $raw);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, $raw);
        [, $live] = self::displayState($browser, $groupBLive);
        self::assertSame(1, self::at($live, 'control.spotlight.rank'));
        self::assertSame(3900, self::at($live, 'control.spotlight.resultSeconds'));
        self::assertSame(1, self::at($live, 'control.spotlight.tableNumber'));

        // A display of another round shows nobody
        [, $other] = self::displayState($browser, $groupA);
        self::assertNull(self::at($other, 'control.spotlight'));

        $browser->request('GET', self::STATE . '?round=' . OfficialResultsFixture::ROUND_GROUP_B);
        self::assertSame(['entryRef' => $gina, 'name' => 'Gina Quick', 'table' => 1], self::json($browser)['spotlight']);

        // Clear
        self::assertSame(['spotlight' => null], $this->post($browser, $spotlightUrl, ['entryRef' => null]));
        [, $state] = self::displayState($browser, $groupB);
        self::assertNull(self::at($state, 'control.spotlight'));
    }

    public function testTheSpotlightCostsTheEntriesStatementOnlyWhenSomebodyIsInIt(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(self::EVENT, [['type' => 'spotlight'], ['type' => 'timer']], OfficialResultsFixture::ROUND_GROUP_B);

        $this->startCountingQueries($browser);
        self::displayState($browser, $token);
        self::assertSame(3, $this->queryCount($browser));

        self::getContainer()->get(Connection::class)->insert('competition_round_spotlight', [
            'round_id' => OfficialResultsFixture::ROUND_GROUP_B,
            'entry_ref' => 'participant_round:' . OfficialResultsFixture::ENTRY_B_HUGO,
            'set_at' => '2026-10-14 12:00:00',
        ]);

        $this->startCountingQueries($browser);
        [, $state] = self::displayState($browser, $token);
        self::assertSame(4, $this->queryCount($browser));
        self::assertSame('Hugo Slow', self::at($state, 'control.spotlight.name'));

        // A latest-finisher spotlight with a leaderboard and a ticker: still one entries statement
        $published = self::createDisplay(self::EVENT, [['type' => 'spotlight', 'props' => ['pick' => 'latest_finisher']], ['type' => 'leaderboard'], ['type' => 'ticker']], OfficialResultsFixture::ROUND_GROUP_A);
        $this->startCountingQueries($browser);
        [, $state] = self::displayState($browser, $published);
        self::assertSame(4, $this->queryCount($browser));
        self::assertNotSame([], self::at($state, 'results.latest'));
    }

    public function testSpotlightRefusals(): void
    {
        $browser = self::createClient();
        $spotlightUrl = '/en/display-control/round/' . OfficialResultsFixture::ROUND_GROUP_A . '/spotlight';
        $anna = 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA;

        TestingLogin::asPlayer($browser, self::ORGANISER);
        self::assertSame(['error' => 'no_displays'], $this->post($browser, $spotlightUrl, ['entryRef' => $anna]));
        self::assertResponseStatusCodeSame(422);

        self::createDisplay(self::EVENT, [['type' => 'spotlight']]);
        self::assertSame(['error' => 'unknown_entry'], $this->post($browser, $spotlightUrl, ['entryRef' => 'participant_round:' . OfficialResultsFixture::ENTRY_B_GINA]));
        self::assertResponseStatusCodeSame(422);
        $this->post($browser, $spotlightUrl, ['entryRef' => 12]);
        self::assertResponseStatusCodeSame(400);
        $this->post($browser, '/en/display-control/round/' . Uuid::uuid7()->toString() . '/spotlight', ['entryRef' => $anna]);
        self::assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertSame(['error' => 'forbidden'], $this->post($browser, $spotlightUrl, ['entryRef' => $anna]));

        self::assertFalse(self::getContainer()->get(Connection::class)->fetchOne('SELECT entry_ref FROM competition_round_spotlight'));
    }

    public function testTheStopwatchPageOffersTheSpotlightOnlyWithDisplays(): void
    {
        $browser = $this->browser(self::ORGANISER);
        $page = '/en/manage-round-stopwatch/' . OfficialResultsFixture::ROUND_GROUP_A;

        $browser->request('GET', $page);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-display-spotlight-card]');
        self::assertStringNotContainsString('display-control', (string) $browser->getResponse()->getContent());

        self::createDisplay(self::EVENT, [['type' => 'spotlight']]);
        self::getContainer()->get(CompetitionDisplaysPresence::class)->changed(self::EVENT);

        $browser->request('GET', $page);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-display-spotlight-card][data-controller="display-control"]');
        self::assertSelectorExists('[data-display-control-page-link][href="' . self::PAGE . '?round=' . OfficialResultsFixture::ROUND_GROUP_A . '"]');
    }

    private static function withoutClock(string $raw): string
    {
        return (string) preg_replace('/"(serverNowMs|asOfMs|token|expiresIn)":("[^"]*"|\d+)/', '"$1":0', $raw);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<mixed>
     */
    private function post(KernelBrowser $browser, string $url, array $payload): array
    {
        $browser->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', ...self::headers()], content: json_encode($payload, JSON_THROW_ON_ERROR));

        return self::json($browser);
    }

    /**
     * @return array<mixed>
     */
    private static function json(KernelBrowser $browser): array
    {
        $decoded = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ];
    }

    private function browser(string $player): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }
}
