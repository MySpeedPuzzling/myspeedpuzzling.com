<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The live result entry's offline copy (competition toolkit C5 - docs/features/competitions-management/toolkit/
 * C-judge-page.md §1.6, R-review-and-plan.md §1.3) run under node by tests/live-entry-shell-harness.mjs: every live
 * entry URL the service worker may answer with the copy (locales, no locale, other hosts, garbage, the printed name tag
 * unchanged), the round shown from the device's snapshots (the current-round port shares its cases with
 * LiveResultsCurrentRoundTest), the neutral screens and the way back to the live page. Every scenario must report "ok".
 */
final class LiveEntryShellScriptsTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/live-entry-shell-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(15, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
