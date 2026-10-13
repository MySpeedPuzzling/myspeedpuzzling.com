<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The participant page's decisions (assets/online/compete_flow.js) and its requests (assets/online/compete_api.js) run
 * under node by tests/online-compete-harness.mjs (docs/features/online-competitions/README.md §5.5, §5.6): the screens
 * from welcome to after, time's up only for a page that took part, a takeover, the undo window, the checklist's rows and
 * their codes, the periodic-photo cadence, pieces, practice, the clock's short form, what the device remembers, and the
 * API's answers - clock samples, no cookies, a dead link. Every scenario must report "ok".
 */
final class OnlineCompeteScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-compete-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(19, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
