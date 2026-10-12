<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The bottom bar's label steps (docs/features/navigation README D14; implementation plan NAV-02, decision 95):
 * assets/bottom_nav_labels.js chooseStep() run under node (tests/bottom-nav-labels-harness.mjs). Steps are numbered as
 * the requirement - 1 = 11 px, 2 = 10 px, 3 = only the current tab's label. CSS picks the step before first paint; the
 * browser's look may only raise it: the lowest step whose font fits every label in a tab's room, never below the CSS
 * step, and nothing to decide without labels or room.
 */
final class BottomNavLabelsScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testTheStepsAreNumberedAsTheRequirement(): void
    {
        self::assertSame(['STEP_11PX' => 1, 'STEP_10PX' => 2, 'STEP_CURRENT_ONLY' => 3, 'SAFETY_PX' => 0.5], self::results()['constants']);
    }

    public function testTheLowestStepThatFitsEveryLabel(): void
    {
        self::assertSame(1, self::results()['everything fits at 11 px']);
        self::assertSame(1, self::results()['the widest label is exactly at the room']);
        self::assertSame(2, self::results()['half a pixel too wide at 11 px → 10 px']);
        self::assertSame(2, self::results()['French Communauté at 360 → 10 px']);
        self::assertSame(3, self::results()['French Communauté at 320 → current label only']);
        self::assertSame(2, self::results()['a wider fallback font for Japanese at 360 → 10 px']);
    }

    public function testItOnlyEverRaisesTheCssStep(): void
    {
        self::assertSame(2, self::results()['CSS step 2 and the labels fit: stays 2']);
        self::assertSame(2, self::results()['CSS step 2 never goes back to 1, even with room to spare']);
        self::assertSame(3, self::results()['CSS step 2 but a label still overflows at 10 px → 3']);
        self::assertSame(3, self::results()['CSS step 3 stays 3']);
    }

    public function testWithoutAnythingToMeasureCssDecides(): void
    {
        self::assertSame(1, self::results()['no labels']);
        self::assertSame(2, self::results()['no room (bar not laid out)']);
        self::assertSame(1, self::results()['junk widths are ignored']);
        self::assertSame(2, self::results()['junk widths next to real ones']);
        self::assertSame(1, self::results()['no argument at all']);
        self::assertSame(2, self::results()['junk css step counts as 1']);
    }

    public function testReadingAStep(): void
    {
        self::assertSame([1, 2, 3, 1, 1, 1, 1, 1, 1, 2], self::results()['readStep']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the module - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/bottom-nav-labels-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
