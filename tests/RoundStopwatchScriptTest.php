<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The round stopwatch's clock in the browser (assets/round_stopwatch_clock.js - the projector page, the stopwatch control
 * page, the live entry, the results overview) run under node by tests/round-stopwatch-harness.mjs: a scheduled start
 * counts down and runs from its moment by itself, a time is never negative, the time limit, the countdown's rounding,
 * the newest state wins, the clock offset of the shortest round trip. Every scenario must report "ok".
 */
final class RoundStopwatchScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/round-stopwatch-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(15, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
