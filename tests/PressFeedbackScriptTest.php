<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Pressed feedback per platform (docs/features/navigation README §4.13 G11, requirement GEST-11): assets/press_feedback.js
 * run under node (tests/press-feedback-harness.mjs) - only Android ripples, never under reduced motion; the delay by
 * context (the fixed bars at once, content after 150 ms, cards never); cancelled by a move over 10 px or pointercancel;
 * the fade starts at the release but never before 225 ms of ripple; the circle covers the target from the touch point
 * and starts at 20 % of its larger side. The browser part (≤ 30 ms on a tab, ≥ 150 ms in content, removed ≤ 700 ms after
 * the release, never on iOS / desktop / reduced motion) is checks/GEST-11.py, measured in the page.
 */
final class PressFeedbackScriptTest extends TestCase
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
            'COVER_MS' => 450,
            'HOLD_MIN_MS' => 225,
            'FADE_MS' => 150,
            'CONTENT_DELAY_MS' => 150,
            'MOVE_TOLERANCE_PX' => 10,
            'START_FRACTION' => 0.2,
            'PRESS_SELECTOR' => '[data-press]',
            'HOST_CLASS' => 'msp-ripple-host',
            'RIPPLE_CLASS' => 'msp-ripple',
            'FADING_CLASS' => 'is-fading',
            'SURFACE_SELECTOR' => '.nav-tab-icon, .stopwatch-pill-body',
            'FADE_ANIMATION' => 'msp-ripple-fade',
            'SURFACE_ATTRIBUTE' => 'data-ripple-surface',
        ], self::results()['constants']);
    }

    /** The script's timings are the stylesheet's (the cover and the fade are CSS animations) */
    public function testTheStylesheetHasTheSameTimings(): void
    {
        $scss = (string) file_get_contents(__DIR__ . '/../assets/styles/_press-feedback.scss');

        self::assertStringContainsString('animation: msp-ripple-cover 450ms cubic-bezier(.2, 0, 0, 1) forwards;', $scss);
        self::assertStringContainsString('animation: msp-ripple-fade 150ms linear forwards;', $scss);
        // The element's ink (shared with GEST-04's layer), the 10 % coral by default; a dark ink on the coral surfaces,
        // where the coral layer would vanish (GEST review F2)
        self::assertStringContainsString('background: var(--nav-press-ink, var(--nav-pressed-pill', $scss);
        // ... set once, next to GEST-04's layer (custom properties inherit: the ripple in the tile's pill reads the tab's)
        self::assertMatchesRegularExpression('~\.nav-tab--add\[data-press\],\s*\.site-header-add\[data-press\],\s*\.menu-guest-btn--primary\[data-press\] \{\s*--nav-press-ink: rgba\(0, 0, 0, \.16\);~', (string) file_get_contents(__DIR__ . '/../assets/styles/_press-states.scss'));
        self::assertStringNotContainsString('--nav-press-ink:', $scss);
        self::assertStringContainsString('transform: scale(var(--msp-ripple-from', $scss);
        // Under the target's icon and label, as GEST-04's layer (the script isolates the target meanwhile)
        self::assertStringContainsString('z-index: -1;', $scss);
    }

    public function testOnlyAndroidRipplesAndNeverUnderReducedMotion(): void
    {
        self::assertSame([
            'android' => true,
            'androidReduced' => false,
            'ios' => false,
            'desktop' => false,
            'nothing' => false,
        ], self::results()['rippleEnabled']);
    }

    public function testTheDelayByContext(): void
    {
        self::assertSame(['fixed' => 0, 'content' => 150, 'layer' => null, 'none' => null, 'junk' => 150], self::results()['delayFor']);

        self::assertSame(['log' => [['start', 0], ['fade', 225]], 'timersLeft' => 0], self::results()['fixed: quick tap']);
        self::assertSame([['start', 0], ['fade', 600]], self::results()['fixed: held 600 ms']);
        self::assertSame(['before' => 0, 'log' => [['start', 150], ['fade', 400]]], self::results()['content: held 400 ms']);
        // A tap shorter than the delay: a short ripple from the release, as Android draws it
        self::assertSame([['start', 60], ['fade', 285]], self::results()['content: tap of 60 ms']);
    }

    public function testCancelByDistance(): void
    {
        self::assertSame(['still' => false, 'tenExactly' => false, 'eleven' => true, 'twentyDown' => true], self::results()['movedTooFar']);

        self::assertSame(['log' => [], 'timersLeft' => 0], self::results()['content: moved 20 px at 50 ms']);
        self::assertSame([['start', 0], ['cancel', 100]], self::results()['fixed: moved 20 px at 100 ms']);
        self::assertSame([['start', 0], ['fade', 300]], self::results()['fixed: moved 8 px']);
        self::assertSame(['early' => [], 'late' => [['start', 0], ['cancel', 100]], 'earlyTimers' => 0], self::results()['pointercancel']);
    }

    public function testTheFadeStart(): void
    {
        self::assertSame(['quickTap' => 225, 'held' => 600, 'releasedAtTheStart' => 375], self::results()['fadeStartAt']);
    }

    public function testTheCircleCoversTheTargetFromTheTouchPoint(): void
    {
        self::assertSame([
            // The tab's 56 × 32 pill, touched in its middle: radius to a corner 32.25 px; 20 % of 56 = 11.2 px of 64.5
            'tabPillCentre' => ['size' => 64.5, 'left' => -4.25, 'top' => -16.25, 'startScale' => 0.174],
            'rowCorner' => ['size' => 607.63, 'left' => -303.82, 'top' => -303.82, 'startScale' => 0.099],
            'rowMiddle' => ['size' => 303.82, 'left' => -1.91, 'top' => -127.91, 'startScale' => 0.197],
            'empty' => ['size' => 2, 'left' => -1, 'top' => -1, 'startScale' => 0],
        ], self::results()['rippleGeometry']);
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

        $process = new Process([$node, __DIR__ . '/press-feedback-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
