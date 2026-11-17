<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The live clock of the public competitor page (assets/competitor_page_live.js, competition toolkit B5,
 * docs/features/competitions-management/competitor-pages.md "Live clock") run under node by
 * tests/competitor-page-live-harness.mjs with a fake clock, timers, state fetch and stream: phases from the state, the
 * server clock offset and a synchronous tap time, a countdown turning into the running clock at the moment, a stop push
 * freezing it, the spread of state fetches (push 0-1 s, reconnect 0-3 s, reveal moments), polling and backoff, topics
 * within ±12 h, the wake lock, pages switched off. Every scenario must report "ok".
 */
final class CompetitorPageLiveScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/competitor-page-live-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(15, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
