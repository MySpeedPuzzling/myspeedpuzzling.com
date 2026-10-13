<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The participant page's outboxes (assets/online/event_outbox.js, assets/online/media_outbox.js,
 * docs/features/online-competitions/README.md §5.6, §5.11) run under node by tests/online-outbox-harness.mjs on
 * in-memory stores with a fake clock, a fake API and a fake object store: stored before sent, batches, refused events
 * kept aside, offline / 429 Retry-After / server error backoff, one sending tab, a reload, a broken store; presign →
 * upload → report, critical before periodic, expired and refused URLs presigned again, the store failing, periodic
 * photos thinned under quota pressure while critical ones are never dropped. Every scenario must report "ok".
 */
final class OnlineOutboxScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/online-outbox-harness.mjs']);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(32, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
