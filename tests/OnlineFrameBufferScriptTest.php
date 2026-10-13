<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The finish burst's memory (assets/online/frame_buffer.js, docs/features/online-competitions/README.md §5.6) run under
 * node by tests/online-frame-buffer-harness.mjs with fake timers: the ring's window, frames closer than 1000/fps
 * dropped, ±5 s around a moment (the frames before kept although the ring lets them go), manifest offsets that give
 * every frame back, the window's ends, a camera that stops, out-of-order frames, overlapping bursts, cancelling.
 * Every scenario must report "ok".
 */
final class OnlineFrameBufferScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-frame-buffer-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(13, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
