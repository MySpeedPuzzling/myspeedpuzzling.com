<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Tests\Navigation\ScssReader;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Long-press a cut tab shows its label (docs/features/navigation README §4.13 G16, requirement GEST-18):
 * assets/tab_long_press.js run under node on a fake clock (tests/tab-long-press-harness.mjs) - 500 ms held without
 * moving more than 10 px shows the bubble, the release then never navigates, a shorter tap does, the bubble goes on
 * release or after 1.5 s; which tabs are cut (NAV-02's step 3 for every tab but the current one, or a label wider than
 * its box); iPhones and Android phones (touch, pen) get the bubble, desktop / other the native title, the app neither;
 * the bubble 12 px over the tab, 8 px from the edges. The controller part (DOM, haptic, aria-describedby, the dropped
 * click) is checks/GEST-18.py.
 */
final class TabLongPressScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testNoCaseThrew(): void
    {
        foreach (self::results() as $case => $result) {
            self::assertFalse(is_array($result) && array_key_exists('threw', $result), $case . ': ' . json_encode($result));
        }
    }

    public function testTheContract(): void
    {
        self::assertSame([
            'HOLD_MS' => 500,
            'MOVE_TOLERANCE_PX' => 10,
            'HIDE_AFTER_MS' => 1500,
            'BUBBLE_GAP_PX' => 12,
            'EDGE_PX' => 8,
            'CLICK_WINDOW_MS' => 600,
            'BUBBLE_ID' => 'nav-tab-bubble',
        ], self::results()['constants']);
    }

    public function testHoldingHalfASecondShowsTheLabelAndTheReleaseDoesNotNavigate(): void
    {
        self::assertSame([
            'shownAt' => ['show', 500],
            'beforeHold' => 0,
            'drop' => true,
            'log' => [['show', 500], ['hide', 550]],
            'timersLeft' => 0,
            'state' => 'released',
        ], self::results()['held 550 ms, then released']);
    }

    public function testAShorterTapNavigates(): void
    {
        self::assertSame(['drop' => false, 'log' => [], 'timersLeft' => 0], self::results()['a tap of 150 ms']);
    }

    public function testMovingMoreThan10PixelsIsADrag(): void
    {
        self::assertSame(['drop' => false, 'log' => []], self::results()['moved 20 px, then held on']);
        self::assertSame(['shown' => true, 'drop' => true], self::results()['moved 10 px exactly (not more)']);
        self::assertSame(['log' => [['show', 500], ['hide', 600]], 'drop' => true], self::results()['the bubble shown, then moved 40 px']);
    }

    public function testTheBubbleGoesAfterOneAndAHalfSecondsAndTheReleaseStillDoesNotNavigate(): void
    {
        self::assertSame(['log' => [['show', 500], ['hide', 2000]], 'drop' => true, 'logAfterRelease' => 2], self::results()['held 3 s']);
    }

    public function testACancelledTouchDropsNothing(): void
    {
        self::assertSame([
            'holding' => [],
            'shown' => [['show', 500], ['hide', 600]],
            'shownState' => 'idle',
            'shownUp' => false,
        ], self::results()['cancelled while holding / after showing']);

        self::assertSame(['early' => 0, 'log' => [['show', 800]]], self::results()['two presses in a row']);
    }

    public function testWhichTabsAreCut(): void
    {
        self::assertSame([
            'step3NotCurrent' => true,
            'step3Current' => false,
            'step3AsString' => true,
            'step1Fits' => false,
            'step2Wider' => true,
            'step1CurrentWider' => true,
            'nothingKnown' => false,
            'noArgument' => false,
        ], self::results()['isTruncated']);
    }

    public function testWhoGetsTheBubbleAndWhoTheTitle(): void
    {
        self::assertSame([
            'androidTouch' => true,
            'iosTouch' => true,
            'iosPen' => true,
            'androidMouse' => false,
            'desktopTouch' => false,
            'nativeApp' => false,
            'nothing' => false,
            'forAnAndroidBrowser' => true,
            'forTheApp' => false,
        ], self::results()['longPressEnabled']);

        self::assertSame([
            'desktopCut' => true,
            'desktopFits' => false,
            'androidCut' => false,
            'iosCut' => false,
            'appCut' => false,
        ], self::results()['wantsTitle']);
    }

    public function testTheBubbleSitsTwelvePixelsOverTheTabAndEightFromTheEdges(): void
    {
        self::assertSame([
            'centred' => ['left' => 130, 'top' => 702],
            'leftEdge' => ['left' => 8, 'top' => 702],
            'rightEdge' => ['left' => 232, 'top' => 702],
            'widerThanTheScreen' => ['left' => 8, 'top' => 702],
        ], self::results()['bubblePosition']);
    }

    /**
     * Tab links - only they among the navigation's links - get no iOS link callout (the held finger is the label's); the
     * bubble never takes a touch and sits above the bars
     */
    public function testOnlyTabLinksLoseTheLinkCallout(): void
    {
        $withCallout = [];

        foreach (glob(__DIR__ . '/../assets/styles/_*.scss') ?: [] as $file) {
            foreach (ScssReader::rules((string) file_get_contents($file)) as $selector => $declarations) {
                if (preg_match('~-webkit-touch-callout\s*:\s*none~', $declarations) === 1) {
                    $withCallout[] = basename($file) . ' ' . $selector;
                }
            }
        }

        self::assertSame(['_nav-bars.scss .nav-tab'], $withCallout);

        $bubble = ScssReader::rules((string) file_get_contents(__DIR__ . '/../assets/styles/_nav-bars.scss'))['.nav-tab-bubble'] ?? '';
        self::assertStringContainsString('position: fixed', $bubble);
        self::assertStringContainsString('pointer-events: none', $bubble);
        self::assertStringContainsString('z-index: $z-tooltip', $bubble);
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

        $process = new Process([$node, __DIR__ . '/tab-long-press-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
