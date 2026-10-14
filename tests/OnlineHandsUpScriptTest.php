<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The finish detector's pure parts run under node by tests/online-hands-up-harness.mjs (docs/features/
 * online-competitions/README.md §5.7): assets/online/hands_up_signal.js - raised hands from pose landmarks (mirrored
 * front cameras, visibility, the person's scale) and from gestures (two open palms), the views -,
 * assets/online/detector_pacing.js - one frame in flight at 4-5 fps, backing off by the inference time, stalls - and
 * the message protocol of assets/online/detector.worker.js against a fake MediaPipe - init, frames, errors as
 * messages, bitmaps always closed, ever increasing timestamps, dispose. Every scenario must report "ok".
 */
final class OnlineHandsUpScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-hands-up-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(25, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
