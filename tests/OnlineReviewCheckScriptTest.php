<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The completeness check's part of the online review (docs/features/online-competitions/README.md §5.8, Phase 3) run
 * under node by tests/online-review-check-harness.mjs: assets/online/review_check.js - a photo's badge, the suspected
 * gaps as boxes scaled to the shown photo, the list's "needs a look" order (gaps, not sure, not checked, complete;
 * confirmed participants keep their place). Every scenario must report "ok".
 */
final class OnlineReviewCheckScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-review-check-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(12, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
