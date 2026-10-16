<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The desktop Menu's client-side rules (docs/features/navigation NAV-13, README §4.3 / D16), run under node by
 * tests/nav-layout-harness.mjs:
 * - assets/head/nav_layout.js sets html[data-nav-layout] (docked | rail) and html[data-nav-collapsed] (folded groups)
 *   from localStorage before first paint - anything else is ignored, nothing happens inside the app, a blocked storage
 *   throws nothing; it never writes data-nav (the click-instrumentation attribute) or a length (--nav-left is CSS only);
 * - assets/navigation/nav_layout.js keeps the choices (stored values, folded groups ≤ 80 characters), the desktop ☰'s
 *   state per tier (from 1280 px: aria-expanded = docked, the fold / dock tooltip; below: a dialog opener), where the
 *   column scrolls to (its own scrollTop: where it was, moved onto the current row when that is out of sight), focuses
 *   the docked column's first row for a link to `menu`, and docking asks for the Menu's numbers with the 5-minute TTL;
 * - assets/navigation/menu_links.js: a link to `menu` opens the panel on phones, the overlay below 1280 px and in the
 *   rail state, focuses the docked column; the ☰ from 1280 px docks / folds; the app and kept clicks go to the page;
 * - on the `menu` page itself the ☰ focuses the Menu's first row (nav_layout.js, NAV review Q5).
 */
final class NavLayoutScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testNoCaseThrew(): void
    {
        foreach (self::results() as $case => $facts) {
            self::assertIsArray($facts, $case);
            self::assertArrayNotHasKey('threw', $facts, $case . ': ' . (is_string($facts['threw'] ?? null) ? $facts['threw'] : ''));
        }
    }

    public function testTheHeadScriptAppliesOnlyValidChoicesBeforePaint(): void
    {
        $layout = ['data-nav-layout' => 'docked'];
        $defaultFolds = ['data-nav-collapsed' => 'puzzling library add account help about'];

        self::assertSame([
            // Nothing stored = the column's default folds (owner, 2026-10-16); 'empty' (a stored "") = all unfolded
            'nothing' => $defaultFolds,
            'docked' => $layout + $defaultFolds,
            'rail' => ['data-nav-layout' => 'rail'] + $defaultFolds,
            'junkLayout' => $defaultFolds,
            'upperCase' => $defaultFolds,
            'folded' => ['data-nav-collapsed' => 'roles help'],
            'foldedAndRail' => ['data-nav-layout' => 'rail', 'data-nav-collapsed' => 'about'],
            'onlySpaces' => [],
            'empty' => [],
            'injected' => [],
            'tooLong' => [],
            'longest' => ['data-nav-collapsed' => str_repeat('a', 80)],
            'insideTheApp' => [],
            'storageBlocked' => [],
            'text' => ['hasScriptEnd' => false, 'hasCommentOpen' => false, 'setsDataNav' => false],
        ], self::results()['head script']);

        // The page inlines its build (templates/_inline_scripts/nav_layout.js, InlineScriptsBuildTest): the same answers
        self::assertSame(self::results()['head script'], self::results()['head script build']);

        // Inlined by the head flags, after the other two (implementation plan §3.11); never a length
        $flags = (string) file_get_contents(__DIR__ . '/../templates/_head_flags.html.twig');
        self::assertStringContainsString("<script>{{ source('_inline_scripts/nav_layout.js')|raw }}</script>", $flags);
        self::assertGreaterThan((int) strpos($flags, 'standalone.js'), (int) strpos($flags, 'nav_layout.js'));
        self::assertStringNotContainsString('--nav-left', (string) file_get_contents(__DIR__ . '/../assets/head/nav_layout.js'));
    }

    public function testThePureRules(): void
    {
        $pure = self::results()['pure'];
        self::assertIsArray($pure);

        self::assertSame([
            'layout' => 'msp:nav-layout',
            'collapsed' => 'msp:nav-collapsed',
            'scroll' => 'msp:nav-col-scroll',
            'layoutAttribute' => 'data-nav-layout',
            'collapsedAttribute' => 'data-nav-collapsed',
            'event' => 'msp:nav-layout',
            'wide' => '(min-width: 1280px) and (min-height: 501px), (min-width: 1280px) and (pointer: fine)',
        ], $pure['keys']);
        self::assertSame(['docked', 'rail', null, null, null], $pure['storedLayout']);
        self::assertSame([
            'twice' => ['roles', 'help'],
            'spaces' => ['roles', 'about'],
            'junk' => [],
            'tooLong' => [],
            'notAString' => [],
        ], $pure['collapsedIds']);
        self::assertSame([
            'fold' => 'roles help',
            'foldAgain' => 'roles help',
            'unfold' => 'help',
            'unfoldLast' => '',
            'notAnId' => 'roles',
            // The oldest fold gave way - the head script refuses a value over 80 characters whole
            'overflow' => str_repeat('b', 20) . ' ' . str_repeat('c', 20) . ' ' . str_repeat('d', 17) . ' eeee',
        ], $pure['collapsedValue']);
        self::assertSame(['fromDocked' => 'rail', 'fromRail' => 'docked'], $pure['toggledLayout']);
        self::assertSame(['top' => 0, 'kept' => 300, 'below' => 720, 'above' => 0, 'clamped' => 900, 'junk' => 0], $pure['columnScrollTop']);
        self::assertSame([
            'wideDocked' => ['haspopup' => null, 'expanded' => 'true', 'controls' => 'site-menu', 'tooltip' => 'fold'],
            'wideRail' => ['haspopup' => null, 'expanded' => 'false', 'controls' => 'site-menu', 'tooltip' => 'dock'],
            'narrow' => ['haspopup' => 'dialog', 'expanded' => null, 'controls' => 'menu-layer', 'tooltip' => null],
            'insideTheApp' => ['haspopup' => 'dialog', 'expanded' => null, 'controls' => 'menu-layer', 'tooltip' => null],
            // NAV review Q5: on the `menu` page the ☰ is that page - it only points at the Menu
            'menuPageWide' => ['haspopup' => null, 'expanded' => null, 'controls' => 'site-menu', 'tooltip' => null],
            'menuPageNarrow' => ['haspopup' => null, 'expanded' => null, 'controls' => 'site-menu', 'tooltip' => null],
        ], $pure['menuButtonState']);
    }

    /**
     * NAV review Q5: on the `menu` page (the server marks both ☰ aria-current="page") a plain click on either ☰ focuses
     * the Menu's first row instead of loading the page again - from 1280 px too, where the ☰ elsewhere docks / folds
     * (the stored layout stays); a kept click (Ctrl, a new tab) goes to the page; elsewhere this listener leaves the ☰
     * to the Menu shell's
     */
    public function testTheMenuButtonOnTheMenuPageFocusesTheMenu(): void
    {
        self::assertSame([
            'attributes' => ['haspopup' => null, 'expanded' => null, 'controls' => 'site-menu', 'title' => null, 'current' => 'page'],
            'deskPrevented' => true,
            'deskFocused' => 'first-row',
            'layoutStored' => null,
            'phonePrevented' => true,
            'phoneFocused' => 'first-row',
            'ctrlClick' => false,
            'otherPrevented' => false,
            'notTheMenuPage' => false,
        ], self::results()['the ☰ on the menu page']);
    }

    /**
     * README §4.3: links to `menu` focus the docked column's first row, otherwise (rail, < 1280 px) open the overlay; on
     * phones the panel; the desktop ☰ from 1280 px switches docked ↔ rail
     */
    public function testWhatALinkToTheMenuDoesOnEachSurface(): void
    {
        self::assertSame([
            'phone' => 'phone',
            'landscapePhone' => 'phone',
            'compact' => 'overlay',
            'railBelow1280' => 'overlay',
            'rail' => 'rail',
            'docked' => 'docked',
        ], self::results()['menuSurface']);

        self::assertSame([
            'phone' => ['link' => 'panel', 'bar' => 'panel'],
            'overlay' => ['link' => 'overlay', 'bar' => 'overlay'],
            'rail' => ['link' => 'overlay', 'bar' => 'toggle'],
            'docked' => ['link' => 'focus', 'bar' => 'toggle'],
            'insideTheApp' => ['page', 'page', 'page', 'page'],
            'ctrlClickDocked' => 'page',
            'newTabRail' => 'page',
            'unknown' => 'page',
        ], self::results()['menuLinkAction']);
    }

    public function testFoldingAGroup(): void
    {
        self::assertSame([
            'after' => ['value' => 'roles', 'stored' => 'roles', 'attribute' => 'roles', 'roles' => 'false', 'help' => 'true'],
            'unfolded' => ['value' => '', 'stored' => '', 'attribute' => null, 'roles' => 'true'],
        ], self::results()['fold a group']);

        // turbo:before-render: the server renders every button expanded, the new body is marked before it shows
        self::assertSame(['roles' => 'true', 'help' => 'false'], self::results()['a new body follows the folded groups']);
    }

    public function testTheMenuButtonDocksAndFolds(): void
    {
        self::assertSame([
            'before' => ['expanded' => 'true', 'haspopup' => null, 'title' => 'Collapse the menu'],
            'folded' => 'rail',
            'rail' => ['expanded' => 'false', 'title' => 'Expand the menu'],
            'docked' => 'docked',
            'stored' => 'docked',
            'attribute' => 'docked',
            'events' => ['rail', 'docked'],
            'refreshes' => [['maxAge' => 300000]],
        ], self::results()['the ☰ folds and docks']);

        self::assertSame(['expanded' => 'false', 'haspopup' => 'dialog', 'controls' => 'menu-layer', 'title' => null], self::results()['the ☰ below 1280 px']);
    }

    /**
     * GEST-15 (e): critical CSS shows the column's slot only within WIDE_QUERY - below it the answer is "not docked"
     * without asking for a layout (turbo:render asks on every visit and Back)
     */
    public function testBelow1280TheColumnIsNotDockedWithoutALayout(): void
    {
        self::assertSame(
            ['narrowDocked' => false, 'measured' => 0, 'wideDocked' => true, 'wideHidden' => false],
            self::results()['docked without a layout below 1280 px'],
        );
    }

    public function testALinkToTheMenuWhereTheColumnIsDocked(): void
    {
        self::assertSame([
            'first' => true,
            'focused' => 'first-row',
            'scrollTop' => 0,
            'language' => true,
            'languageOpen' => true,
            'languageFocused' => 'SUMMARY',
            'collapsed' => null,
            'notDocked' => false,
        ], self::results()['a link to menu where the column is docked']);

        self::assertSame(['saved' => '240', 'railSaves' => null], self::results()['the column scroll is saved']);

        // Never scrollIntoView in the column (it would scroll the page too)
        self::assertStringNotContainsString('scrollIntoView', (string) file_get_contents(__DIR__ . '/../assets/navigation/nav_layout.js'));
        self::assertStringNotContainsString('scrollIntoView', (string) file_get_contents(__DIR__ . '/../templates/navigation/_menu_slot.html.twig'));
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
        self::assertIsString($node, 'node is required to execute the scripts - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/nav-layout-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $decoded;
    }
}
