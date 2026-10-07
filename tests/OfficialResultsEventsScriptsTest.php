<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The live updates stream of the official results organiser pages (assets/official_results_events.js,
 * docs/features/competitions-management/official-results.md "Live updates") run under node by
 * tests/official-results-events-harness.mjs against a fake hub, clock and timers: the SSE parser, the token as a
 * header, reconnecting with backoff and Last-Event-ID, the catch-up, 401 and token renewal (new stream before the old
 * one closes), a silent stream, signed out / no rights, closing. Every scenario must report "ok".
 */
final class OfficialResultsEventsScriptsTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/official-results-events-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(21, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
