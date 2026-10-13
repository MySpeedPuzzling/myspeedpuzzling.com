<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The phone top bar's hide on scroll (docs/features/navigation README §4.1, NAV-03, decision 20): assets/topbar_scroll.js
 * run under node (tests/topbar-scroll-harness.mjs) - hide after 8 px down past the bar's 52 px, back after 8 px up or
 * near the top, counting from each change of direction; never on desktop, under 480 px of height, inside the app or
 * while the search, the Menu, a menu of the bar or the focus is in it. A sticky sub-bar follows only while stuck.
 * And the installed-app head script (assets/head/standalone.js): html.is-standalone from display-mode standalone or
 * iOS's home screen flag, the test flag only under WebDriver, never inside the app.
 */
final class TopbarScrollScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testTheVerifysScrollSteps(): void
    {
        self::assertSame(['HIDE_AFTER_PX' => 8, 'SHOW_AFTER_PX' => 8, 'PHONE_TOPBAR_PX' => 52, 'SHORT_VIEWPORT_PX' => 480], self::results()['constants']);
        // window.scrollTo({top: 400}) hides it, 20 px up bring it back
        self::assertSame([false, true, false], self::results()['scroll to 400, then 20 up']);
    }

    public function testEightPixelsInOneDirection(): void
    {
        self::assertSame([false, false, false], self::results()['7 px down past the bar']);
        self::assertSame([false, false, true], self::results()['8 px down past the bar']);
        self::assertSame([false, false, false], self::results()['down to 52 never hides']);
        self::assertSame([false, true, false], self::results()['hidden, then back near the top']);
        self::assertSame([false, true, true], self::results()['hidden, 7 px up']);
        self::assertSame([false, true, false], self::results()['hidden, 8 px up']);
        self::assertSame([false, true, false, false], self::results()['shown again, then 7 px down']);
        self::assertSame([false, true, false, true], self::results()['shown again, then 8 px down']);
        self::assertSame([false, false, false, false, true], self::results()['four 2 px steps down past the bar']);
        self::assertSame([false, false], self::results()['negative scroll']);
        self::assertSame([false, true], self::results()['shadow once scrolled']);
    }

    public function testWhenTheBarStays(): void
    {
        self::assertSame([false, false, false], self::results()['may not hide']);
        self::assertSame([
            'phone' => true,
            'desktop' => false,
            'phone 479 px tall' => false,
            'phone 480 px tall' => true,
            'search open' => false,
            'Menu open' => false,
            'a menu of the bar open' => false,
            'focus inside the bar' => false,
            'no facts' => false,
        ], self::results()['mayHide']);
    }

    public function testStickySubBarsFollowOnlyWhileStuck(): void
    {
        self::assertSame([0, 0, 0, -52, -26.5, 0], self::results()['translateY']);
        self::assertSame([0, -52, 0, -30, 0, 0], self::results()['transformY']);
        self::assertSame([
            'at its sticky offset' => true,
            'half a pixel off' => true,
            'further down the page' => false,
            'stuck and translated by the hidden bar' => true,
            'not stuck, translated' => false,
            'no offset' => false,
        ], self::results()['stuckAt']);
        $followers = self::results()['followers'];
        self::assertIsArray($followers);
        self::assertContains('.ev-toolbar', $followers);
    }

    public function testTheInstalledAppFlag(): void
    {
        self::assertSame([
            'display-mode standalone' => ['is-standalone'],
            'iOS home screen' => ['is-standalone'],
            'a browser tab' => [],
            'the test flag under WebDriver' => ['is-standalone'],
            'the test flag without WebDriver' => [],
            'WebDriver without the flag' => [],
            'inside the app' => [],
            'matchMedia throws' => [],
            'storage blocked under WebDriver' => [],
        ], self::results()['standalone']);

        $script = (string) file_get_contents(__DIR__ . '/../assets/head/standalone.js');
        self::assertStringNotContainsStringIgnoringCase('</script', $script);
        self::assertStringNotContainsString('<!--', $script);
    }

    /**
     * NAV review F3: iOS reports scroll positions beyond the page's end while a fling bounces. Clamped to the real range,
     * the bounce back is no move - the bar stays hidden; a real scroll up still brings it back, the top bounce too is
     * clamped (at 0 the bar shows anyway), and a page whose range ends inside the bar's height never hides it.
     */
    public function testTheRubberBandAtTheEndOfAPageIsNoScroll(): void
    {
        self::assertSame([
            'clamped' => [false, true, true, true, true, true],
            'unclamped' => [false, true, true, true, false, false],
            'backUpForReal' => [false, true, true, true, true, false],
            'topBounce' => [false, true, false, false, false],
            'shortPage' => [false, false, false],
        ], self::results()['rubberBand']);
        self::assertSame([0, 600, 300, 0, 900, 0], self::results()['clampScroll']);
    }

    /**
     * NAV-07 + NAV review F1/Q1: Turbo keeps <html>'s attributes across a visit - the bar's state goes back to "shown"
     * (no shadow) before the next page renders, and comes off <html> when the controller disconnects (a page without the
     * bar, the sign-in pages' header, starts neutral). Nothing else on <html> is touched.
     */
    public function testTheBarsStateIsResetForTheNextPage(): void
    {
        self::assertSame([
            'hidden' => ['data-topbar' => 'shown', 'lang' => 'en'],
            'remove' => ['lang' => 'en'],
            'nothing' => ['lang' => 'en', 'data-topbar' => 'shown'],
        ], self::results()['reset']);
    }

    /**
     * NAV-11 (WCAG 3.2.1 On Focus): Tab passing through the top bar's search field must not open the search - the phone
     * view hides ?, Inbox and ☰ and takes a history entry. assets/navigation/focus_origin.js marks a focus moved by Tab
     * or Shift+Tab (the keydown's default action, the same task) and forgets it in the next task; Ctrl+K and typing are
     * not Tab (global_search_controller.js opens the search for them).
     */
    public function testAFocusMovedByTabIsKnownUntilTheNextTask(): void
    {
        self::assertSame([
            'install' => [true, false, [['keydown', true]]],
            'before' => false,
            'tab' => [true, false],
            'shiftTab' => [true, false],
            'ctrlTab' => [false, false],
            'altTab' => [false, false],
            'letter' => [false, false],
            'ctrlK' => [false, false],
        ], self::results()['focusOrigin']);
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

        $process = new Process([$node, __DIR__ . '/topbar-scroll-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
