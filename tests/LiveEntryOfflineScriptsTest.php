<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The live result entry's copies on the device (competition toolkit C4 -
 * docs/features/competitions-management/toolkit/C-judge-page.md §1.5, §1.7, R §2.7) run under node by
 * tests/live-entry-offline-harness.mjs: round snapshots (written after a pause, never the stream token, only for the
 * player the device cookie names, retention and cap, prefetch limits, a broken store), drafts of a result being entered
 * (each kind restored exactly, same player and round only, 10 minutes), the referee's preferences, and the wipe after a
 * sign-out. Every scenario must report "ok".
 */
final class LiveEntryOfflineScriptsTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/live-entry-offline-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(25, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
