<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The display runtime under node - assets/displays/runtime.js (competition toolkit D4) with a fake clock, timers, fetch
 * and stream by tests/display-runtime-harness.mjs: signal throttling, one fetch in flight, polling, stopwatch pushes,
 * refreshAtMs, scheduled starts, the invalid link, the reload policy, design swaps, clock resync, wake lock and a leak
 * check.
 * The scenarios live in the harness; every one must report "ok".
 */
final class DisplayRuntimeScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/display-runtime-harness.mjs']);
        $process->setTimeout(300);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(14, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
