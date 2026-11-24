<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidTableLabels;
use SpeedPuzzling\Web\Value\TableLabelGenerator;
use SpeedPuzzling\Web\Value\TableLabels;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Table names (table-labels-and-plates.md): the PHP value and generator against the shared cases
 * tests/fixtures/table-labels-cases.json, and assets/table_labels.js against the same cases under node - one rule in
 * both. Plus the judge's typing over the SPWS hall (A-H × 1-22 without H22): which keystrokes open which table.
 */
final class TableLabelsTest extends TestCase
{
    /**
     * @return array{keys: list<array{string, string}>, validate: list<array{list: list<string>, labels?: list<string>, problems?: list<array<string, string>>}>, generate: list<array{settings: array{letters: string, numbers: string, seats: int, seatStyle: string, order: string, skip: string}, labels?: list<string>, count?: int, first?: string, last?: string, problems?: list<array<string, string>>}>}
     */
    private static function cases(): array
    {
        /** @var array{keys: list<array{string, string}>, validate: list<array{list: list<string>, labels?: list<string>, problems?: list<array<string, string>>}>, generate: list<array{settings: array{letters: string, numbers: string, seats: int, seatStyle: string, order: string, skip: string}, labels?: list<string>, count?: int, first?: string, last?: string, problems?: list<array<string, string>>}>} $cases */
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/table-labels-cases.json'), true, flags: JSON_THROW_ON_ERROR);

        return $cases;
    }

    public function testKeys(): void
    {
        foreach (self::cases()['keys'] as [$text, $key]) {
            self::assertSame($key, TableLabels::key($text), $text);
        }
    }

    public function testValidation(): void
    {
        foreach (self::cases()['validate'] as $case) {
            try {
                $labels = TableLabels::fromList($case['list']);
                self::assertSame($case['labels'] ?? null, $labels->labels, json_encode($case['list'], JSON_THROW_ON_ERROR));
            } catch (InvalidTableLabels $invalid) {
                self::assertSame($case['problems'] ?? null, $invalid->problems, json_encode($case['list'], JSON_THROW_ON_ERROR));
            }
        }
    }

    public function testGenerator(): void
    {
        foreach (self::cases()['generate'] as $case) {
            $settings = $case['settings'];

            try {
                $labels = TableLabelGenerator::generate($settings['letters'], $settings['numbers'], $settings['seats'], $settings['seatStyle'], $settings['order'], $settings['skip']);
            } catch (InvalidTableLabels $invalid) {
                self::assertSame($case['problems'] ?? null, $invalid->problems, json_encode($settings, JSON_THROW_ON_ERROR));

                continue;
            }

            if (isset($case['labels'])) {
                self::assertSame($case['labels'], $labels);
            } else {
                self::assertCount($case['count'] ?? -1, $labels);
                self::assertSame($case['first'] ?? null, $labels[0]);
                self::assertSame($case['last'] ?? null, $labels[count($labels) - 1]);
            }

            // Every generated list is a valid list
            TableLabels::fromList($labels);
        }
    }

    public function testLookup(): void
    {
        $labels = TableLabels::fromList(['A1 L', 'A1 R', 'B07']);

        self::assertSame('A1 R', $labels->labelOf(2));
        // Beyond the list: the number, never blank
        self::assertSame('9', $labels->labelOf(9));
        self::assertSame(2, $labels->numberOf('a1r'));
        self::assertSame(3, $labels->numberOf('b 7'));
        self::assertNull($labels->numberOf('A1'));
        self::assertNull($labels->numberOf(''));
        self::assertTrue($labels->names(3));
        self::assertFalse($labels->names(4));
        self::assertSame('12', TableLabels::display(null, 12));
        self::assertNull(TableLabels::display($labels, null));
        self::assertNull(TableLabels::fromStored(null));
        self::assertNull(TableLabels::fromStored('[]'));
        self::assertSame(['A1'], TableLabels::fromStored('["A1"]')?->labels);
    }

    public function testTheBrowserTwinFollowsTheSameCases(): void
    {
        $cases = self::cases();
        $input = [];

        foreach ($cases['keys'] as [$text]) {
            $input[] = ['op' => 'key', 'text' => $text];
        }

        foreach ($cases['validate'] as $case) {
            $input[] = ['op' => 'validate', 'list' => $case['list']];
        }

        foreach ($cases['generate'] as $case) {
            $input[] = ['op' => 'generate', 'settings' => $case['settings']];
        }

        $output = $this->runInNode($input);
        $index = 0;

        foreach ($cases['keys'] as [$text, $key]) {
            self::assertSame($key, $output[$index++], 'key ' . $text);
        }

        foreach ($cases['validate'] as $case) {
            $result = $output[$index++];
            self::assertIsArray($result);
            self::assertSame(isset($case['labels']) ? ['labels' => $case['labels']] : ['problems' => $case['problems'] ?? []], $result);
        }

        foreach ($cases['generate'] as $case) {
            $result = $output[$index++];
            self::assertIsArray($result);

            if (isset($case['problems'])) {
                self::assertSame(['problems' => $case['problems']], $result);
            } else {
                /** @var list<string> $labels */
                $labels = $result['labels'];
                $settings = $case['settings'];
                self::assertSame(TableLabelGenerator::generate($settings['letters'], $settings['numbers'], $settings['seats'], $settings['seatStyle'], $settings['order'], $settings['skip']), $labels);
            }
        }
    }

    public function testMyTablesReadLabelsOnALabelledRound(): void
    {
        $list = ['A1 L', 'A1 R', 'A12 L', 'B1 L', 'B1 R'];

        self::assertSame([
            // A range between whole tables, list order
            ['ranges' => [[1, 3]]],
            // A whole column and one place
            ['ranges' => [[2, 2], [4, 5]]],
            ['error' => 'unknown', 'token' => 'Z'],
            ['error' => 'syntax', 'token' => 'A1..B1..A12'],
        ], $this->runInNode([
            ['op' => 'ranges', 'text' => 'A1..A12', 'list' => $list],
            ['op' => 'ranges', 'text' => 'B, A1 r', 'list' => $list],
            ['op' => 'ranges', 'text' => 'Z', 'list' => $list],
            ['op' => 'ranges', 'text' => 'A1..B1..A12', 'list' => $list],
        ]));
    }

    /**
     * @return iterable<string, array{array{letters: string, numbers: string, seats: int, seatStyle: string}, list<array{string, null|string}>}>
     */
    public static function typing(): iterable
    {
        $hall = ['letters' => 'A-H', 'numbers' => '1-22'];

        // One team per table: like numbers today - "a5" opens A5, "a1" waits for A10…A19, "a12" opens A12
        yield 'one per table' => [$hall + ['seats' => 1, 'seatStyle' => 'dash'], [['a5', 'A5'], ['a1', null], ['a12', 'A12'], ['g22', 'G22'], ['h22', null], ['anna', null]]];
        // Two per table, left / right: no separator needed
        yield 'left right' => [$hall + ['seats' => 2, 'seatStyle' => 'side'], [['a1l', 'A1 L'], ['a1 r', 'A1 R'], ['a1', null], ['b12r', 'B12 R'], ['a12', null]]];
        // Two per table, A1-1: the place needs a separator ("a11" is table A11)
        yield 'dash' => [$hall + ['seats' => 2, 'seatStyle' => 'dash'], [['a1 1', 'A1-1'], ['a1-2', 'A1-2'], ['a11', null], ['a11 2', 'A11-2']]];
    }

    /**
     * @param array{letters: string, numbers: string, seats: int, seatStyle: string} $settings
     * @param list<array{string, null|string}> $expected typed => the table that opens without Enter
     */
    #[DataProvider('typing')]
    public function testTheJudgeTypesATableOfTheHall(array $settings, array $expected): void
    {
        $labels = TableLabelGenerator::generate($settings['letters'], $settings['numbers'], $settings['seats'], $settings['seatStyle'], 'letters', 'H22');
        $output = $this->runInNode([['op' => 'typing', 'list' => $labels, 'typed' => array_map(static fn (array $pair): string => $pair[0], $expected)]]);

        /** @var list<array{typed: string, opens: null|string}> $rows */
        $rows = $output[0];

        foreach ($expected as $index => [$typed, $opens]) {
            self::assertSame($opens, $rows[$index]['opens'], 'typed ' . $typed);
        }
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return list<mixed>
     */
    private function runInNode(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/../table-labels-harness.mjs']);
        $process->setInput((string) json_encode($cases, JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
