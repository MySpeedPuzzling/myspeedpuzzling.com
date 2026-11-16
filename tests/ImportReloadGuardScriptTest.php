<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * docs/features/data-import/adding-screens.md "Check and add follows by itself" - the real guard of the page's own
 * reload (assets/data_import_reload_guard.js, what data_import_setup_controller.js asks before it loads the page
 * again), run by node (tests/import-reload-guard-harness.mjs): the page never loads itself again against the player -
 * not once they are leaving it (a click 10 ms after a poll started must not be answered by a reload of the page
 * they left), not under a form they touched.
 */
final class ImportReloadGuardScriptTest extends TestCase
{
    public function testThePageIsNeverLoadedAgainOnceThePlayerIsLeavingIt(): void
    {
        $facts = $this->facts();

        self::assertSame(['reload', 'reload'], $facts['idle']);
        self::assertSame(['no', 'no'], $facts['gone'], 'a page that is gone, or a controller that stopped');

        foreach (['turbo:click', 'turbo:before-visit', 'turbo:visit', 'turbo:submit-start', 'beforeunload', 'pagehide'] as $way) {
            // Before: a reload was fine. After: leaving, whoever asked was told exactly once, and nothing reloads -
            // whatever the player stands on
            self::assertSame(['reload', true, 1, 'no', 'no'], $facts['leaves by ' . $way], $way);
        }

        // The poll that was on its way is aborted by the click, and its answer - if it still comes - reloads nothing
        self::assertSame([true, 'no'], $facts['race']);
        // A frame of the page fetching is not the page being left; Turbo's fetch for a visit is
        self::assertSame([[false, 'reload'], [true, 'no']], $facts['fetches']);
        // After disconnect it listens to nothing
        self::assertSame([true, 0, false], $facts['disposed']);
    }

    public function testThePageIsNotLoadedAgainUnderAFormThePlayerTouched(): void
    {
        $facts = $this->facts();

        // Typing outside a form says nothing; a changed field of a form does: the "Refresh" link instead
        self::assertSame([[false, 'reload'], [true, 'refresh']], $facts['touched']);
        // Standing in a field is enough; standing on a link is not
        self::assertSame(['refresh', 'reload'], $facts['inAField']);
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(): array
    {
        $node = (new ExecutableFinder())->find('node');

        if ($node === null) {
            self::markTestSkipped('node is not installed.');
        }

        $process = new Process([$node, __DIR__ . '/import-reload-guard-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $facts */
        $facts = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $facts;
    }
}
