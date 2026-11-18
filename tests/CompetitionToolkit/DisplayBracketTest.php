<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetCompetitionAdvancementSteps;
use SpeedPuzzling\Web\Query\OfficialEntrantsView;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayBracket;
use SpeedPuzzling\Web\Services\Standings\PublicStandingSnapshots;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DisplayKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * The bracket element of the displays (competition toolkit v2 S6, displays-standings.md §3.3): the bracket of one
 * category as a guest sees it in the display's state - stages, boxes, lines, the qualified of published rounds only
 * (names cut per element), each box's status computed now, nothing for an event without steps, a design without the
 * element unchanged, a fixed statement budget, and a failure that empties the element but never the timer.
 *
 * The Results Cup: Group A (published: Anna 1st, Ben + Cara 2nd, Anna and Ben qualified), Group B (not published: Gina,
 * Hugo qualified) → Final; Pairs (not published) → Pairs Final.
 */
final class DisplayBracketTest extends WebTestCase
{
    use CreatesCompetitionDisplays;
    use QueryCountAssertions;

    private const string CUP = OfficialResultsFixture::COMPETITION_RESULTS_CUP;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testStagesBoxesLinesAndTheQualifiedOfPublishedRoundsOnly(): void
    {
        $this->declareSteps();
        $token = self::createDisplay(self::CUP, [
            ['type' => 'bracket'],
            ['type' => 'bracket', 'props' => ['category' => 'duo', 'names' => 1]],
            ['type' => 'bracket', 'props' => ['category' => 'team']],
        ], OfficialResultsFixture::ROUND_GROUP_A, kind: DisplayKind::Overlay);
        [$raw, $state] = self::displayState($this->browser, $token);

        // auto = the shown round's category (solo); stages as columns, boxes in schedule order
        $solo = self::at($state, 'bracket.el000000');
        self::assertIsArray($solo);
        self::assertSame('solo', $solo['category']);
        self::assertSame([['Group A', 'Group B'], ['Final']], self::boxNames($solo));
        self::assertSame([[['fromY' => 250, 'toY' => 500], ['fromY' => 750, 'toY' => 500]]], $solo['gaps']);

        $groupA = self::at($solo, 'stages.0.rounds.0');
        self::assertIsArray($groupA);
        self::assertSame(['name', 'status', 'current', 'showsTop', 'entrants', 'more'], array_keys($groupA));
        self::assertSame('published', $groupA['status']);
        self::assertTrue($groupA['current']);
        self::assertFalse($groupA['showsTop']);
        self::assertSame([['rank' => 1, 'name' => 'Anna Fast', 'qualified' => true], ['rank' => 2, 'name' => 'Ben Steady', 'qualified' => true]], $groupA['entrants']);
        self::assertSame(0, $groupA['more']);

        // Group B is not published: no name of it anywhere
        self::assertSame(['finished', false, [], 0], [self::at($solo, 'stages.0.rounds.1.status'), self::at($solo, 'stages.0.rounds.1.current'), self::at($solo, 'stages.0.rounds.1.entrants'), self::at($solo, 'stages.0.rounds.1.more')]);
        foreach (['Gina Quick', 'Hugo Slow', 'Ivan Last', 'Puzzle Sharks', 'Edge Hunters'] as $unpublished) {
            self::assertStringNotContainsString($unpublished, $raw, 'An unpublished round names ' . $unpublished);
        }

        // A category: the pairs bracket; a category without a bracket: nothing
        self::assertSame([['Pairs'], ['Pairs Final']], self::boxNames(self::at($state, 'bracket.el000001')));
        self::assertNull(self::at($state, 'bracket.el000002'));

        // No ids of rounds in the boxes, of participants or players anywhere, no puzzle data
        self::assertStringNotContainsString(strtolower(OfficialResultsFixture::ROUND_FINAL), $raw);
        self::assertDoesNotMatchRegularExpression('/"[plt]:/', $raw);
        self::assertStringNotContainsString('playerCode', $raw);
        self::assertStringNotContainsString('puzzle"', (string) json_encode($state['bracket']));

        // The public bracket page's texts in the display's language
        self::assertSame(['upcoming' => 'Upcoming', 'live' => 'Live now', 'finished' => 'Results coming', 'published' => 'Results'], self::at($state, 'texts.bracketStatus'));
        self::assertSame('Top places', self::at($state, 'texts.bracketTop'));
        self::assertSame('+ %count% more', self::at($state, 'texts.bracketMore'));
    }

    public function testNamesAreCutPerElementAndATopThreeBoxSaysSo(): void
    {
        $this->declareSteps();
        // Group A qualified nobody: its top 3 (Ben and Cara share second)
        $this->database->executeStatement('UPDATE competition_participant_round SET qualified_at = NULL WHERE round_id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_A]);

        $token = self::createDisplay(self::CUP, [
            ['type' => 'bracket', 'props' => ['names' => 1]],
            ['type' => 'bracket', 'props' => ['names' => 0]],
        ], OfficialResultsFixture::ROUND_FINAL);
        [, $state] = self::displayState($this->browser, $token);

        self::assertSame([['rank' => 1, 'name' => 'Anna Fast', 'qualified' => false]], self::at($state, 'bracket.el000000.stages.0.rounds.0.entrants'));
        self::assertSame(2, self::at($state, 'bracket.el000000.stages.0.rounds.0.more'));
        self::assertTrue(self::at($state, 'bracket.el000000.stages.0.rounds.0.showsTop'));
        self::assertTrue(self::at($state, 'bracket.el000000.stages.1.rounds.0.current'));
        self::assertSame([], self::at($state, 'bracket.el000001.stages.0.rounds.0.entrants'));
        self::assertSame(3, self::at($state, 'bracket.el000001.stages.0.rounds.0.more'));
    }

    /**
     * Publishing a round's results shows its qualified on the next state at once - the rounds are part of the snapshot's
     * key, even when no published standing bumps the generation.
     */
    public function testAPublishedRoundShowsItsQualifiedOnTheNextState(): void
    {
        $this->declareSteps();
        $token = self::createDisplay(self::CUP, [['type' => 'bracket']], OfficialResultsFixture::ROUND_GROUP_B);
        $this->browser->disableReboot();
        self::getContainer()->set('competition_standings_cache', new FilesystemAdapter('s6_' . bin2hex(random_bytes(6)), 0, sys_get_temp_dir() . '/display-bracket-test'));

        [$raw] = self::displayState($this->browser, $token);
        self::assertStringNotContainsString('Gina Quick', $raw);

        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_B]);
        [, $state] = self::displayState($this->browser, $token);
        self::assertSame('published', self::at($state, 'bracket.el000000.stages.0.rounds.1.status'));
        self::assertSame(['Gina Quick', 'Hugo Slow'], array_column((array) self::at($state, 'bracket.el000000.stages.0.rounds.1.entrants'), 'name'));
    }

    /**
     * The guest view: a signed-in player's blocklist hides nobody on a display (the displays' documented exemption).
     */
    public function testASignedInBlockerStillSeesEveryEntrant(): void
    {
        $this->declareSteps();
        $token = self::createDisplay(self::CUP, [['type' => 'bracket']], OfficialResultsFixture::ROUND_GROUP_A);
        // Anna Fast is linked to PLAYER_ADMIN
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_REGULAR, 'blocked' => PlayerFixture::PLAYER_ADMIN],
        );

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        self::displayState($this->browser, $token);
        [, $state] = self::displayState($this->browser, $token);
        self::assertSame(['Anna Fast', 'Ben Steady'], array_column((array) self::at($state, 'bracket.el000000.stages.0.rounds.0.entrants'), 'name'));
    }

    public function testNothingWithoutStepsAndADesignWithoutTheElementKeepsItsAnswer(): void
    {
        [, $state] = self::displayState($this->browser, self::createDisplay(self::CUP, [['type' => 'bracket'], ['type' => 'timer']], OfficialResultsFixture::ROUND_GROUP_A));
        self::assertSame(['el000000' => null], $state['bracket']);
        self::assertNotNull($state['stopwatch']);

        $this->declareSteps();
        [, $state] = self::displayState($this->browser, self::createDisplay(self::CUP, [['type' => 'timer'], ['type' => 'leaderboard']], OfficialResultsFixture::ROUND_GROUP_A));
        self::assertArrayNotHasKey('bracket', $state);
        self::assertIsArray($state['texts']);
        self::assertSame(['startsIn', 'timesUp', 'stopped', 'notStarted', 'scheduled', 'finished', 'piecesPlaced', 'category', 'table', 'pieces', 'invalid'], array_keys($state['texts']));

        // A hidden bracket element asks for nothing either
        [, $state] = self::displayState($this->browser, self::createDisplay(self::CUP, [['type' => 'bracket', 'hidden' => true]], OfficialResultsFixture::ROUND_GROUP_A));
        self::assertArrayNotHasKey('bracket', $state);
    }

    /**
     * Statements (§3.3): the display's own 3 stay; the steps and the entrants are read once per generation and rounds
     * (warm +0; cold: steps + people + teams).
     */
    public function testTheStatementBudget(): void
    {
        $this->declareSteps();
        $token = self::createDisplay(self::CUP, [['type' => 'bracket'], ['type' => 'timer']], OfficialResultsFixture::ROUND_GROUP_A);
        $this->browser->disableReboot();
        self::getContainer()->set('competition_standings_cache', new FilesystemAdapter('s6_' . bin2hex(random_bytes(6)), 0, sys_get_temp_dir() . '/display-bracket-test'));

        $this->startCountingQueries($this->browser);
        self::displayState($this->browser, $token);
        $this->assertQueryCountAtMost($this->browser, 3 + 3, 'cold');

        $this->startCountingQueries($this->browser);
        self::displayState($this->browser, $token);
        self::assertSame(3, $this->queryCount($this->browser), 'warm');

        // An advancement step written (its controller's after-commit hook) = a new generation: read again
        self::getContainer()->get(CompetitionDisplayLiveUpdates::class)->standingsChanged(self::CUP);
        $this->startCountingQueries($this->browser);
        self::displayState($this->browser, $token);
        self::assertGreaterThan(3, $this->queryCount($this->browser), 'after a new generation');
    }

    /**
     * A bracket failure never blanks the timer: the element is empty (null) and a warning says why.
     */
    public function testAFailureEmptiesTheElementWithAWarning(): void
    {
        $broken = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 1, 'dbname' => 'none', 'user' => 'none']);
        $container = self::getContainer();
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        $bracket = new DisplayBracket(
            new GetCompetitionAdvancementSteps($broken),
            $container->get(OfficialEntrantsView::class),
            $container->get(PublicStandingSnapshots::class),
            new ArrayAdapter(),
            $logger,
        );

        $design = ['elements' => [
            ['id' => 'brack001', 'type' => 'bracket', 'hidden' => false, 'when' => ['phases' => ['running']], 'props' => ['category' => 'auto', 'names' => 3]],
            ['id' => 'brack002', 'type' => 'bracket', 'hidden' => false, 'when' => ['phases' => ['running']], 'props' => ['category' => 'duo', 'names' => 3]],
        ]];
        $rounds = [[
            'id' => OfficialResultsFixture::ROUND_GROUP_A,
            'name' => 'Group A',
            'slug' => 'group-a',
            'category' => 'solo',
            'starts_at' => new DateTimeImmutable(TestClock::FROZEN_NOW),
            'minutes_limit' => 60,
            'timezone' => null,
            'badge_bg' => '#fe696a',
            'stopwatch_status' => null,
            'stopwatch_started_at' => null,
            'stopwatch_stopped_at' => null,
            'results_published_at' => null,
            'spotlight_ref' => null,
        ]];
        $now = new DateTimeImmutable(TestClock::FROZEN_NOW);

        self::assertSame(['brack001' => null, 'brack002' => null], $bracket->forDisplay($design, self::CUP, $rounds, ['id' => OfficialResultsFixture::ROUND_GROUP_A, 'category' => 'solo'], $now));
        self::assertContains('Could not read the bracket of a display - shown empty', $logger->warnings);
        self::assertNull($bracket->forDisplay(['elements' => []], self::CUP, $rounds, null, $now));
    }

    private function declareSteps(): void
    {
        DeclaredAdvancementStep::insert($this->database, self::CUP, [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B], [OfficialResultsFixture::ROUND_FINAL], qualifyCount: 2);
        DeclaredAdvancementStep::insert($this->database, self::CUP, [OfficialResultsFixture::ROUND_PAIRS], [OfficialResultsFixture::ROUND_PAIRS_FINAL], position: 1);
    }

    /**
     * @return list<list<string>>
     */
    private static function boxNames(mixed $bracket): array
    {
        self::assertIsArray($bracket);
        self::assertIsArray($bracket['stages']);

        return array_values(array_map(
            static fn (mixed $stage): array => is_array($stage) && is_array($stage['rounds'])
                ? array_values(array_map(static fn (mixed $round): string => is_array($round) && is_string($round['name']) ? $round['name'] : '', $stage['rounds']))
                : [],
            $bracket['stages'],
        ));
    }
}
