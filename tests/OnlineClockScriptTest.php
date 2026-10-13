<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The server's time on a participant's phone (assets/online/clock_sync.js, docs/features/online-competitions/README.md
 * §5.2) run under node by tests/online-clock-harness.mjs against a simulated world - the phone's performance.now() and
 * Date.now(), the server's clock, a network with its own delay each way: the smallest round trip wins, an asymmetric
 * route stays within half the round trip, a sleep and a wall clock change, small corrections slewed and big ones at
 * once, a monotonic clock drifting for 30 minutes, odd samples waiting for a confirmation, Resource Timing, the
 * readiness gate. Every scenario must report "ok".
 */
final class OnlineClockScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-clock-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(19, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
