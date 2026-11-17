<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The judge page, the results desk and the competitor page compute a `pick` round entry's total and refuse a record in
 * the browser (assets/official_results_puzzles.js, competition toolkit A3) - exactly like the server
 * (RoundEntryPuzzles), or a device would send what the server refuses, or hold back what it takes.
 */
final class OfficialResultsPuzzlesParityTest extends TestCase
{
    private const array IDS = [
        '018d0020-0000-0000-0000-00000000a201',
        '018d0020-0000-0000-0000-00000000a202',
        '018d0020-0000-0000-0000-00000000a203',
        '018d0020-0000-0000-0000-00000000a204',
    ];

    public function testTheBrowserJudgesARecordLikeTheServer(): void
    {
        $cases = [...self::handWritten(), ...self::generated()];
        $expected = array_map(static fn (array $case): array => self::server($case), $cases);

        self::assertSame($expected, $this->runInNode($cases));
    }

    /**
     * @return list<array{items: null|list<array<string, mixed>>, round: array{toSolve: int, puzzles: list<array{roundPuzzleId: string, piecesCount: int}>}, result: null|array<string, int|bool>}>
     */
    private static function handWritten(): array
    {
        [$a, $b, $c] = self::IDS;
        $round = self::round(2, [500, 500, 1000]);

        return [
            ['items' => null, 'round' => $round, 'result' => ['seconds' => 300]],
            ['items' => [['roundPuzzleId' => $a, 'done' => false]], 'round' => $round, 'result' => null],
            ['items' => [['roundPuzzleId' => $a, 'done' => true, 'atSeconds' => 100], ['roundPuzzleId' => $c, 'done' => true, 'atSeconds' => 300]], 'round' => $round, 'result' => ['seconds' => 300]],
            ['items' => [['roundPuzzleId' => $a, 'done' => true, 'atSeconds' => 100], ['roundPuzzleId' => $c, 'done' => true, 'atSeconds' => 300]], 'round' => $round, 'result' => ['seconds' => 301]],
            ['items' => [['roundPuzzleId' => $a, 'done' => true], ['roundPuzzleId' => $b, 'done' => false, 'piecesPlaced' => 312]], 'round' => $round, 'result' => ['piecesPlaced' => 812]],
            ['items' => [['roundPuzzleId' => $a, 'done' => true], ['roundPuzzleId' => $b, 'done' => false, 'piecesPlaced' => 312]], 'round' => $round, 'result' => ['piecesPlaced' => 811]],
            ['items' => [['roundPuzzleId' => $a, 'done' => false]], 'round' => $round, 'result' => ['didNotStart' => true]],
            ['items' => [['roundPuzzleId' => '018d0020-0000-0000-0000-00000000ffff', 'done' => false]], 'round' => $round, 'result' => null],
            ['items' => [['roundPuzzleId' => $a, 'done' => false], ['roundPuzzleId' => $a, 'done' => false]], 'round' => $round, 'result' => null],
            ['items' => [['roundPuzzleId' => $a, 'done' => false], ['roundPuzzleId' => $b, 'done' => false], ['roundPuzzleId' => $c, 'done' => false]], 'round' => $round, 'result' => null],
            ['items' => [['roundPuzzleId' => $a, 'done' => true, 'atSeconds' => 0]], 'round' => $round, 'result' => null],
            ['items' => [['roundPuzzleId' => $a, 'done' => true, 'atSeconds' => RoundEntryResult::MAX_SECONDS + 1]], 'round' => $round, 'result' => null],
            ['items' => [['roundPuzzleId' => $a, 'done' => false, 'piecesPlaced' => 500]], 'round' => $round, 'result' => null],
            ['items' => [['roundPuzzleId' => $a, 'done' => false], ['roundPuzzleId' => $b, 'done' => true]], 'round' => $round, 'result' => null],
            ['items' => [['roundPuzzleId' => $a, 'done' => true, 'atSeconds' => 20], ['roundPuzzleId' => $b, 'done' => true, 'atSeconds' => 20]], 'round' => $round, 'result' => null],
            ['items' => [], 'round' => $round, 'result' => ['didNotStart' => true]],
            // K = N, all three
            ['items' => [['roundPuzzleId' => $c, 'done' => true, 'atSeconds' => 50], ['roundPuzzleId' => $b, 'done' => true], ['roundPuzzleId' => $a, 'done' => true, 'atSeconds' => 90]], 'round' => self::round(3, [500, 500, 1000]), 'result' => ['seconds' => 90]],
        ];
    }

    /**
     * Deterministic pseudo-random records - many of them wrong in some way.
     *
     * @return list<array{items: null|list<array<string, mixed>>, round: array{toSolve: int, puzzles: list<array{roundPuzzleId: string, piecesCount: int}>}, result: null|array<string, int|bool>}>
     */
    private static function generated(): array
    {
        $seed = 11;
        $next = static function (int $modulo) use (&$seed): int {
            $seed = ($seed * 1103515245 + 12345) % 2147483648;

            return intdiv($seed, 65536) % $modulo;
        };

        $cases = [];
        for ($i = 0; $i < 300; $i++) {
            $puzzleCount = 1 + $next(4);
            $pieces = [];
            for ($p = 0; $p < $puzzleCount; $p++) {
                $pieces[] = [100, 500, 1000][$next(3)];
            }
            $round = self::round(1 + $next($puzzleCount), $pieces);

            $items = [];
            $itemCount = $next(4);
            $split = 0;
            for ($j = 0; $j < $itemCount; $j++) {
                $item = ['roundPuzzleId' => self::IDS[$next(4)], 'done' => $next(3) > 0];

                if ($item['done'] && $next(2) === 1) {
                    $split += $next(5) * 100 - 50;
                    $item['atSeconds'] = $split;
                }

                if (!$item['done'] && $next(2) === 1) {
                    $item['piecesPlaced'] = $next(1001);
                }

                $items[] = $item;
            }

            $sum = (RoundEntryPuzzles::fromWire($items)?->piecesPlaced(self::piecesById($round))) ?? 0;
            $result = match ($next(5)) {
                0 => null,
                1 => ['didNotStart' => true],
                2 => ['piecesPlaced' => $next(2) === 1 ? max(1, $sum) : 1 + $next(2000)],
                default => ['seconds' => $next(2) === 1 && $split > 0 ? $split : 1 + $next(2000)],
            };

            $cases[] = ['items' => $items === [] && $next(2) === 1 ? null : $items, 'round' => $round, 'result' => $result];
        }

        return $cases;
    }

    /**
     * @param list<int> $pieces
     * @return array{toSolve: int, puzzles: list<array{roundPuzzleId: string, piecesCount: int}>}
     */
    private static function round(int $toSolve, array $pieces): array
    {
        $puzzles = [];
        foreach ($pieces as $index => $count) {
            $puzzles[] = ['roundPuzzleId' => self::IDS[$index], 'piecesCount' => $count];
        }

        return ['toSolve' => $toSolve, 'puzzles' => $puzzles];
    }

    /**
     * @param array{toSolve: int, puzzles: list<array{roundPuzzleId: string, piecesCount: int}>} $round
     * @return array<string, int>
     */
    private static function piecesById(array $round): array
    {
        return array_column($round['puzzles'], 'piecesCount', 'roundPuzzleId');
    }

    /**
     * @param array{items: null|list<array<string, mixed>>, round: array{toSolve: int, puzzles: list<array{roundPuzzleId: string, piecesCount: int}>}, result: null|array<string, int|bool>} $case
     * @return array{items: null|string, consistency: null|string, validate: null|string, total: int, durations: list<null|int>, maximum: int}
     */
    private static function server(array $case): array
    {
        $pieces = self::piecesById($case['round']);
        $toSolve = $case['round']['toSolve'];
        $puzzles = RoundEntryPuzzles::fromWire($case['items']);
        $items = $puzzles?->itemsRefusal($toSolve, $pieces);
        $consistency = RoundEntryPuzzles::consistencyRefusal(RoundEntryResult::fromWire($case['result']), $puzzles, $toSolve, $pieces);

        return [
            'items' => $items,
            'consistency' => $consistency,
            'validate' => $items ?? $consistency,
            'total' => $puzzles?->piecesPlaced($pieces) ?? 0,
            'durations' => array_map(static fn (int $index): null|int => $puzzles?->duration($index), array_keys($case['items'] ?? [])),
            'maximum' => RoundEntryPuzzles::unfinishedMaximum($toSolve, $pieces),
        ];
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return list<array<string, mixed>>
     */
    private function runInNode(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/official-results-puzzles-harness.mjs']);
        $process->setInput((string) json_encode(['cases' => $cases], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array<string, mixed>> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
