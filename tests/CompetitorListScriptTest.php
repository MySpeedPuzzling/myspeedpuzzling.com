<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\SearchText;
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
     * @param list<array{sections: list<list<string>>, query: string}> $cases
     * @return list<array{shown: list<list<bool>>, counts: list<int>, total: int}>
     */
    private function runInNode(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/competitor-list-harness.mjs']);
        $process->setInput(json_encode(['cases' => $cases], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array{shown: list<list<bool>>, counts: list<int>, total: int}> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
