<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The plain part of the public watch page of a round's studio (assets/online/watch_view.js, contract phase-1b.md §5
 * `online_watch`) run under node by tests/online-watch-harness.mjs: the waits before trying again (never a reload),
 * what each refused token request means, the studio's tiles - a shared screen, the hosts, the guests, each keeping its
 * place - and its sounds, the grid on a phone and a wide screen, the number watching, the curtain, the round's clock
 * and the sound button. A real browser against a real LiveKit: spikes.md S6. Every scenario must report "ok".
 */
final class OnlineWatchScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-watch-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(18, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
