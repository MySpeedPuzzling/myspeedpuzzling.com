<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The live video wrapper (assets/online/livekit_session.js, docs/features/online-competitions/contracts/phase-1b.md §6)
 * run under node by tests/online-livekit-harness.mjs against a fake livekit-client: the identities, who may subscribe
 * (nobody before the room is known, the moderators present before the camera is published, a moderator coming later,
 * everybody when asked), the simulcast plan, data messages by topic, participants, attaching, leaving - and the page's
 * camera published as it is and never stopped, by the wrapper or by LiveKit's own stop paths, enabled again after the
 * background. Real browsers and a real LiveKit: spikes.md S6. Every scenario must report "ok".
 */
final class OnlineLiveKitScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-livekit-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(17, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
