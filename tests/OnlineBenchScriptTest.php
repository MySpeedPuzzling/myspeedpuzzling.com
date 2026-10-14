<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The detection bench's pure core (tools/online-bench/lib/, docs/features/online-competitions/spikes.md S2) run under
 * node by tests/online-bench-harness.mjs: the S1 label file and its list form for several raises, and the scoring -
 * signals through the finish machine, detection error, misses, false raises, the finish, thinning to the phone's frame
 * rate, signals recomputed from landmarks, the sweep's ranking, CSV and the markdown summary. Every scenario must
 * report "ok".
 */
final class OnlineBenchScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-bench-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(13, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
