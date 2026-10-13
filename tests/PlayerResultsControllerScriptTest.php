<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The Results page's script (assets/controllers/player_results_controller.js, docs/features/navigation PROF-05b/05c)
 * under the races the PROF review found (delivery/reviews/PROF.md) - run for real: the controller with Turbo 8.0.23 and
 * Stimulus in jsdom, its requests answered by a stub with the delays each case sets (tests/player-results-controller-harness.mjs,
 * one node process per case).
 *
 * - F2: Show more's stream belongs to the list and the page it was asked for: a tab or a visit that lands first aborts
 *   it - and when the answer comes anyway it is dropped - so no other list gets its rows and no other page its address;
 *   the page leaving aborts it too.
 * - F3: Show all superseded by a sort leaves nothing "loading": the sorted list's Show more works.
 * - F4: a click within the search's 300 ms takes the typed text along (one load, one entry), the search's chip drops it,
 *   Show more within the typing loads the typed list; typing that ends while a click's list loads continues it.
 * - F5: a list answer without the list (a 404) is a full visit of that answer - never "Content missing", nothing thrown.
 * - Q5: "Show from the start" loads the list from its first row and replaces the entry.
 *
 * @phpstan-type PageState array{url: string, historyLength: int, owner: null|string, turboStarted: bool, rows: int, lists: list<string>, more: null|string, connected: bool, loading: null|bool, pending: null|bool, title: string, contentMissing: bool, field: null|string, focus: null|string}
 * @phpstan-type Facts array{before: PageState, searched: PageState, afterSort: PageState, after: PageState, requests: list<array{url: string, frame: null|string, aborted: bool, answered: bool}>, errors: list<string>}
 */
final class PlayerResultsControllerScriptTest extends TestCase
{
    private const string PAGE = '/en/player-results/abc';

    /** @var array<string, array<string, mixed>> */
    private static array $results = [];

    public function testShowMoreAppendsThePagesRowsAndReplacesTheEntry(): void
    {
        $facts = self::case('more appends');

        self::assertSame(self::PAGE . '?page=2', $facts['after']['url']);
        self::assertSame($facts['before']['historyLength'], $facts['after']['historyLength'], 'Replaced, not pushed');
        self::assertSame(100, $facts['after']['rows']);
        self::assertSame('result-solo-newest-51', $facts['after']['focus'], 'The first new row has the focus');
        self::assertFalse($facts['after']['loading']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTabBeforeTheStream(): iterable
    {
        yield 'the stream aborted' => ['more then tab'];
        yield 'the answer comes anyway' => ['more then tab, late answer'];
    }

    #[DataProvider('provideTabBeforeTheStream')]
    public function testATabLandingBeforeTheStreamKeepsItsListAlone(string $case): void
    {
        $facts = self::case($case);

        self::assertTrue($facts['requests'][0]['aborted'], 'The stream is aborted by the newer list');
        self::assertSame(['duo-newest'], $facts['after']['lists'], 'Only the tab\'s rows');
        self::assertSame(50, $facts['after']['rows']);
        self::assertSame(self::PAGE . '?category=duo&page=2', $facts['after']['more'], 'The tab\'s own footer');
        self::assertSame(self::PAGE . '?category=duo', $facts['after']['url'], 'The address names the list shown');
        self::assertSame($facts['before']['historyLength'] + 1, $facts['after']['historyLength'], 'The tab\'s entry, pushed once');
        self::assertFalse($facts['after']['loading']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideVisitBeforeTheStream(): iterable
    {
        yield 'the stream aborted' => ['more then visit'];
        yield 'the answer comes anyway' => ['more then visit, late answer'];
    }

    #[DataProvider('provideVisitBeforeTheStream')]
    public function testAVisitLandingBeforeTheStreamKeepsItsOwnEntry(string $case): void
    {
        $facts = self::case($case);

        self::assertTrue($facts['requests'][0]['aborted'], 'The visit aborts the stream');
        self::assertSame('Elsewhere', $facts['after']['title']);
        self::assertSame('/en/puzzle/elsewhere', $facts['after']['url'], 'The visited page\'s address - never the Results one');
        self::assertNull($facts['after']['owner'], 'Its entry is no Results entry');
        self::assertTrue($facts['after']['turboStarted'], 'Turbo\'s history runs again - nobody paused it for a page that left');
        self::assertSame(0, $facts['after']['rows']);
    }

    public function testThePageLeavingAbortsTheStream(): void
    {
        $facts = self::case('more then disconnect');

        self::assertTrue($facts['requests'][0]['aborted']);
        self::assertFalse($facts['requests'][0]['answered']);
        self::assertSame(self::PAGE, $facts['after']['url']);
        self::assertTrue($facts['after']['turboStarted']);
    }

    public function testShowAllSupersededBySortLeavesNothingLoading(): void
    {
        $facts = self::case('all then sort');

        self::assertSame(self::PAGE . '?sort=fastest', $facts['afterSort']['url']);
        self::assertFalse($facts['afterSort']['loading'], 'The sorted list is idle');
        self::assertSame(['solo-fastest'], $facts['after']['lists']);
        self::assertSame(100, $facts['after']['rows'], 'Its Show more appends');
        self::assertSame(self::PAGE . '?sort=fastest&page=2', $facts['after']['url']);
        self::assertSame(self::PAGE . '?sort=fastest&page=2&fragment=more', $facts['requests'][2]['url']);
    }

    public function testAClickWithinTheTypingTakesTheTypedTextAlong(): void
    {
        $facts = self::case('typing then tab');

        self::assertSame([self::PAGE . '?category=duo&q=night'], array_column($facts['requests'], 'url'), 'One load: the tab with the typed text');
        self::assertSame(self::PAGE . '?category=duo&q=night', $facts['after']['url']);
        self::assertSame($facts['before']['historyLength'] + 1, $facts['after']['historyLength'], 'One entry, pushed');
        self::assertSame('night', $facts['after']['field'], 'The typed text stays in the field');
        self::assertSame(['duo-newest-q-night'], $facts['after']['lists']);
    }

    public function testTheSearchChipDropsTheTypedText(): void
    {
        $facts = self::case('typing then search chip');

        self::assertSame(self::PAGE . '?q=night', $facts['searched']['url']);
        self::assertSame(self::PAGE, $facts['after']['url']);
        self::assertSame(['solo-newest'], $facts['after']['lists']);
        self::assertSame('', $facts['after']['field']);
        self::assertCount(2, $facts['requests'], 'No load for the dropped text');
    }

    public function testShowMoreWithinTheTypingLoadsTheTypedList(): void
    {
        $facts = self::case('typing then more');

        self::assertSame([[self::PAGE . '?page=2&q=night', 'player-results']], array_map(static fn (array $request): array => [$request['url'], $request['frame']], $facts['requests']), 'The frame, no stream');
        self::assertSame(self::PAGE . '?q=night&page=2', $facts['after']['url']);
        self::assertSame($facts['before']['historyLength'], $facts['after']['historyLength'], 'Paging and typing replace');
        self::assertSame(100, $facts['after']['rows']);
        self::assertSame(['solo-newest-q-night'], $facts['after']['lists']);
    }

    public function testTypingEndingWhileAClicksListLoadsContinuesThatChange(): void
    {
        $facts = self::case('tab then typing');

        self::assertSame(self::PAGE . '?category=duo&q=night', $facts['after']['url']);
        self::assertSame(['duo-newest-q-night'], $facts['after']['lists'], 'The tab stays - with the search');
        self::assertSame($facts['before']['historyLength'] + 1, $facts['after']['historyLength'], 'The click\'s entry, pushed once');
    }

    public function testAListAnswerWithoutTheListIsAFullVisit(): void
    {
        $facts = self::case('frame missing');

        self::assertSame('Not found', $facts['after']['title'], 'The answer shown as the page it is');
        self::assertFalse($facts['after']['contentMissing'], 'Never Turbo\'s "Content missing"');
        self::assertSame(self::PAGE . '?sort=fastest', $facts['after']['url']);
        self::assertSame($facts['before']['historyLength'] + 1, $facts['after']['historyLength']);
        self::assertTrue($facts['after']['turboStarted']);
        self::assertCount(1, $facts['requests'], 'The answer is visited, not asked again');
    }

    public function testShowFromTheStartLoadsTheListFromItsFirstRow(): void
    {
        $facts = self::case('start link');

        self::assertSame(self::PAGE . '?page=4', $facts['before']['url']);
        self::assertSame(self::PAGE, $facts['after']['url']);
        self::assertSame($facts['before']['historyLength'], $facts['after']['historyLength'], 'Paging: the entry replaced');
        self::assertSame(50, $facts['after']['rows']);
        self::assertSame('result-solo-newest-1', $facts['after']['focus'], 'The first row has the focus');
    }

    /**
     * The facts of one case (each case's own keys: before / searched / afterSort, always after, requests and errors)
     *
     * @return Facts
     */
    private static function case(string $name): array
    {
        if (!isset(self::$results[$name])) {
            $node = new ExecutableFinder()->find('node');
            self::assertIsString($node, 'node is required to execute the controller - it is part of the base image');

            $process = new Process([$node, '--no-warnings', __DIR__ . '/player-results-controller-harness.mjs', $name]);
            $process->setTimeout(60);
            $process->mustRun();

            /** @var array<string, array<string, mixed>> $decoded */
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::$results[$name] = $decoded[$name] ?? [];
        }

        $facts = self::$results[$name];
        self::assertArrayNotHasKey('threw', $facts, $name . ': ' . (is_string($facts['threw'] ?? null) ? $facts['threw'] : ''));
        self::assertSame([], $facts['errors'] ?? null, $name . ': nothing thrown or logged');

        /** @var Facts $facts */
        return $facts;
    }
}
