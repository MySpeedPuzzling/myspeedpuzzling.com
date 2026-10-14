<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The participant page's live video (docs/features/online-competitions/contracts/phase-1b.md stream VP) run under node
 * by tests/online-compete-video-harness.mjs: when the phone joins the hall and publishes (ready on the active device,
 * the round's video, a takeover, the studio, the after window), who may watch it, the strip of the others (never
 * during the solve), the host's voice around the beeps, the studio invitation from every source through its window,
 * Join / Not now, on air, "Thank you" and back, the second screen - and the runtime against a fake LiveKit: the page's
 * very camera track published and never stopped, retries, refusals, the voice and the strip received, the studio round
 * trip. Real browsers and a real LiveKit: spikes.md S6. Every scenario must report "ok".
 */
final class OnlineCompeteVideoScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-compete-video-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(40, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
