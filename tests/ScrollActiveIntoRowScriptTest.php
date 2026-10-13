<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The current pill of a sideways-scrolling row - the leaderboards' pill row, the section chips (docs/features/navigation
 * COM-02) - is brought into view by assets/navigation/scroll_active_into_row.js: centred when it lies past the row's
 * right edge, left alone when it fits; the row scrolls, never the page (no scrollIntoView anywhere in it).
 */
final class ScrollActiveIntoRowScriptTest extends TestCase
{
    public function testTheCurrentPillIsCentredOnlyWhenItLiesPastTheEdge(): void
    {
        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the module - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/scroll-active-into-row-harness.mjs']);
        $process->mustRun();

        self::assertSame([
            'fits' => ['moved' => false, 'scrollLeft' => 0],
            // 400 - (360 - 120) / 2
            'pastTheEdge' => ['moved' => true, 'scrollLeft' => 280],
            'missing' => ['noActive' => false, 'noRow' => false],
        ], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testThePageItselfNeverScrolls(): void
    {
        foreach (['assets/navigation/scroll_active_into_row.js', 'assets/controllers/section_chips_controller.js', 'assets/controllers/leaderboards_nav_controller.js'] as $file) {
            self::assertDoesNotMatchRegularExpression('/\.scrollIntoView\s*\(/', (string) file_get_contents(__DIR__ . '/../' . $file), $file);
        }
    }
}
