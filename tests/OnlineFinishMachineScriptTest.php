<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The finish rule (assets/online/finish_machine.js, docs/features/online-competitions/README.md §5.7) run under node
 * by tests/online-finish-machine-harness.mjs over synthetic signal sequences: a clean raise, a 0.4 s raise, a
 * flickering raise, dropped frames at high and low frame rates, a premature raise + the real one after the cooldown,
 * a raise during the cooldown, one straddling it (all three policies), frame gaps (restart and bridge), the three
 * modes, minScore, out-of-order frames, state() and reset(). Every scenario must report "ok".
 */
final class OnlineFinishMachineScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-finish-machine-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(22, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
