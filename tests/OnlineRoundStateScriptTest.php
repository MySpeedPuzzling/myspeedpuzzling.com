<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * What a phone shows for an online round (assets/online/round_state.js, docs/features/online-competitions/README.md
 * §5.1, §5.3) run under node by tests/online-round-state-harness.mjs: waiting, scheduled, the 10-second countdown,
 * running, ended by the limit or by "End now", a late joiner, a cancelled countdown, the newest state winning by
 * asOfMs (a pushed clock surviving an older fetch), the time formats and the official time of a moment. Every scenario
 * must report "ok".
 */
final class OnlineRoundStateScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-round-state-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(16, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
