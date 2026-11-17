<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The "Get ready" checklist's remembered collapse (W2 of the event creation wizard, assets/get_ready_collapse.js) run by
 * node (tests/get-ready-collapse-harness.mjs): per event, open by default, open right after Publish, and never an error
 * when localStorage is missing or throws.
 */
final class GetReadyCollapseScriptTest extends TestCase
{
    public function testTheCollapseIsRememberedPerEventAndNeverBreaksThePage(): void
    {
        $node = (new ExecutableFinder())->find('node');

        if ($node === null) {
            self::markTestSkipped('node is not installed.');
        }

        $process = new Process([$node, __DIR__ . '/get-ready-collapse-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $facts */
        $facts = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([false, true], $facts['fresh'], 'open until collapsed');
        self::assertSame([true, false, false, ['msp-get-ready-collapsed:a']], $facts['collapsed'], 'remembered for its event only');
        self::assertTrue($facts['forced'], 'open right after Publish');
        self::assertSame([false, 0], $facts['reopened'], 'opening forgets the key');
        self::assertSame([false, true], $facts['throwing'], 'a throwing storage: nothing remembered, open');
        self::assertSame([false, true], $facts['missing']);
    }
}
