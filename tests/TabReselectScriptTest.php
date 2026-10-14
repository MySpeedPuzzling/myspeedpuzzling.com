<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Tap the active tab (docs/features/navigation README §4.13 G3, requirement GEST-03): assets/tab_reselect.js decide()
 * run under node (tests/tab-reselect-harness.mjs), in the order of the requirement - a layer holding the history entry
 * (the Menu panel, the player card, a sheet, the search view: the current place's tab only closes it - GEST review Q1),
 * another place, a deeper page (to the start page = the tab's href path, decision 58 D-5, or the page the server marks
 * aria-current="page" - GEST review F4), the start page scrolled (to the top, instant with reduced motion), the top of
 * Home (the feed refreshes in place), the top of Add (nothing - Add never reloads, also on its form with a chosen puzzle
 * and on every page showing the solving-time form - GEST review F3), the top of any other start page (a visit). Inside
 * the native app the link always navigates. Links without data-nav-place (+ Add, the docked column's place rows) name
 * their place in data-nav (GEST review F1, Q3).
 */
final class TabReselectScriptTest extends TestCase
{
    private const array CLOSE_MENU = ['action' => 'close-layer', 'layer' => 'menu'];
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
            'CLOSE_LAYER' => 'close-layer',
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

    /** GEST review Q1: any layer holding the history entry - the tap would otherwise act on the page hidden behind it */
    public function testWhileAnyLayerIsOpenTheCurrentPlacesTabOnlyClosesIt(): void
    {
        self::assertSame(['action' => 'close-layer', 'layer' => 'player-card'], self::results()['the player card open on the scrolled Hub, Home → close the card (no glide behind it)']);
        self::assertSame(['action' => 'close-layer', 'layer' => 'modal'], self::results()['a sheet open at the top of the Hub, Home → close the sheet (no feed refresh behind it)']);
        self::assertSame(['action' => 'close-layer', 'layer' => 'search'], self::results()['the search view open on the add form, Add → close the search view']);
        self::assertSame(self::NAVIGATE, self::results()['the player card open, another tab → navigate (a replace visit)']);
        self::assertSame([self::SCROLL_SMOOTH, self::SCROLL_SMOOTH], self::results()['no layer (null or empty) → the page rules']);
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

    /** GEST review F4: the server's aria-current="page" is a start page whatever the tab's href (storage blocked) */
    public function testTheServersStartPageCountsWithoutTheBrowsersStorage(): void
    {
        self::assertSame(self::SCROLL_SMOOTH, self::results()['Marketplace scrolled, the Puzzles tab still on My library, the server says start page → scroll to the top']);
        self::assertSame(self::NAVIGATE, self::results()['the same without the server\'s word → navigate']);
    }

    /** GEST review F3: a page showing the solving-time form is Add's start page - its typed input survives a tap on Add */
    public function testEveryPageShowingTheTimeFormIsAddsStartPage(): void
    {
        self::assertSame(self::NONE, self::results()['the stopwatch\'s save form at the top, Add → nothing']);
        self::assertSame(self::SCROLL_SMOOTH, self::results()['the stopwatch\'s save form scrolled, Add → scroll to the top']);
        self::assertSame(self::NAVIGATE, self::results()['the stopwatch\'s save form without the form fact, Add → navigate']);
        self::assertSame(self::NONE, self::results()['the edit form at the top, Add → nothing']);
        self::assertSame(self::NAVIGATE, self::results()['the form fact counts for Add only']);
    }

    /** GEST review F1 + Q3: + Add (topbar/add/add) and the docked column's place rows (menu/<group>/<place>[_place]) */
    public function testALinkWithoutDataNavPlaceNamesItsPlaceInDataNav(): void
    {
        self::assertSame(['add', 'home', 'puzzles', 'community', 'profile', null, null, null, null, null, null], self::results()['placeOfNav']);
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
            'serverSays' => true,
            'formShown' => true,
            'formShownOtherPlace' => false,
            'truthyIsNotTrue' => false,
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
