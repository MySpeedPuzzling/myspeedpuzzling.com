<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Tests\AnalyticsStudioTesting;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\CreatesCompetitionDisplays;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\TableLabels;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Table labels on the displays, their live control and the analytics studio (table-labels-and-plates.md, A4): an entry
 * of a labelled round carries `tableLabel` next to its number - only when the round's list names that number; an
 * unlabelled round's answers have no such key at all (byte-identical shapes).
 *
 * OfficialResultsFixture's Results Cup: Group A published (Anna table 1, Ben 2, Cara 3, Dan 4), Group B not published
 * (Gina 1, Hugo 2, Ivan 3).
 */
final class DisplayTableLabelsTest extends WebTestCase
{
    use AnalyticsStudioTesting;
    use CreatesCompetitionDisplays;

    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const array LEADERBOARD = ['type' => 'leaderboard', 'props' => ['rows' => 10, 'columns' => ['rank', 'name', 'table']]];
    private const array TICKER = ['type' => 'ticker', 'props' => ['rows' => 4]];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testDisplayRowsCarryTheLabelOnlyOnALabelledRound(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(self::EVENT, [self::LEADERBOARD, self::TICKER], OfficialResultsFixture::ROUND_GROUP_A);

        // Plain numbers: no `tableLabel` anywhere
        [$raw, $state] = self::displayState($browser, $token);
        self::assertStringNotContainsString('tableLabel', $raw);
        self::assertSame(1, self::at($state, 'results.top.0.tableNumber'));

        // Three labels for four seated entries: Dan's table 4 is beyond the list
        self::labelRound(OfficialResultsFixture::ROUND_GROUP_A, ['A1-1', 'A1-2', 'A2-1']);
        [, $state] = self::displayState($browser, $token);
        $top = self::at($state, 'results.top');
        self::assertIsArray($top);
        $labels = [];

        foreach ($top as $row) {
            self::assertIsArray($row);
            $name = $row['name'] ?? null;
            self::assertIsString($name);
            $labels[$name] = [$row['tableNumber'] ?? null, $row['tableLabel'] ?? null];
        }

        self::assertSame([
            'Anna Fast' => [1, 'A1-1'],
            'Ben Steady' => [2, 'A1-2'],
            'Cara Tied' => [3, 'A2-1'],
            'Dan Unfinished' => [4, null],
        ], $labels);
        self::assertArrayNotHasKey('tableLabel', (array) self::at($state, 'results.top.3'), 'no key for a number the list does not name');

        // Another round of the event keeps plain numbers
        [$raw] = self::displayState($browser, self::createDisplay(self::EVENT, [['type' => 'leaderboard', 'props' => ['source' => 'live']]], OfficialResultsFixture::ROUND_GROUP_B));
        self::assertStringNotContainsString('tableLabel', $raw);
    }

    public function testTheLiveControlAndTheSpotlightNameTheTableByItsLabel(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ORGANISER);
        $display = self::createDisplay(self::EVENT, [['type' => 'spotlight', 'props' => ['pick' => 'chosen', 'show' => ['table', 'name']]]], OfficialResultsFixture::ROUND_GROUP_B);
        $state = '/en/display-control/' . self::EVENT . '/state?round=' . OfficialResultsFixture::ROUND_GROUP_B;
        $spotlightUrl = '/en/display-control/round/' . OfficialResultsFixture::ROUND_GROUP_B . '/spotlight';
        $gina = 'participant_round:' . OfficialResultsFixture::ENTRY_B_GINA;

        // Plain numbers: the picker's chips and the spotlight as before
        $browser->request('GET', $state);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('tableLabel', (string) $browser->getResponse()->getContent());
        self::assertSame(['spotlight' => ['entryRef' => $gina, 'name' => 'Gina Quick', 'table' => 1]], $this->post($browser, $spotlightUrl, ['entryRef' => $gina]));

        // Two labels: Gina's table 1 is "B1", Ivan's table 3 has none
        self::labelRound(OfficialResultsFixture::ROUND_GROUP_B, ['B1', 'B2']);
        $browser->request('GET', $state);
        $control = self::json($browser);
        self::assertIsArray($control['entries']);
        $chips = [];

        foreach ($control['entries'] as $chip) {
            self::assertIsArray($chip);
            $name = $chip['name'] ?? null;
            self::assertIsString($name);
            $chips[$name] = [$chip['table'] ?? null, $chip['tableLabel'] ?? null];
        }

        self::assertSame(['Gina Quick' => [1, 'B1'], 'Hugo Slow' => [2, 'B2'], 'Ivan Last' => [3, null]], array_intersect_key($chips, ['Gina Quick' => 1, 'Hugo Slow' => 1, 'Ivan Last' => 1]));
        self::assertSame(['entryRef' => $gina, 'name' => 'Gina Quick', 'table' => 1, 'tableLabel' => 'B1'], $control['spotlight']);

        self::assertSame(['spotlight' => ['entryRef' => $gina, 'name' => 'Gina Quick', 'table' => 1, 'tableLabel' => 'B1']], $this->post($browser, $spotlightUrl, ['entryRef' => $gina]));
        [, $shown] = self::displayState($browser, $display);
        self::assertSame(1, self::at($shown, 'control.spotlight.tableNumber'));
        self::assertSame('B1', self::at($shown, 'control.spotlight.tableLabel'));

        $ivan = 'participant_round:' . OfficialResultsFixture::ENTRY_B_IVAN;
        self::assertSame(['spotlight' => ['entryRef' => $ivan, 'name' => 'Ivan Last', 'table' => 3]], $this->post($browser, $spotlightUrl, ['entryRef' => $ivan]));
    }

    public function testStudioEntrantsCarryTheLabelOnlyOnALabelledRound(): void
    {
        $browser = self::createClient();
        $url = '/analytics-studio/' . OfficialResultsFixture::ROUND_GROUP_A . '/state';
        $this->clearAnalyticsStudioCache();

        $state = $this->studioJson($browser, $url);
        self::assertStringNotContainsString('tableLabel', json_encode($state, JSON_THROW_ON_ERROR));
        $plainAnna = self::digArray($state, 'entrants', 0);
        self::assertSame(['key', 'isTeam', 'teamName', 'table', 'members'], array_keys($plainAnna));

        // No cache clear: a changed list is another cache entry at once
        self::labelRound(OfficialResultsFixture::ROUND_GROUP_A, ['A1-1', 'A1-2', 'A2-1']);
        $state = $this->studioJson($browser, $url);
        $tables = [];

        foreach (self::digArray($state, 'entrants') as $entrant) {
            self::assertIsArray($entrant);
            $name = self::dig($entrant, 'members', 0, 'name');
            self::assertIsString($name);
            $tables[$name] = [$entrant['table'] ?? null, $entrant['tableLabel'] ?? null];
        }

        self::assertSame([1, 'A1-1'], $tables['Anna Fast']);
        self::assertSame([3, 'A2-1'], $tables['Cara Tied']);
        self::assertSame([4, null], $tables['Dan Unfinished'], 'beyond the list: the number only');
        self::assertSame(['key', 'isTeam', 'teamName', 'table', 'tableLabel', 'members'], array_keys(self::digArray($state, 'entrants', 0)));
    }

    /**
     * @param list<string> $labels
     */
    private static function labelRound(string $roundId, array $labels): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $round = $manager->find(CompetitionRound::class, $roundId);
        self::assertInstanceOf(CompetitionRound::class, $round);
        $round->changeTableLabels(TableLabels::fromList($labels));
        $manager->flush();
        $manager->clear();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<mixed>
     */
    private function post(KernelBrowser $browser, string $url, array $payload): array
    {
        $browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

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
}
