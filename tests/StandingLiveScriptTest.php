<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The live part of the public standing page (assets/standing_live.js, competition toolkit v2 S4,
 * docs/features/competitions-management/standings.md "Live push") run under node by tests/standing-live-harness.mjs
 * with a fake clock, timers, fragment fetch and stream: the anonymous subscription, the 0-4 s jitter, signals coalescing
 * (one fetch at a time, one more after it), a hidden page fetching on being shown again, the 60 s poll while the stream
 * is refused or down, the 5-minute safety poll while it is live, 404 stopping everything. Every scenario must report "ok".
 */
final class StandingLiveScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/standing-live-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(15, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
