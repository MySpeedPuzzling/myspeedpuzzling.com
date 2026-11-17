<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * "Done - propose my time" on the competitor's own page (competition toolkit B8,
 * docs/features/competitions-management/competitor-pages.md "Propose my time") run under node by
 * tests/competitor-proposals-harness.mjs: the phone's outbox (assets/competitor_proposals_outbox.js - the tap's id and
 * moment kept across resends, offline and a reload, refusals, signed out, Undo before / while / after sending), what
 * the round's slot shows (assets/competitor_proposals_view.js - Stop only on the own running entry, every proposal
 * state), the 5-second poll while a proposal waits for the judge, and T2's timing evidence (what a send says about
 * how the tap travelled, the outbox summary, the heartbeat - assets/competitor_heartbeat.js). Every scenario must
 * report "ok".
 */
final class CompetitorProposalsScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/competitor-proposals-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(26, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
