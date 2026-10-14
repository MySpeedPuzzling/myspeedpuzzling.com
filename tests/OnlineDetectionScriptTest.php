<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The participant page's finish detection (docs/features/online-competitions/README.md §5.7, stream P2I) run under node
 * by tests/online-detection-harness.mjs against a fake worker, frame grabber and clock: when the chunk loads, when the
 * detector looks (the set-up test, the round from GO until the result is confirmed - never hidden, without the camera,
 * in a practice or a preview), one worker per session (init with both models, configure to the view, never a second),
 * the set-up test's choice of view and its failure (button-only on the device, "Test again"), the skip while loading,
 * no detector in the browser / no WebGL2 / a broken worker / frame errors = button-only, a candidate in `on` mode (the
 * event at the first raised frame, the burst, the sharpest still, the flash and the chime, a new candidate after the
 * cooldown, "raise again in a moment" inside it), `shadow` recording silently, a new run or mode = a new machine, the
 * heartbeat and device.ready reports. Every scenario must report "ok".
 */
final class OnlineDetectionScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-detection-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(19, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
