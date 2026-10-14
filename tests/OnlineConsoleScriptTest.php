<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The pure parts of the moderators' console and the host's studio page (assets/online/console_grid.js,
 * console_talk.js, console_invites.js, console_studio.js - docs/features/online-competitions/contracts/phase-1b.md
 * stream VM) run under node by tests/online-console-harness.mjs: the grid's order (the finished first, the newest tap
 * on top), paging, which cameras are received at which simulcast layer (the page small, the spotlight large, nobody
 * else), why a tile has no video, push-to-talk (muted until held, several holders, the latch, losing focus, the Space
 * key never typing into a field), the studio's invitations (only forward, newer replaces older, expiry, what a tile
 * offers, polling) and the studio page's audience, stage and lists. Real browsers and a real LiveKit: spikes.md S6.
 * Every scenario must report "ok".
 */
final class OnlineConsoleScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-console-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(18, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
