<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Tap the active tab (docs/features/navigation README §4.13 G3, requirement GEST-03): assets/tab_reselect.js decide()
 * run under node (tests/tab-reselect-harness.mjs), in the order of the requirement - the Menu panel open (the current
 * place's tab only closes it), another place, a deeper page (to the start page = the tab's href path, decision 58 D-5),
 * the start page scrolled (to the top, instant with reduced motion), the top of Home (the feed refreshes in place), the
 * top of Add (nothing - Add never reloads, also on its form with a chosen puzzle), the top of any other start page (a
 * visit). Inside the native app the link always navigates.
 */
final class TabReselectScriptTest extends TestCase
{
    private const array CLOSE_MENU = ['action' => 'close-menu'];
    private const array NAVIGATE = ['action' => 'navigate'];
    private const array SCROLL_SMOOTH = ['action' => 'scroll-top', 'behavior' => 'smooth'];
    private const array SCROLL_INSTANT = ['action' => 'scroll-top', 'behavior' => 'instant'];
    private const array REFRESH_FEED = ['action' => 'refresh-feed'];
    private const array NONE = ['action' => 'none'];

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
            'CLOSE_MENU' => 'close-menu',
            'NAVIGATE' => 'navigate',
            'SCROLL_TOP' => 'scroll-top',
            'REFRESH_FEED' => 'refresh-feed',
            'NONE' => 'none',
            'FEED_REFRESH_EVENT' => 'msp:feed-refresh',
        ], self::results()['constants']);
    }

    public function testWhileTheMenuIsOpenTheCurrentPlacesTabOnlyClosesIt(): void
    {
        self::assertSame(self::CLOSE_MENU, self::results()['Menu open, the current place\'s tab → close the Menu']);
        self::assertSame(self::CLOSE_MENU, self::results()['Menu open, the current place\'s tab on a deeper page → still only closes']);
        self::assertSame(self::NAVIGATE, self::results()['Menu open, another tab → navigate (a replace visit)']);
    }

    public function testAnotherPlaceNavigates(): void
    {
        self::assertSame(self::NAVIGATE, self::results()['another place\'s tab → navigate']);
        self::assertSame(self::NAVIGATE, self::results()['a page of no place (FAQ), Home → navigate']);
        self::assertSame(self::NAVIGATE, self::results()['no current place at all → navigate']);
    }

    public function testADeeperPageGoesToTheStartPageWhateverTheScroll(): void
    {
        self::assertSame(self::NAVIGATE, self::results()['puzzle page scrolled 800, Puzzles → navigate to the start page']);
        self::assertSame(self::NAVIGATE, self::results()['own statistics page, Profile → navigate to the profile']);
        self::assertSame(self::NAVIGATE, self::results()['the recap after adding a time, Add → navigate to the add form']);
    }

    public function testTheStartPageScrolledGoesToTheTop(): void
    {
        self::assertSame(self::SCROLL_SMOOTH, self::results()['Hub scrolled 800, Home → scroll to the top, smooth']);
        self::assertSame(self::SCROLL_INSTANT, self::results()['Hub scrolled 800 with reduced motion → scroll to the top, instant']);
        self::assertSame(self::SCROLL_SMOOTH, self::results()['a Puzzles section root scrolled (the section used last) → scroll to the top']);
        self::assertSame(self::SCROLL_SMOOTH, self::results()['the add form scrolled → scroll to the top']);
        self::assertSame(self::SCROLL_SMOOTH, self::results()['the add form with a chosen puzzle scrolled → scroll to the top']);
        self::assertSame(self::SCROLL_SMOOTH, self::results()['a start page with a trailing slash scrolled → scroll to the top']);
        self::assertSame(['smooth', 'instant'], self::results()['scrollBehavior']);
    }

    public function testAtTheTopHomeRefreshesTheFeedAndAddNeverReloads(): void
    {
        self::assertSame(self::REFRESH_FEED, self::results()['top of the Hub, Home → refresh the feed']);
        self::assertSame(self::REFRESH_FEED, self::results()['top of the guests\' homepage "/", Home → refresh the feed']);
        self::assertSame(self::REFRESH_FEED, self::results()['iOS rubber band above the top (-40) counts as the top']);
        self::assertSame(self::NONE, self::results()['top of the add form, Add → nothing (never reloads)']);
        self::assertSame(self::NONE, self::results()['top of the add form with a chosen puzzle, Add → nothing']);
        self::assertSame(self::NAVIGATE, self::results()['top of Community, Community → navigate (same URL)']);
        self::assertSame(self::NAVIGATE, self::results()['top of the own profile, Profile → navigate (same URL)']);
    }

    public function testTheStartPageIsTheTabsHrefPath(): void
    {
        self::assertSame([
            'same' => true,
            'trailingSlash' => true,
            'prefixIsNotAPage' => false,
            'deeperOfHome' => false,
            'addWithPuzzle' => true,
            'addLookalike' => false,
            'rootIsNoPrefix' => false,
            'noTabPath' => false,
            'noCurrentPath' => false,
        ], self::results()['isStartPage']);
        self::assertSame(['/en/hub', '/', '/', '/en', null, null, null], self::results()['normalizePath']);
    }

    public function testJunkFactsNavigate(): void
    {
        self::assertSame(self::NAVIGATE, self::results()['no facts at all → navigate']);
        self::assertSame(self::NAVIGATE, self::results()['null facts → navigate']);
        self::assertSame(self::NAVIGATE, self::results()['an empty tab place never counts as the current one']);
        self::assertSame(self::REFRESH_FEED, self::results()['a scroll position that is no number counts as the top']);
    }

    public function testInsideTheNativeAppTheLinkNavigates(): void
    {
        self::assertSame([self::NAVIGATE, self::NAVIGATE, self::NAVIGATE], self::results()['native app → navigate, whatever else']);
        self::assertSame(['app' => self::NAVIGATE, 'web' => self::SCROLL_SMOOTH], self::results()['decideFor reads the app flag of <html>']);
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

        $process = new Process([$node, __DIR__ . '/tab-reselect-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
