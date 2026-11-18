<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The live control's pure module under node - assets/displays/control_picker.js (competition toolkit v2 L1/L2) by
 * tests/display-control-harness.mjs: message rules, recent chips, spotlight chips, filter, "Just finished" and the
 * optimistic one-tap choice. The scenarios live in the harness; every one must report "ok".
 */
final class DisplayControlScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/display-control-harness.mjs']);
        $process->setTimeout(60);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(8, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
