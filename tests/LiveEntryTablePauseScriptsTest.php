<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The judge's typed table name that opens after a pause and the solve-all order of "Didn't finish"
 * (spws-demo-ux-leftovers.md §2 + §3) - assets/live_entry_table_pause.js and live_entry_puzzles.js run under node by
 * tests/live-entry-table-pause-harness.mjs. Every scenario must report "ok".
 */
final class LiveEntryTablePauseScriptsTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/live-entry-table-pause-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(9, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
