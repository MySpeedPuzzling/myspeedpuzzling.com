<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\EventWizard;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetCompetitionAdvancementSteps;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * The event creation wizard's several-rounds path (W5 + W7, docs/features/competitions-management/toolkit/
 * W-creation-wizard-research.md §3.1, §3.5, §3.6, §3.9): the entry's follow-up question, Basics without a round,
 * Structure (templates, schedule table, read-only once entries exist, the toolkit's advancement steps and standings),
 * the Puzzles grid (secret switch, the one-puzzle-per-category invariant, pick K) and Review.
 */
final class EventWizardSeveralRoundsTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'EVENT_CREATION_WIZARD';
    private const string TOOLKIT_FLAG = 'COMPETITION_TOOLKIT_FOR_ORGANISERS';
    private const string ORGANISER = PlayerFixture::PLAYER_REGULAR;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $this->overrideFeatureFlagEnv(self::TOOLKIT_FLAG, false);
        $this->browser = self::createClient();
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testTheEntrysFollowUpAnswerLeadsToBasicsOfTheSeveralRoundsPath(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $crawler = $this->browser->request('GET', '/en/new-event');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('[data-event-wizard-structure-question] input[name="structure"]'));

        $this->browser->request('GET', '/en/new-event?kind=several_rounds&structure=every_round_counts');
        self::assertResponseRedirects('/en/new-event/basics?path=several_rounds&structure=every_round_counts');

        // No answer given: the qualification template
        $this->browser->request('GET', '/en/new-event?kind=several_rounds');
        self::assertResponseRedirects('/en/new-event/basics?path=several_rounds&structure=qualification');

        $crawler = $this->browser->request('GET', '/en/new-event/basics?path=several_rounds&structure=qualification');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-event-wizard-rail]');
        self::assertStringContainsString('Structure', $crawler->filter('[data-event-wizard-rail]')->text());
    }

    public function testBasicsCreatesTheDraftWithoutARoundAndTheDraftResumesAtStructure(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $competitionId = $this->startDraft('every_round_counts', '09:30');
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/structure?structure=every_round_counts&start=09:30', 303);
        self::assertSame(0, $this->roundsCount($competitionId));
        self::assertTrue($this->database()->fetchOne('SELECT is_draft FROM competition WHERE id = :id', ['id' => $competitionId]));

        // Resumed without the carried answer: still the several-rounds path, at its Structure step
        $this->browser->request('GET', '/en/new-event/' . $competitionId);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/structure');

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure?structure=every_round_counts&start=09:30');
        self::assertResponseIsSuccessful();
        self::assertSame('09:30', $crawler->filter('input[name="event_wizard_structure[startTime]"]')->attr('value'));
        self::assertSame('every_round_counts', $crawler->filter('input[name="event_wizard_structure[template]"][checked]')->attr('value'));
    }

    public function testQualificationCreatesTheStagesRoundsAndForTheToolkitTheirAdvancementSteps(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $competitionId = $this->startDraft('qualification');

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure?structure=qualification&start=10:00');
        self::assertSelectorExists('input[name="event_wizard_structure[stages][0][advance]"]');
        $this->buildTemplate($crawler, []);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/structure#schedule', 303);

        $rounds = $this->rounds($competitionId);
        self::assertSame(['Group A', 'Group B', 'Group C', 'Group D', 'Semifinal 1', 'Semifinal 2', 'Final'], array_column($rounds, 'name'));
        self::assertSame(array_fill(0, 7, 'solo'), array_column($rounds, 'category'));
        // From 10:00 in Prague (08:00 UTC in June), every 2 hours
        self::assertStringStartsWith('2027-06-15 08:00', $rounds[0]['starts_at']);
        self::assertStringStartsWith('2027-06-15 10:00', $rounds[1]['starts_at']);
        self::assertSame(120, $rounds[6]['minutes_limit']);
        self::assertSame('Europe/Prague', $rounds[0]['timezone']);

        $steps = self::getContainer()->get(GetCompetitionAdvancementSteps::class)->ofCompetition($competitionId);
        self::assertCount(2, $steps);
        $ids = $this->roundIds($competitionId);
        self::assertSame(array_slice($ids, 0, 4), $steps[0]->settings->sourceRoundIds);
        self::assertSame(array_slice($ids, 4, 2), $steps[0]->settings->targetRoundIds);
        self::assertSame(AdvanceDistribution::Balanced, $steps[0]->settings->distribution);
        self::assertSame(10, $steps[0]->settings->qualifyCount);
        self::assertSame([$ids[6]], $steps[1]->settings->targetRoundIds);
        self::assertSame(AdvanceDistribution::Single, $steps[1]->settings->distribution);
        self::assertSame(15, $steps[1]->settings->qualifyCount);

        // The schedule table is next, the template behind "Start again"
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure');
        self::assertCount(7, $crawler->filter('[data-event-wizard-schedule-row]:not([data-event-wizard-schedule-row="new"])'));
        self::assertSelectorExists('[data-event-wizard-template-again]');
        self::assertStringContainsString('the best 10 of each round go on', $crawler->filter('[data-event-wizard-toolkit-summary]')->text());
    }

    public function testWithoutTheToolkitTheStagesAreJustRounds(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft('qualification');

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure');
        self::assertSelectorNotExists('input[name="event_wizard_structure[stages][0][advance]"]');
        self::assertSelectorNotExists('input[name="event_wizard_structure[sumPerCategory]"]');

        $this->buildTemplate($crawler, ['template' => 'every_round_counts', 'pairsRounds' => '2', 'teamsRounds' => '0', 'soloRounds' => '2']);
        self::assertResponseRedirects();
        self::assertSame(4, $this->roundsCount($competitionId));
        self::assertSame(0, $this->rowsOf('competition_standing', $competitionId));
        self::assertSame(0, $this->rowsOf('competition_advancement_step', $competitionId));
    }

    public function testEveryRoundCountsAddsUpTheTimesPerCategoryForTheToolkit(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $competitionId = $this->startDraft('every_round_counts');

        // 18 rounds every typed 2 hours do not fit into the one day: refused, nothing created
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure?structure=every_round_counts');
        $crawler = $this->buildTemplate($crawler, ['template' => 'every_round_counts', 'everyMinutes' => '120']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString("The rounds don't fit into the event's day", $crawler->filter('[data-event-wizard-template]')->text());
        self::assertSame(0, $this->roundsCount($competitionId));

        // The gap left automatic: closer, so they fit - from 10:00 every 45 minutes
        $this->buildTemplate($crawler, ['template' => 'every_round_counts', 'everyMinutes' => '']);
        self::assertResponseRedirects();

        $rounds = $this->rounds($competitionId);
        self::assertCount(18, $rounds);
        // 10:00 Prague, then every 45 minutes
        self::assertStringStartsWith('2027-06-15 08:45', $rounds[1]['starts_at']);
        self::assertSame(['Solo 1', 'Pairs 1', 'Teams 1', 'Solo 2'], array_slice(array_column($rounds, 'name'), 0, 4));
        self::assertSame(4, $rounds[2]['team_size']);

        /** @var list<array{name: string, category: string, published_at: null|string, round_ids: string}> $standings */
        $standings = $this->database()->fetchAllAssociative('SELECT name, category, published_at, round_ids FROM competition_standing WHERE competition_id = :id ORDER BY name', ['id' => $competitionId]);
        self::assertCount(3, $standings);
        self::assertSame(['duo', 'solo', 'team'], array_column($standings, 'category'));
        // Never published by the wizard
        self::assertSame([null, null, null], array_column($standings, 'published_at'));
        $soloIds = array_column(array_filter($rounds, static fn (array $round): bool => $round['category'] === 'solo'), 'id');
        self::assertSame($soloIds, json_decode($standings[1]['round_ids'], true));
    }

    public function testExactlyOneRoundIsRefusedWith422(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft('qualification');

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure');
        $this->buildTemplate($crawler, ['template' => 'every_round_counts', 'soloRounds' => '1', 'pairsRounds' => '0', 'teamsRounds' => '0']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->roundsCount($competitionId));
    }

    public function testTheScheduleTableRenamesMovesRemovesAndAddsRounds(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraftWithRounds(['template' => 'every_round_counts', 'soloRounds' => '3', 'pairsRounds' => '0', 'teamsRounds' => '0']);
        $ids = $this->roundIds($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure');
        $form = $crawler->filter('form[name="event_wizard_schedule"]')->form();
        $form['event_wizard_schedule[rows][' . $ids[0] . '][name]'] = 'Opening round';
        $form['event_wizard_schedule[rows][' . $ids[1] . '][start]'] = '2027-06-15T18:15';
        self::tick($form, 'event_wizard_schedule[rows][' . $ids[2] . '][remove]');
        $form['event_wizard_schedule[rows][new0][name]'] = 'Pairs bonus';
        $form['event_wizard_schedule[rows][new0][start]'] = '2027-06-15T20:00';
        $form['event_wizard_schedule[rows][new0][category]'] = 'duo';
        $this->browser->submit($form);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/puzzles', 303);

        $rounds = $this->rounds($competitionId);
        self::assertSame(['Opening round', 'Solo 2', 'Pairs bonus'], array_column($rounds, 'name'));
        self::assertStringStartsWith('2027-06-15 16:15', $rounds[1]['starts_at']);
        self::assertSame('duo', $rounds[2]['category']);
        self::assertSame(90, $rounds[2]['minutes_limit']);

        // A new round outside the event's day: refused on its row
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure');
        $form = $crawler->filter('form[name="event_wizard_schedule"]')->form();
        $form['event_wizard_schedule[rows][new0][name]'] = 'Late round';
        $form['event_wizard_schedule[rows][new0][start]'] = '2027-06-16T10:00';
        $crawler = $this->browser->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The event takes place from 2027-06-15 to 2027-06-15', $crawler->filter('[data-event-wizard-schedule-row="new"]')->first()->text());
        self::assertSame(3, $this->roundsCount($competitionId));

        // Down to one round: refused, nothing changed
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure');
        $form = $crawler->filter('form[name="event_wizard_schedule"]')->form();
        self::tick($form, 'event_wizard_schedule[rows][' . $rounds[1]['id'] . '][remove]');
        self::tick($form, 'event_wizard_schedule[rows][' . $rounds[2]['id'] . '][remove]');
        $this->browser->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(3, $this->roundsCount($competitionId));
    }

    public function testStructureIsReadOnlyOnceARoundHasBegun(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraftWithRounds([]);
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure');
        $form = $crawler->filter('form[name="event_wizard_schedule"]')->form();

        $this->database()->executeStatement(
            "UPDATE competition_round SET stopwatch_status = 'stopped' WHERE id = (SELECT id FROM competition_round WHERE competition_id = :id ORDER BY starts_at LIMIT 1)",
            ['id' => $competitionId],
        );

        $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-event-wizard-structure-locked]');
        self::assertSelectorNotExists('form[name="event_wizard_schedule"]');

        // A page loaded before: refused, nothing removed
        $first = $this->roundIds($competitionId)[1];
        self::tick($form, 'event_wizard_schedule[rows][' . $first . '][remove]');
        $this->browser->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(7, $this->roundsCount($competitionId));
    }

    public function testThePuzzlesGridAddsSecretPuzzlesRevealedAtTheStopwatchStartAndRowsForLater(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $competitionId = $this->startDraftWithRounds([]);
        $ids = $this->roundIds($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzles');
        self::assertResponseIsSuccessful();
        self::assertCount(7, $crawler->filter('[data-event-wizard-puzzles-row]'));
        self::assertSelectorExists('input[name="event_wizard_puzzles[revealMode]"][value="stopwatch"]');

        $this->submitGrid($crawler, [
            'secret' => '1',
            'revealMode' => 'stopwatch',
        ], [
            $ids[0] => [[ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PuzzleFixture::PUZZLE_500_01]],
            $ids[6] => [[ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PuzzleFixture::PUZZLE_500_02]],
        ], overrides: [$ids[6] => 'public']);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);

        $puzzles = $this->database()->fetchAllAssociative(
            'SELECT crp.round_id, crp.puzzle_id, crp.hide_until_round_starts, crp.reveal_mode, crp.reveal_at FROM competition_round_puzzle crp JOIN competition_round cr ON cr.id = crp.round_id WHERE cr.competition_id = :id ORDER BY cr.starts_at',
            ['id' => $competitionId],
        );
        self::assertCount(2, $puzzles);
        self::assertSame($ids[0], $puzzles[0]['round_id']);
        self::assertTrue($puzzles[0]['hide_until_round_starts']);
        self::assertSame('stopwatch', $puzzles[0]['reveal_mode']);
        self::assertNull($puzzles[0]['reveal_at']);
        // The row's override: not secret
        self::assertFalse($puzzles[1]['hide_until_round_starts']);

        // Five rounds left for later: resuming comes back to the grid
        $this->browser->request('GET', '/en/new-event/' . $competitionId);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/puzzles');

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        self::assertResponseIsSuccessful();
        self::assertCount(7, $crawler->filter('[data-event-wizard-review-round]'));
        self::assertStringContainsString('revealed when you start the stopwatch', $crawler->filter('[data-event-wizard-review-rounds]')->text());
        self::assertCount(5, $crawler->filter('[data-event-wizard-review-puzzle-later]'));
        self::assertCount(2, $crawler->filter('[data-event-wizard-review-toolkit] .ci-arrow-right'));

        $this->browser->submit($crawler->filter('[data-event-wizard-publish]')->form(), ['action' => 'publish']);
        self::assertResponseRedirects('/en/edit-event/' . $competitionId, 303);
        self::assertFalse($this->database()->fetchOne('SELECT is_draft FROM competition WHERE id = :id', ['id' => $competitionId]));
    }

    public function testTheSamePuzzleInTwoSoloRoundsIsARowErrorAndNothingIsWritten(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraftWithRounds([]);
        $ids = $this->roundIds($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzles');
        // The stopwatch reveal is a toolkit option
        self::assertSelectorNotExists('input[name="event_wizard_puzzles[revealMode]"][value="stopwatch"]');

        $crawler = $this->submitGrid($crawler, [], [
            $ids[0] => [[ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PuzzleFixture::PUZZLE_500_01]],
            $ids[1] => [[ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PuzzleFixture::PUZZLE_500_02]],
            $ids[2] => [[ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PuzzleFixture::PUZZLE_500_01]],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This puzzle is already in Group A', $crawler->filter('#round-' . $ids[2])->text());
        self::assertSame(0, $this->puzzlesCount($competitionId));

        // Already in a round of the event: refused the same way
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzles');
        $this->submitGrid($crawler, [], [$ids[0] => [[ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PuzzleFixture::PUZZLE_500_01]]]);
        self::assertResponseRedirects();
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzles');
        $this->submitGrid($crawler, [], [$ids[3] => [[ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PuzzleFixture::PUZZLE_500_01]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->puzzlesCount($competitionId));
    }

    public function testARowPicksTwoOfThreePuzzlesForTheToolkit(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $competitionId = $this->startDraftWithRounds([]);
        $ids = $this->roundIds($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzles');
        $three = [
            [ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PuzzleFixture::PUZZLE_500_01],
            [ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PuzzleFixture::PUZZLE_500_02],
            [ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PuzzleFixture::PUZZLE_500_03],
        ];

        // K above the puzzles the round will have: refused up front, nothing written
        $this->submitGrid($crawler, [], [$ids[0] => $three], toSolve: [$ids[0] => '4']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->puzzlesCount($competitionId));

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzles');
        $this->submitGrid($crawler, [], [$ids[0] => $three], toSolve: [$ids[0] => '2']);
        self::assertResponseRedirects();
        self::assertSame(3, $this->puzzlesCount($competitionId));

        $round = $this->database()->fetchAssociative('SELECT puzzle_format, puzzles_to_solve FROM competition_round WHERE id = :id', ['id' => $ids[0]]);
        self::assertIsArray($round);
        self::assertSame('pick', $round['puzzle_format']);
        self::assertSame(2, $round['puzzles_to_solve']);
    }

    public function testAnotherPlayerCannotOpenTheStepsAndWithoutTheFlagTheyAre404(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft('qualification');

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_PRIVATE);
        foreach (['structure', 'puzzles'] as $step) {
            $this->browser->request('GET', '/en/new-event/' . $competitionId . '/' . $step);
            self::assertResponseStatusCodeSame(403, $step);
        }

        $this->overrideFeatureFlagEnv(self::FLAG, false);
        self::ensureKernelShutdown();
        $this->browser = self::createClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        foreach (['structure', 'puzzles'] as $step) {
            $this->browser->request('GET', '/en/new-event/' . $competitionId . '/' . $step);
            self::assertResponseStatusCodeSame(404, $step);
        }
    }

    /**
     * Basics of a new several-rounds event, submitted - the new event's id.
     */
    private function startDraft(string $structure, string $start = '10:00'): string
    {
        $before = $this->competitionIds();
        $crawler = $this->browser->request('GET', '/en/new-event/basics?path=several_rounds&structure=' . $structure);
        self::assertResponseIsSuccessful();

        $this->browser->submit($crawler->filter('form[name="event_wizard_basics_form"]')->form(), [
            'event_wizard_basics_form[name]' => 'Wizard Championship',
            'event_wizard_basics_form[isOnline]' => '0',
            'event_wizard_basics_form[location]' => 'Hall, Brno',
            'event_wizard_basics_form[dateFrom]' => '15.06.2027',
            'event_wizard_basics_form[startTime]' => $start,
        ]);

        $created = array_values(array_diff($this->competitionIds(), $before));
        self::assertCount(1, $created);

        return $created[0];
    }

    /**
     * A draft with the template's rounds (the qualification template's 7 rounds by default)
     *
     * @param array<string, string> $template
     */
    private function startDraftWithRounds(array $template): string
    {
        $competitionId = $this->startDraft('qualification');
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure');
        $this->buildTemplate($crawler, $template);
        self::assertResponseRedirects();

        return $competitionId;
    }

    /**
     * @param array<string, string> $values field => value of the template form
     */
    private function buildTemplate(Crawler $crawler, array $values): Crawler
    {
        $form = $crawler->filter('form[name="event_wizard_structure"]')->form();
        $prefixed = [];

        foreach ($values as $field => $value) {
            $prefixed['event_wizard_structure[' . $field . ']'] = $value;
        }

        return $this->browser->submit($form, $prefixed);
    }

    /**
     * The Puzzles grid submitted with the page's own values (its CSRF token, the automatic reveals it showed) plus:
     *
     * @param array<string, string> $top secret, revealMode, hideMode
     * @param array<string, list<array{string, string}>> $rows round id => [brand, puzzle] per picker
     * @param array<string, string> $overrides round id => the row's secret override
     * @param array<string, string> $toSolve round id => K
     */
    private function submitGrid(Crawler $crawler, array $top, array $rows, array $overrides = [], array $toSolve = []): Crawler
    {
        $form = $crawler->filter('form[name="event_wizard_puzzles"]')->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['event_wizard_puzzles']);
        $grid = $values['event_wizard_puzzles'];

        foreach ($top as $field => $value) {
            $grid[$field] = $value;
        }

        if (($top['secret'] ?? null) === null) {
            unset($grid['secret']);
        }

        self::assertIsArray($grid['rows']);
        foreach ($rows as $roundId => $pickers) {
            self::assertIsArray($grid['rows'][$roundId]);
            $row = $grid['rows'][$roundId];
            $slots = is_array($row['puzzles'] ?? null) ? $row['puzzles'] : [];
            foreach ($pickers as $index => [$brand, $puzzle]) {
                $slots[$index] = ['brand' => $brand, 'puzzle' => $puzzle, 'piecesCount' => ''];
            }
            $row['puzzles'] = $slots;
            $grid['rows'][$roundId] = $row;
        }

        foreach ($overrides as $roundId => $override) {
            self::assertIsArray($grid['rows'][$roundId]);
            $grid['rows'][$roundId]['override'] = $override;
        }

        foreach ($toSolve as $roundId => $count) {
            self::assertIsArray($grid['rows'][$roundId]);
            $grid['rows'][$roundId]['puzzlesToSolve'] = $count;
        }

        $values['event_wizard_puzzles'] = $grid;

        return $this->browser->request('POST', $form->getUri(), $values);
    }

    /**
     * @return list<array{id: string, name: string, category: string, starts_at: string, minutes_limit: null|int, timezone: null|string, team_size: null|int}>
     */
    private function rounds(string $competitionId): array
    {
        /** @var list<array{id: string, name: string, category: string, starts_at: string, minutes_limit: null|int, timezone: null|string, team_size: null|int}> $rounds */
        $rounds = $this->database()->fetchAllAssociative(
            'SELECT id, name, category, starts_at, minutes_limit, timezone, team_size FROM competition_round WHERE competition_id = :id ORDER BY starts_at, id',
            ['id' => $competitionId],
        );

        return $rounds;
    }

    /**
     * @return list<string>
     */
    private function roundIds(string $competitionId): array
    {
        return array_column($this->rounds($competitionId), 'id');
    }

    private static function tick(Form $form, string $name): void
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->tick();
    }

    /**
     * @return list<string>
     */
    private function competitionIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->database()->fetchFirstColumn('SELECT id::text FROM competition');

        return $ids;
    }

    private function rowsOf(string $table, string $competitionId): int
    {
        $count = $this->database()->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE competition_id = :id', ['id' => $competitionId]);

        return is_numeric($count) ? (int) $count : 0;
    }

    private function roundsCount(string $competitionId): int
    {
        return count($this->rounds($competitionId));
    }

    private function puzzlesCount(string $competitionId): int
    {
        $count = $this->database()->fetchOne(
            'SELECT COUNT(*) FROM competition_round_puzzle crp JOIN competition_round cr ON cr.id = crp.round_id WHERE cr.competition_id = :id',
            ['id' => $competitionId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
