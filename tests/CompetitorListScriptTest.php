<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\SearchText;
use SpeedPuzzling\Web\Value\TableLabels;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The competitor list's search in the browser (assets/competitor_list_filter.js, competitor_list_controller.js): the
 * rows' `data-search` is folded in PHP (GetCompetitorList, SearchText::fold()), the typed words in the browser - every
 * word must be in a row. Runs the real module under node.
 */
final class CompetitorListScriptTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<list<bool>>, list<int>}>
     */
    public static function provideQueries(): iterable
    {
        yield 'nothing typed shows everybody' => ['', [[true, true, true], [true, true]], [3, 2]];
        yield 'an accent and capitals typed' => ['ÁNN', [[true, false, false], [true, false]], [1, 1]];
        yield 'words in any order' => ['fast anna', [[true, false, false], [true, false]], [1, 1]];
        yield 'a shown #code' => ['#admin', [[true, false, false], [false, false]], [1, 0]];
        yield 'the code typed in capitals' => ['#ADMIN', [[true, false, false], [false, false]], [1, 0]];
        yield 'a team name' => ['sharks', [[false, false, false], [true, false]], [0, 1]];
        yield 'a name folded on the server' => ['zofia', [[false, false, true], [false, true]], [1, 1]];
        yield 'nobody matches' => ['xq', [[false, false, false], [false, false]], [0, 0]];
        yield 'spaces only' => ['   ', [[true, true, true], [true, true]], [3, 2]];
    }

    /**
     * @param list<list<bool>> $shown
     * @param list<int> $counts
     */
    #[DataProvider('provideQueries')]
    public function testTheTypedWordsFilterTheServersRows(string $query, array $shown, array $counts): void
    {
        // What GetCompetitorList renders: people (name + a shown #code), line-ups (team name + members)
        $sections = [
            [
                SearchText::fold('Anna Fast') . ' #' . SearchText::fold('ADMIN'),
                SearchText::fold('Ben Steady'),
                SearchText::fold('Zofia Łukasiewicz'),
            ],
            [
                SearchText::fold('Puzzle Sharks Anna Fast Ben Steady'),
                SearchText::fold('Żofia Łukasiewicz Cara Tied'),
            ],
        ];

        $result = $this->runInNode([['sections' => $sections, 'query' => $query]])[0];

        self::assertSame($shown, $result['shown']);
        self::assertSame($counts, $result['counts']);
        self::assertSame(array_sum($counts), $result['total']);
    }

    /**
     * @return iterable<string, array{string, list<bool>, list<int>, list<list<string>>}>
     */
    public static function provideTableQueries(): iterable
    {
        // Rows: 0 A10 L, 1 A1 L, 2 A1 (a team's table), 3 A1 R, 4 H21 R, 5 table 12, 6 table 120, 7 no table
        $none = [[], [], [], [], [], [], [], []];
        yield 'nothing typed: everybody in the server order' => ['', [true, true, true, true, true, true, true, true], [0, 1, 2, 3, 4, 5, 6, 7], $none];
        yield 'a1: the exact table, whole segments, then started' => ['a1', [true, true, true, true, false, false, false, false], [2, 1, 3, 0, 4, 5, 6, 7], [['A.10.L'], ['A.1.L'], ['A.1'], ['A.1.R'], [], [], [], []]];
        yield 'A1 L beats A10 L' => ['A1 L', [false, true, false, false, false, false, false, false], [1, 0, 2, 3, 4, 5, 6, 7], [[], ['A.1.L'], [], [], [], [], [], []]];
        yield 'a1l without a space' => ['a1l', [false, true, false, false, false, false, false, false], [1, 0, 2, 3, 4, 5, 6, 7], [[], ['A.1.L'], [], [], [], [], [], []]];
        yield 'a1-l with a dash' => ['a1-l', [false, true, false, false, false, false, false, false], [1, 0, 2, 3, 4, 5, 6, 7], [[], ['A.1.L'], [], [], [], [], [], []]];
        yield 'h21 r in lower case' => ['h21 r', [false, false, false, false, true, false, false, false], [4, 0, 1, 2, 3, 5, 6, 7], [[], [], [], [], ['H.21.R'], [], [], []]];
        yield 'a plain table number first, then the longer ones' => ['12', [false, false, false, false, false, true, true, false], [5, 6, 0, 1, 2, 3, 4, 7], [[], [], [], [], [], ['12'], ['120'], []]];
        yield 'a letter alone is a name search, not a column' => ['a', [false, false, false, false, false, false, false, false], [0, 1, 2, 3, 4, 5, 6, 7], $none];
        yield 'a name still finds its row, no table shown' => ['zed', [true, false, false, false, false, false, false, false], [0, 1, 2, 3, 4, 5, 6, 7], $none];
        yield 'a table nobody has' => ['b7', [false, false, false, false, false, false, false, false], [0, 1, 2, 3, 4, 5, 6, 7], $none];
        yield 'the word table first' => ['Table A1 L', [false, true, false, false, false, false, false, false], [1, 0, 2, 3, 4, 5, 6, 7], [[], ['A.1.L'], [], [], [], [], [], []]];
        yield 'the word table in Czech' => ['stůl 12', [false, false, false, false, false, true, true, false], [5, 6, 0, 1, 2, 3, 4, 7], [[], [], [], [], [], ['12'], ['120'], []]];
        yield 'a name and a table' => ['yew a1', [false, true, false, false, false, false, false, false], [1, 0, 2, 3, 4, 5, 6, 7], [[], ['A.1.L'], [], [], [], [], [], []]];
        yield 'another name before the table' => ['zed a1 l', [false, false, false, false, false, false, false, false], [0, 1, 2, 3, 4, 5, 6, 7], $none];
        yield 'a #code is never a table' => ['#12', [false, false, false, false, false, false, false, false], [0, 1, 2, 3, 4, 5, 6, 7], $none];
    }

    /**
     * @param list<bool> $shown
     * @param list<int> $order
     * @param list<list<string>> $tables
     */
    #[DataProvider('provideTableQueries')]
    public function testATypedTableFindsItsRowsExactNameFirst(string $query, array $shown, array $order, array $tables): void
    {
        $rows = [
            ['search' => 'zed', 'tables' => ['A.10.L']],
            ['search' => 'yew', 'tables' => ['A.1.L']],
            ['search' => 'xiu', 'tables' => ['A.1']],
            ['search' => 'wu', 'tables' => ['A.1.R']],
            ['search' => 'vic', 'tables' => ['H.21.R']],
            ['search' => 'tom', 'tables' => ['12']],
            ['search' => 'sol', 'tables' => ['120']],
            ['search' => 'rex', 'tables' => []],
        ];

        $result = $this->runInNode([['sections' => [$rows], 'query' => $query]])[0];

        self::assertSame([$shown], $result['shown']);
        self::assertSame([$order], $result['order']);
        self::assertSame([$tables], $result['tables']);
    }

    public function testTableMatchesComeFirstInEverySectionAndANameMatchFollows(): void
    {
        $sections = [
            [['search' => 'anna a10 fan', 'tables' => ['B.2']], ['search' => 'ben', 'tables' => ['A.10']]],
            [['search' => 'sharks', 'tables' => ['C.1']], ['search' => 'corners', 'tables' => ['A.10', 'D.4']]],
        ];

        $result = $this->runInNode([['sections' => $sections, 'query' => 'a10']])[0];

        // Anna's name holds "a10" (made up) - she still shows, after the table match
        self::assertSame([[true, true], [false, true]], $result['shown']);
        self::assertSame([[1, 0], [1, 0]], $result['order']);
        self::assertSame([[[], ['A.10']], [[], ['A.10']]], $result['tables']);
        self::assertSame([2, 1], $result['counts']);
    }

    /**
     * The keys the browser compares are the server's: TableLabels::key() of what a table reads as.
     */
    public function testTheServersTableKeysAreWhatTheBrowserTypes(): void
    {
        $rows = [
            ['search' => 'one', 'tables' => [TableLabels::key('A1 L')]],
            ['search' => 'two', 'tables' => [TableLabels::key('A1-1')]],
            ['search' => 'three', 'tables' => [TableLabels::key('07')]],
        ];

        $results = $this->runInNode([
            ['sections' => [$rows], 'query' => 'a1l'],
            ['sections' => [$rows], 'query' => 'A1 1'],
            ['sections' => [$rows], 'query' => '7'],
        ]);

        self::assertSame([[true, false, false]], $results[0]['shown']);
        self::assertSame([[false, true, false]], $results[1]['shown']);
        self::assertSame([[false, false, true]], $results[2]['shown']);
    }

    /**
     * @param list<array{sections: list<list<string|array{search: string, tables: list<string>}>>, query: string}> $cases
     * @return list<array{shown: list<list<bool>>, counts: list<int>, total: int, order: list<list<int>>, tables: list<list<list<string>>>}>
     */
    private function runInNode(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/competitor-list-harness.mjs']);
        $process->setInput(json_encode(['cases' => $cases], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array{shown: list<list<bool>>, counts: list<int>, total: int, order: list<list<int>>, tables: list<list<list<string>>>}> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
