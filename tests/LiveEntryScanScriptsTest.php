<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Continuous scanning with a warm camera on the judge page (judge-speed J4, live-results.md "Scanning") - the camera's
 * state machine assets/live_entry_scan_session.js on the building blocks of assets/official_results_scan.js, run under
 * node by tests/live-entry-scan-harness.mjs with a fake camera, tracks, video, reader and timers: one getUserMedia per
 * warm stretch, released after 90 s idle / hidden / ended, a code acted on once per scan, the just-saved tag ignored
 * while in view. Every scenario must report "ok".
 */
final class LiveEntryScanScriptsTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/live-entry-scan-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(19, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
