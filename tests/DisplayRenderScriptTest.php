<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The display renderer under node - assets/displays/render.js (competition toolkit D4) in jsdom by
 * tests/display-render-harness.mjs: every element type of the v1 catalogue, phases, timer formats, results source gating
 * per element, puzzles only once revealed, QR, leaderboard diffing, the XSS corpus of
 * tests/fixtures/display-design-cases.json (no element or attribute injection) and no HTML sinks in the display scripts.
 * The scenarios live in the harness; every one must report "ok".
 */
final class DisplayRenderScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/display-render-harness.mjs']);
        $process->setTimeout(300);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(50, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
