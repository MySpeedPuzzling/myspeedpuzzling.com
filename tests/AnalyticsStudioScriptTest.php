<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The Analytics Studio's browser modules (docs/features/competitions-management/analytics-studio.md §7, assets/
 * analytics-studio/) run under node by tests/analytics-studio-harness.mjs: the model (search, filters, sorts, R8 - live
 * results never ordered, compare cap 4), the URL round trip, the keymap, the refresh planner, formatting and render.js
 * (every panel, the empty states, a hidden slot without puzzle data, the XSS corpus). Every scenario must report "ok".
 */
final class AnalyticsStudioScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        // jsdom renders the page many times - generous on a busy CI runner
        $process = new Process([$node, __DIR__ . '/analytics-studio-harness.mjs'], timeout: 300);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(50, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
