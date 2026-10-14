<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The participant page's device modules run under node against fake browser APIs by tests/online-devices-harness.mjs
 * (docs/features/online-competitions/README.md §5.5 "Browser realities"): the camera (front by default, the Android
 * main-lens re-pick shared with the barcode scanner, refusals, mute / unmute / ended, two opens at once), the wake lock
 * (asked on a tap, again on every return), the countdown's beeps on the audio clock and the chime of a detected finish,
 * and the frame grabber (capture times, one encoding at a time, the fallbacks, photos, how sharp a still is, the finish
 * detector's feed of small bitmaps). Real cameras are tested by hand - device-test.md. Every
 * scenario must report "ok".
 */
final class OnlineDevicesScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-devices-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(19, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
