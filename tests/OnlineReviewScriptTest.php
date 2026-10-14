<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The online review's pure modules run under node by tests/online-review-harness.mjs (docs/features/online-competitions/
 * README.md §5.9, implementation-plan.md WP 1.7 + 1.8): assets/online/review_logic.js - the official time of a moment,
 * a burst's manifest sliced into its frames, the frame nearest a tap, the confirmation of a moment (button or reviewer)
 * and of pieces, the statistics (the same cases as AccuracySummaryTest), the keys - and assets/online/review_control.js
 * - the control room's readiness, online, camera, clock quality, another device on the link, finish, counts, filters
 * and photo refreshes. Every scenario must report "ok".
 */
final class OnlineReviewScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-review-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(22, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
