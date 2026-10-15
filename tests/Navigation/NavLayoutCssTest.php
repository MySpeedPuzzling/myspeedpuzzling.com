<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Navigation\MenuGroup;
use SpeedPuzzling\Web\Navigation\MenuGroupView;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The navigation layout variables and the fixed elements that follow them (docs/features/navigation F-03, README §4.2,
 * §4.3, §5; implementation plan decisions 17, 18, 19, review F9):
 * - closed overlays are `display: none`, never only transparent (Safari 26 samples them for its toolbar tint);
 * - --nav-left, --topbar-h, --topbar-shift and --bottom-nav-height are set in critical CSS only - no script, no
 *   template attribute, no feature stylesheet writes them;
 * - every page-wide fixed bar and every fixed bottom element offsets by --nav-left / --bottom-nav-height;
 * - sub-bars anchored at the static `var(--header-height)` follow the live top bar through `translate`
 *   (nav-follow-topbar), never by transitioning or animating `top`; <html>'s scroll padding reserves the bars, so
 *   scroll margins add only their own gap;
 * - base.html.twig keeps the head order (§3.11) and the web-only nav_chrome / nav_layers blocks, which every bar-free
 *   page empties together with header and footer.
 * Later items append rows to the providers (the NAV-03 search view, HELP-01b, CARD-01a, ...; MENU-02b added #menu-layer).
 */
final class NavLayoutCssTest extends TestCase
{
    private const string CRITICAL = 'templates/navigation/_critical_css.html.twig';

    /**
     * Sub-bars anchored at var(--header-height) that still transition `top` for a reason of their own (not the top bar)
     *
     * @var array<string, string>
     */
    private const array TOP_TRANSITION_EXCEPTIONS = [
        // NAV-03 decision: kept - the slide follows the events toolbar (--ev-toolbar-height, events_page_controller.js), not
        // the top bar, which it follows through the translate while stuck ($sticky); a layout shift of the page's own
        // content on /en/events phones, never of the chrome (NAV-07 counts the chrome)
        'assets/styles/_event-parts.scss .ev-month-header' => 'pre-existing: slides up under the events toolbar when --ev-toolbar-height changes (events_page_controller.js); follows the top bar through the translate while stuck',
    ];

    /** The lengths only critical CSS may set (NAV-03: --header-height too - the top bar's static height, nothing measures it) */
    private const array CSS_ONLY_LENGTHS = ['--header-height', '--nav-left', '--topbar-h', '--topbar-shift', '--bottom-nav-height'];

    /** The top bar's script: which sticky sub-bars it marks stuck (NAV-03) */
    private const string TOPBAR_SCROLL = 'assets/topbar_scroll.js';

    /**
     * @return iterable<string, array{string, string}> file => full selector of a closed overlay
     */
    public static function closedOverlays(): iterable
    {
        // NAV-03: the top bar's search - its panel, its × and the 🔍 that stands in for the field - and the scrim behind it
        yield 'search panel, critical' => [self::CRITICAL, '.site-search-panel'];
        yield 'close search, critical' => [self::CRITICAL, '.site-search-close'];
        yield 'search icon, critical' => [self::CRITICAL, '.site-header .site-search-icon'];
        yield 'global search overlay, critical' => [self::CRITICAL, '.global-search-overlay'];
        yield 'global search overlay' => ['assets/styles/app.scss', '.global-search-overlay'];
        // CARD-01a: the card's layer is a <dialog> (assets/player_card_layer.js) - display: none while not [open]
        yield 'player card layer' => ['assets/styles/players/_card.scss', '.player-card-layer:not([open])'];
        yield 'events ⋯ menu layer' => ['assets/styles/_events-organizer.scss', '.ev-menu-layer[hidden]'];
        yield 'events country sheet' => ['assets/styles/_events-page.scss', '.ev-sheet'];
        yield 'XP recap sheet' => ['assets/styles/_xp-recap.scss', '.recap-sheet[hidden]'];
        yield 'selection bar' => ['assets/styles/_collection-selection.scss', '.collection-selection-bar[hidden]'];
        // HELP-01b: the Help sheet's own scrim (dynamic_modal_controller.js own-scrim path, implementation plan §7)
        yield 'dynamic modal scrim' => ['assets/styles/_dynamic-modal.scss', '.dynamic-modal-scrim[hidden]'];
        // MENU-02b: the Menu shell (a <dialog>: display: none while not [open], also while the stylesheet loads)
        yield 'Menu shell #menu-layer' => ['assets/styles/_nav-menu.scss', '.menu-layer:not([open])'];
        // NAV-13: the docked column's slot wherever the Menu is not docked; the rail's "Sign in" in the phone tab bar
        yield 'Menu slot, critical' => [self::CRITICAL, '.menu-slot'];
        yield 'rail label, critical' => [self::CRITICAL, '.nav-rail-label'];
    }

    #[DataProvider('closedOverlays')]
    public function testClosedOverlaysAreDisplayNone(string $file, string $selector): void
    {
        $declarations = $this->declarationsOf($file, $selector);

        self::assertMatchesRegularExpression('~(^|[;\s])display\s*:\s*none\b~', $declarations, $file . ' ' . $selector . ': the closed state is not display: none');
        // Transparent instead of gone is exactly what README §4.2 forbids
        self::assertDoesNotMatchRegularExpression('~(^|[;\s])opacity\s*:\s*0\s*(;|$)~', $declarations, $file . ' ' . $selector . ': closed by opacity');
    }

    public function testOpenGlobalSearchIsShownByDisplay(): void
    {
        // NAV-03: <html data-search-open> opens the top bar's search
        self::assertMatchesRegularExpression('~display\s*:\s*block~', $this->declarationsOf(self::CRITICAL, 'html[data-search-open] .site-search-panel'));
        self::assertMatchesRegularExpression('~display\s*:\s*block~', $this->declarationsOf(self::CRITICAL, 'html[data-search-open] .global-search-overlay'));
        self::assertMatchesRegularExpression('~display\s*:\s*block~', $this->declarationsOf('assets/styles/app.scss', 'html[data-search-open] .global-search-overlay'));
    }

    /**
     * NAV review F2: the scrim and the page's scroll lock belong to the phone search view only. On desktop the search is
     * a dropdown under the field - focusing the always-visible field neither dims the page nor stops it scrolling, and
     * no removed scrollbar moves the page sideways. The dropdown panel itself opens at every width.
     */
    public function testTheDesktopSearchNeitherDimsNorLocksThePage(): void
    {
        $phone = '@media (max-width:767.98px),(max-height:500.98px) and (pointer:coarse){';
        $lines = array_values(array_filter(explode("\n", $this->criticalRules()), static fn (string $line): bool => str_contains($line, 'html[data-search-open] .global-search-overlay')));

        self::assertCount(1, $lines, 'One critical rule shows the scrim');
        self::assertStringStartsWith($phone, $lines[0], 'The critical CSS shows the scrim on phones only');
        self::assertStringContainsString("\nhtml[data-search-open] .site-search-panel{display:block}\n", "\n" . $this->criticalRules() . "\n", 'The panel opens at every width');

        $app = $this->withoutComments((string) file_get_contents($this->root() . '/assets/styles/app.scss'));
        self::assertSame(1, substr_count($app, 'html[data-search-open]'), 'app.scss styles the open search in one place');
        self::assertMatchesRegularExpression('~@include nav-phone\s*\{\s*html\[data-search-open\]\s*\{\s*overflow\s*:\s*hidden\s*;~', $app, 'The scroll lock and the scrim of the open search are phones only');

        // No other stylesheet locks the page for the open search
        foreach ($this->files('assets/styles', ['scss']) as $path => $code) {
            if ($path !== 'assets/styles/app.scss') {
                self::assertDoesNotMatchRegularExpression('~\[data-search-open\][^{]*\{[^}]*overflow\s*:\s*hidden~', $this->withoutComments($code), $path);
            }
        }
    }

    public function testNoScriptOrTemplateWritesTheLayoutLengths(): void
    {
        $names = implode('|', array_map(static fn (string $name): string => preg_quote($name, '~'), self::CSS_ONLY_LENGTHS));
        $violations = [];

        foreach ($this->files('assets', ['js', 'mjs']) as $path => $code) {
            // setProperty('--nav-left', …), style['--nav-left'] = …, cssText = '--nav-left: …'
            if (preg_match('~setProperty\(\s*[\'"`](' . $names . ')[\'"`]|style\[\s*[\'"`](' . $names . ')|(' . $names . ')\s*:~', $code, $match) === 1) {
                $violations[] = $path . ': ' . $match[0];
            }
        }

        foreach ($this->files('templates', ['twig']) as $path => $code) {
            if ($path !== self::CRITICAL && preg_match('~(' . $names . ')\s*:~', $code, $match) === 1) {
                $violations[] = $path . ': ' . $match[0];
            }
        }

        // Feature stylesheets read them (var(…)), the critical partial alone sets them
        foreach ($this->files('assets/styles', ['scss', 'css']) as $path => $code) {
            if (preg_match('~(?<![\w-])(' . $names . ')\s*:~', $this->withoutComments($code), $match) === 1) {
                $violations[] = $path . ': ' . $match[0];
            }
        }

        self::assertSame([], $violations, 'Only templates/navigation/_critical_css.html.twig sets these lengths (decision 17)');
    }

    /**
     * @return iterable<string, array{string, string, list<string>}> file, full selector, offsets it must use
     */
    public static function fixedElements(): iterable
    {
        // README §4.3: the page-wide fixed bars start at / centre by --nav-left
        yield 'compact bar' => ['assets/styles/_compact-bar.scss', '.compact-bar', ['left']];
        yield 'selection bar' => ['assets/styles/_collection-selection.scss', '.collection-selection-bar', ['bottom', 'left']];
        yield 'multiscan sheet' => ['assets/styles/_multiscan.scss', '.multiscan-sheet', ['bottom', 'left']];
        yield 'seating toasts' => ['assets/styles/_seating.scss', '.seating-toast-region', ['bottom', 'left']];
        yield 'homepage locale banner' => ['assets/styles/_homepage.scss', '.homepage-locale-banner', ['left']];
        yield 'XP recap sheet' => ['assets/styles/_xp-recap.scss', '.recap-sheet', ['bottom', 'left']];
        // README §4.2: every fixed bottom element sits above the phone bottom bar
        yield 'comparison launcher' => ['assets/styles/_comparison-entry.scss', '.comparison-launcher', ['bottom']];
        yield 'multiscan action bar' => ['assets/styles/_multiscan.scss', '.multiscan-action-bar', ['bottom']];
        yield 'multiscan sheet backdrop' => ['assets/styles/_multiscan.scss', '.multiscan-sheet-backdrop', ['bottom']];
        // GEST-02: every dynamic-modal sheet (the achievement sheet, the result detail, the Help sheet) - was the
        // achievement sheet's own rule in _achievement-medal.scss
        yield 'bottom sheet (dynamic modal)' => ['assets/styles/_dynamic-modal.scss', '#dynamic-modal-container.bottom-sheet-open .modal-dialog', ['bottom']];
        yield 'bottom sheet, near full screen' => ['assets/styles/_dynamic-modal.scss', '#dynamic-modal-container.bottom-sheet-open .modal-dialog.modal-sheet-sm-down', ['bottom']];
        yield 'modal-sheet-sm-down' => ['assets/styles/_dynamic-modal.scss', '#dynamic-modal-container .modal-dialog.modal-sheet-sm-down', ['bottom']];
        // Every Bootstrap modal box, the dynamic modal container included (testEveryModalBoxEndsAboveTheBottomBar)
        yield 'every modal incl. the dynamic modal container (decision 18, review F4)' => ['assets/styles/_dynamic-modal.scss', '.modal', ['bottom']];
        yield 'modal backdrop (decision 18)' => ['assets/styles/_dynamic-modal.scss', '.modal-backdrop', ['bottom']];
        yield 'the Help sheet\'s own scrim (decision 18, HELP-01b)' => ['assets/styles/_dynamic-modal.scss', '.dynamic-modal-scrim', ['bottom']];
        yield 'player card layer' => ['assets/styles/players/_card.scss', '.player-card-layer', ['bottom']];
        yield 'player card sheet' => ['assets/styles/players/_card.scss', '.is-sheet .player-card-box', ['bottom']];
        yield 'events ⋯ menu layer' => ['assets/styles/_events-organizer.scss', '.ev-menu-layer', ['bottom']];
        yield 'events ⋯ menu sheet' => ['assets/styles/_events-organizer.scss', '.is-sheet .ev-menu-dialog', ['bottom']];
        yield 'events country sheet' => ['assets/styles/_events-page.scss', '.ev-sheet', ['bottom']];
        // MENU-02b: the phone panel ends above the bottom bar (README §4.12) - the bar stays usable under it
        yield 'Menu shell #menu-layer' => ['assets/styles/_nav-menu.scss', '.menu-layer', ['bottom']];
        // NAV-03: the phone search view's results panel lies under the top bar down to the bottom bar
        yield 'top bar search panel' => ['assets/styles/_nav-topbar.scss', '.site-search-panel', ['bottom']];
        yield 'toasts' => ['assets/styles/app.scss', '.toast-container', ['bottom']];
        yield 'last-piece-reveal confetti' => ['assets/styles/_last-piece-reveal.scss', '.lpr-confetti', ['bottom']];
        yield 'scroll to top' => ['assets/styles/components/_buttons.scss', '.btn-scroll-top', ['bottom']];
        yield 'participants sheet bulk bar' => ['assets/styles/participants_sheet/_people.scss', '.sheet-bulk-bar', ['bottom']];
        yield 'participants sheet toasts' => ['assets/styles/participants_sheet/_core.scss', '.sheet-toasts', ['bottom']];
    }

    /**
     * @param list<string> $offsets
     */
    #[DataProvider('fixedElements')]
    public function testFixedElementsUseTheNavOffsets(string $file, string $selector, array $offsets): void
    {
        $declarations = $this->declarationsOf($file, $selector);

        if (in_array('bottom', $offsets, true)) {
            self::assertMatchesRegularExpression('~--bottom-nav-height|@include\s+nav-bottom\b~', $declarations, $file . ' ' . $selector . ' does not sit above the bottom bar');
        }

        if (in_array('left', $offsets, true)) {
            self::assertMatchesRegularExpression('~--nav-left|@include\s+nav-center-x\b~', $declarations, $file . ' ' . $selector . ' ignores the desktop column/rail');
        }
    }

    /**
     * Decision 18 for every modal, not only the dynamic one (phase F review F4): the `.modal` rule ends every Bootstrap
     * modal box at --bottom-nav-height - the dynamic modal container is one (class "modal"), and no stylesheet sizes a
     * modal box again without the bar. A full-height transparent .modal over the bar would take the bar's taps.
     */
    public function testEveryModalBoxEndsAboveTheBottomBar(): void
    {
        $base = (string) file_get_contents($this->root() . '/templates/base.html.twig');
        self::assertSame(1, preg_match('~<div\b[^>]*\bid="dynamic-modal-container"[^>]*>~s', $base, $container));
        self::assertMatchesRegularExpression('~\bclass="([^"]*\s)?modal(\s[^"]*)?"~', $container[0], 'The dynamic modal container is a .modal - the rule reaches it');

        $resized = [];

        foreach ($this->files('assets/styles', ['scss']) as $path => $code) {
            foreach ($this->rules($code) as $selector => $declarations) {
                $parts = preg_split('/\s*[\s>+~]\s*(?![^(]*\))/', trim($selector));
                $last = is_array($parts) ? (string) end($parts) : $selector;

                if (preg_match('~(\.modal(?![\w-])|#dynamic-modal-container)~', $last) !== 1) {
                    continue;
                }

                if (
                    preg_match('~(?<![\w-])(height|max-height|bottom|inset)\s*:~', $declarations) === 1
                    && preg_match('~--bottom-nav-height|@include\s+nav-bottom\b~', $declarations) !== 1
                ) {
                    $resized[] = $path . ' ' . $selector;
                }
            }
        }

        self::assertSame([], $resized, 'A modal box sized without --bottom-nav-height would cover the bottom bar again');
    }

    /**
     * While a focused text field sends the bottom bar away, every scrim reaches the bottom edge (QA-04 R01): the Help
     * sheet focuses its message on phones, and the strip the bar left must be dimmed scrim, never live page that takes
     * taps. The same field selector hides the bar and grows the scrims; only the scrims grow, never a `.modal` box.
     */
    public function testScrimsReachTheBottomEdgeWhileTheBarIsAway(): void
    {
        $code = (string) file_get_contents($this->root() . '/assets/styles/_nav-bars.scss');
        $rules = $this->rules($code);
        $field = 'html:has(#{$nav-keyboard-field})';

        $hidesTheBar = array_filter(array_keys($rules), static fn (string $selector): bool => str_contains($selector, $field) && str_contains($selector, '.bottom-nav'));
        self::assertNotSame([], $hidesTheBar, 'The bar hides for the same field selector that grows the scrims');

        $declarations = static function (string $part) use ($rules, $field): string {
            $found = '';

            foreach ($rules as $selector => $own) {
                if (str_contains($selector, $field) && preg_match('~(?<![\w-])' . preg_quote($part, '~') . '(?![\w-])~', $selector) === 1) {
                    $found .= $own;
                }
            }

            return $found;
        };

        self::assertMatchesRegularExpression('~(?<![\w-])height\s*:\s*100vh~', $declarations('.modal-backdrop'), 'Bootstrap\'s backdrop reaches the bottom edge');
        self::assertMatchesRegularExpression('~(?<![\w-])bottom\s*:\s*0\b~', $declarations('.dynamic-modal-scrim'), 'The Help sheet\'s own scrim reaches the bottom edge');
        self::assertMatchesRegularExpression('~(?<![\w-])bottom\s*:\s*0\b~', $declarations('.menu-layer'), 'The Menu panel reaches the bottom edge');
        self::assertDoesNotMatchRegularExpression('~(?<![\w-])(height|bottom|inset)\s*:~', $declarations('.modal'), 'No dialog box moves under the finger');
    }

    /**
     * The Help sheet's own scrim (HELP-01b, implementation plan §7, F-03 z-index scale): at the backdrop's layer, under
     * the .modal that takes the taps outside the dialog; the sent sheet (release()) lets taps through to the page except
     * on its dialog
     */
    public function testTheHelpSheetScrimSitsInTheZIndexScale(): void
    {
        $tokens = (string) file_get_contents($this->root() . '/assets/styles/_nav-tokens.scss');
        self::assertMatchesRegularExpression('~^\$z-modal-backdrop:\s*1050;~m', $tokens);
        self::assertMatchesRegularExpression('~^\$z-modal:\s*1055;~m', $tokens);

        self::assertMatchesRegularExpression('~(^|[;\s])z-index\s*:\s*\$z-modal-backdrop\s*;~', $this->declarationsOf('assets/styles/_dynamic-modal.scss', '.dynamic-modal-scrim'));
        self::assertMatchesRegularExpression('~pointer-events\s*:\s*none~', $this->declarationsOf('assets/styles/_dynamic-modal.scss', '#dynamic-modal-container.is-released'));
        self::assertMatchesRegularExpression('~pointer-events\s*:\s*auto~', $this->declarationsOf('assets/styles/_dynamic-modal.scss', '#dynamic-modal-container.is-released .modal-content'));
    }

    public function testSubBarsFollowTheTopBarWithoutAnimatingTop(): void
    {
        $anchored = [];
        $problems = [];
        $keyframes = $this->keyframesSettingTop();

        foreach ($this->files('assets/styles', ['scss']) as $path => $code) {
            foreach ($this->rules($code) as $selector => $declarations) {
                if (preg_match('~(?<![\w-])top\s*:[^;]*(var\(--header-height|\$ev-sticky-top)~', $declarations) !== 1) {
                    continue;
                }

                $anchored[] = $path . ' ' . $selector;

                if (preg_match('~@include\s+nav-follow-topbar\b~', $declarations) !== 1) {
                    $problems[] = $path . ' ' . $selector . ': anchored at var(--header-height) without @include nav-follow-topbar';
                }

                if (!array_key_exists($path . ' ' . $selector, self::TOP_TRANSITION_EXCEPTIONS) && preg_match('~transition(-property)?\s*:[^;]*(?<![\w-])(top|all)(?![\w-])~', $declarations, $match) === 1) {
                    $problems[] = $path . ' ' . $selector . ': transitions top (' . $match[0] . ')';
                }

                if (preg_match_all('~animation(-name)?\s*:\s*([\w-]+)~', $declarations, $animations) > 0) {
                    foreach ($animations[2] as $animation) {
                        if (in_array($animation, $keyframes, true)) {
                            $problems[] = $path . ' ' . $selector . ': animates top (@keyframes ' . $animation . ')';
                        }
                    }
                }
            }
        }

        // The list of the plan (§4 F-03) - a new sub-bar under the top bar joins it by itself. 13 since NAV-03: the page
        // search's old dropdown (.global-search, fixed under the header) became the top bar's own field
        self::assertGreaterThanOrEqual(13, count($anchored), implode("\n", $anchored));
        self::assertSame([], $problems);

        foreach (array_keys(self::TOP_TRANSITION_EXCEPTIONS) as $exception) {
            self::assertContains($exception, $anchored, 'Stale exception: ' . $exception);
        }
    }

    public function testTheFollowMixinTranslatesAndNeverAnimatesTop(): void
    {
        $mixin = $this->mixin('nav-follow-topbar');

        self::assertStringContainsString('translate: 0 calc(-1 * var(--topbar-shift', $mixin);
        self::assertDoesNotMatchRegularExpression('~(?<![\w-])top\s*:~', $mixin);
        self::assertStringContainsString('prefers-reduced-motion', $mixin);
        // NAV-03: a sticky sub-bar translates only while the top bar's script marks it stuck
        self::assertStringContainsString('&[data-topbar-stuck]', $mixin);
    }

    /**
     * NAV-03 (F-03 open point): translating a sticky sub-bar would also move it while it sits further down the page, not
     * stuck. Every sticky follower uses the mixin's `$sticky: true` (it translates only while [data-topbar-stuck]), every
     * fixed one the plain mixin, and the script marking them (STICKY_FOLLOWERS) lists exactly the sticky ones.
     */
    public function testStickySubBarsFollowTheTopBarOnlyWhileStuck(): void
    {
        $sticky = [];
        $problems = [];

        foreach ($this->files('assets/styles', ['scss']) as $path => $code) {
            foreach ($this->rules($code) as $selector => $declarations) {
                if (preg_match('~@include\s+nav-follow-topbar\b~', $declarations) !== 1) {
                    continue;
                }

                $isSticky = preg_match('~(?<![\w-])position\s*:\s*sticky\b~', $declarations) === 1;
                $usesSticky = preg_match('~@include\s+nav-follow-topbar\b[^;]*\$sticky\s*:\s*true~', $declarations) === 1;

                if ($isSticky !== $usesSticky) {
                    $problems[] = $path . ' ' . $selector . ($isSticky ? ': sticky without $sticky: true' : ': $sticky: true on a non-sticky element');
                }

                if ($usesSticky) {
                    $parts = preg_split('/\s*[\s>+~]\s*/', trim($selector));
                    $last = is_array($parts) ? (string) end($parts) : $selector;
                    self::assertSame(1, preg_match('~^\.[\w-]+~', $last, $class), $selector);
                    $sticky[$class[0]] = true;
                }
            }
        }

        self::assertSame([], $problems);

        $script = (string) file_get_contents($this->root() . '/' . self::TOPBAR_SCROLL);
        self::assertSame(1, preg_match('~export const STICKY_FOLLOWERS = \[(.*?)\];~s', $script, $list), 'STICKY_FOLLOWERS missing');
        preg_match_all("~'([^']+)'~", $list[1], $listed);

        $expected = array_keys($sticky);
        sort($expected);
        $actual = $listed[1];
        sort($actual);

        self::assertGreaterThanOrEqual(10, count($expected));
        self::assertSame($expected, $actual, 'assets/topbar_scroll.js STICKY_FOLLOWERS must list every sticky sub-bar that follows the top bar');
    }

    /**
     * Decision 20: the top bar is sticky, transformed only while hidden and never gets a backdrop-filter - its search
     * panel is position: fixed and either would trap it
     */
    public function testTheTopBarIsNeverTheContainingBlockOfItsSearchPanel(): void
    {
        $offenders = [];
        $sources = ['critical' => $this->criticalRules()] + $this->files('assets/styles', ['scss']);

        foreach ($sources as $path => $code) {
            foreach ($this->rules($code) as $selector => $declarations) {
                if (preg_match('~(^|[\s,>])(header|\.site-header)(\s|$|:|\[)~', $selector) !== 1 || str_contains($selector, '.site-header-')) {
                    continue;
                }

                if (preg_match('~backdrop-filter\s*:(?!\s*none)~', $declarations) === 1 || preg_match('~(?<![\w-])(filter|will-change|contain|perspective)\s*:(?!\s*none)~', $declarations) === 1) {
                    $offenders[] = $path . ' ' . $selector . ' {' . $declarations . '}';
                }

                if (preg_match('~(?<![\w-])transform\s*:(?!\s*none)~', $declarations) === 1 && !str_contains($selector, '[data-topbar="hidden"]') && !str_contains($selector, '[data-topbar=hidden]')) {
                    $offenders[] = $path . ' ' . $selector . ': transformed while shown';
                }
            }
        }

        self::assertSame([], $offenders);
        self::assertStringContainsString('.site-header{position:sticky;top:0;', $this->criticalRules());
    }

    /**
     * Anchors land below the top bar (decision 17): since NAV-11 through <html>'s scroll-padding-top (critical CSS;
     * scroll-padding-bottom = the phone bottom bar) - every scroll into view, the focus moving with Tab included. A scroll
     * margin that added the bar's height again would count it twice - read from the bar's variables or hard-coded as the
     * old header plus a gap (`6rem` on the auth fields, the auth disclosure panel and every FAQ question: a 6rem band
     * between the bar and the target - follow-up review F1). Margins live in the stylesheets, never inline in a template.
     * The padding is the bar's static height (follow-up review Q3: with the live one, 0 while the phone bar is hidden, a
     * jump upwards ends under the returning bar), and 0 on pages without bars (Q2) - the auth pages keep it for their
     * sticky header.
     */
    public function testScrollMarginsAddOnlyTheirOwnGap(): void
    {
        self::assertStringContainsString("\nhtml{scroll-padding-top:var(--header-height);scroll-padding-bottom:var(--bottom-nav-height)}\n", $this->criticalRules());
        self::assertStringContainsString("\n:root:has(> body.no-nav-chrome):not(:has(> body > .site-header)){scroll-padding-top:0}\n", $this->criticalRules());
        self::assertStringNotContainsString('scroll-padding-top:var(--topbar-h)', $this->criticalRules());

        $violations = [];

        foreach ($this->files('assets/styles', ['scss']) as $path => $code) {
            if (preg_match_all('~scroll-margin(?:-top|-block|-block-start)?\s*:([^;{}]*)~', $code, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $index => $value) {
                // The bar's own tools take the bar's height away (Q1, below), never add it
                if (str_starts_with(trim($value), 'calc(-1 * (var(--header-height')) {
                    continue;
                }

                if (preg_match('~--(header-height|topbar-h|desk-topbar-h)\b~', $value) === 1 || self::isHeaderSized($value)) {
                    $violations[] = $path . ': ' . trim($matches[0][$index]);
                }
            }
        }

        foreach ($this->files('templates', ['twig']) as $path => $code) {
            if (preg_match_all('~scroll-margin[^;"\'}]*~', (string) preg_replace('~\{#.*?#\}~s', '', $code), $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $violations[] = $path . ': ' . $match . ' (a scroll margin belongs in the stylesheets)';
                }
            }
        }

        self::assertSame([], $violations, 'html\'s scroll-padding-top reserves the top bar; scroll margins add only their own gap');

        // Follow-up review Q1: the padding makes everything inside the sticky bar look covered - the bar's own tools move
        // their scroll box below it (by the hidden phone bar's shift too), or Tab / focus() onto them scrolled the page
        // up by hundreds of pixels; never what hangs from the bar (menus, results, languages: they scroll like the page)
        $tools = $this->rules((string) file_get_contents($this->root() . '/assets/styles/_nav-topbar.scss'));
        $selector = '.site-header :is(a[href], button, input, select, summary, [tabindex]):not(.dropdown-menu *):not(.site-search-panel *):not(.site-header-languages *)';
        self::assertArrayHasKey($selector, $tools, 'The bar\'s tools keep their scroll box below the scroll padding');
        self::assertStringContainsString('scroll-margin-top: calc(-1 * (var(--header-height, 0px) + var(--topbar-shift, 0px)))', $tools[$selector]);
        self::assertStringContainsString('scroll-margin-bottom: calc(var(--header-height, 0px) + var(--topbar-shift, 0px))', $tools[$selector]);

        // The guard itself: what it must catch and what it must let through
        self::assertTrue(self::isHeaderSized(' 6rem'));
        self::assertTrue(self::isHeaderSized(' 48px'));
        self::assertTrue(self::isHeaderSized(' calc(1rem + 3.5em)'));
        self::assertFalse(self::isHeaderSized(' 1rem'));
        self::assertFalse(self::isHeaderSized(' calc(var(--compact-bar-height, 0px) + .75rem)'));
        self::assertFalse(self::isHeaderSized(' 2.75rem'));
    }

    /** A length of 3rem / 3em / 48 px or more in a scroll margin: the old header's height, not a gap */
    private static function isHeaderSized(string $value): bool
    {
        preg_match_all('~(\d*\.?\d+)(rem|em|px)\b~', $value, $lengths, PREG_SET_ORDER);

        foreach ($lengths as [, $number, $unit]) {
            if ((float) $number >= ($unit === 'px' ? 48.0 : 3.0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Follow-up review Q4 (NAV-11, 200 % text size): the 768-1023 px bar - ☰ with its "Menu" label, the logo, the compact
     * places, 🔍, + Add, ?, Inbox, the avatar - needs 864 px at 200 % (883 px for a guest's Sign in), so the avatar ran off
     * a 768 px screen. With a large text size (the viewport narrower than 34em, em = the browser's text size setting) ☰
     * and + Add become their icons - names kept (☰'s aria-label; + Add's label visually hidden, as next to a running
     * stopwatch) - and a place grows with its label instead of letting it out of its 56 px. At the default size nothing
     * changes (34em = 544 px).
     */
    public function testTheCompactBarStepsDownWithALargeTextSize(): void
    {
        $css = "\n" . $this->criticalRules() . "\n";
        $tier = '(min-width:768px) and (max-width:1023.98px) and (max-width:34em)';

        self::assertStringContainsString("\n@media {$tier} and (min-height:501px),{$tier} and (pointer:fine){.site-header .site-header-menu--desk{width:44px;padding:0}.site-header .site-header-menu-label{display:none}.site-header .site-header-add{width:44px;padding:0}.site-header .site-header-add-label{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}}\n", $css);
        self::assertStringContainsString('.nav-places--compact .nav-tab{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;min-width:56px;min-height:56px;padding:0 4px}', $css);

        // ☰ keeps its name without the label, + Add's label is its name
        $topbar = (string) file_get_contents($this->root() . '/templates/navigation/_topbar.html.twig');
        self::assertMatchesRegularExpression('~class="site-header-tool site-header-menu site-header-menu--desk"[^>]*aria-label="\{\{ menu_name \}\}"~', $topbar);
        self::assertStringContainsString('<span class="site-header-menu-label" aria-hidden="true">', $topbar);
        self::assertStringContainsString('<span class="site-header-add-label">', $topbar);
    }

    /**
     * NAV-07: the Menu's row icons are Bootstrap Icons, a font with font-display: swap. Each sits in a box of its own size
     * with the glyph laid out inside it (measured in Chromium: an inline glyph shifts when the font arrives after the
     * first paint, this box does not), so the docked column reports no layout shift on a cold load.
     */
    public function testMenuRowIconsKeepTheirBoxWithoutTheIconFont(): void
    {
        $menu = (string) file_get_contents($this->root() . '/assets/styles/_nav-menu.scss');

        if (preg_match('~@mixin menu-row-icon\(\$box, \$glyph\) \{(.*?)\n\}~s', $menu, $mixin) !== 1) {
            self::fail('No menu-row-icon mixin in _nav-menu.scss');
        }

        foreach (['flex: 0 0 $box;', 'width: $box;', 'height: $box;', 'overflow: hidden;', 'line-height: $box;', 'position: absolute;', 'inset: 0;'] as $declaration) {
            self::assertStringContainsString($declaration, $mixin[1]);
        }

        foreach (['assets/styles/_nav-menu.scss', 'assets/styles/_nav-layout.scss'] as $file) {
            $code = (string) file_get_contents($this->root() . '/' . $file);
            self::assertGreaterThan(0, preg_match_all('~> \.bi \{([^}]*)\}~', $code, $blocks), $file);

            foreach ($blocks[1] as $block) {
                // A glyph size alone stays inside its box (the place rows' larger glyph); a box size goes through the mixin
                if (preg_match('~\b(flex|width|height|line-height)\s*:~', $block) === 1) {
                    self::assertStringContainsString('@include menu-row-icon(', $block, $file . ': a row icon sized without the box');
                }
            }
        }
    }

    public function testCriticalCssDefinesTheVariables(): void
    {
        $css = $this->criticalRules();

        foreach (['--topbar-h', '--bottom-nav-height', '--nav-left'] as $name) {
            self::assertStringContainsString("@property {$name}{syntax:'<length>';inherits:true;initial-value:0px}", $css);
        }

        self::assertMatchesRegularExpression('~:root\{[^}]*--safe-bottom-max:env\(safe-area-max-inset-bottom,env\(safe-area-inset-bottom,0px\)\)~', $css);
        self::assertMatchesRegularExpression('~:root\{[^}]*--bottom-nav-height:0px~', $css);
        self::assertMatchesRegularExpression('~:root\{[^}]*--topbar-shift:0px~', $css);
        // 0 on :root - NAV-13 sets the tiers on <body> (testTheDesktopMenuTiersAreCriticalCss)
        self::assertMatchesRegularExpression('~:root\{[^}]*--nav-left:0px~', $css);
        self::assertMatchesRegularExpression('~:root\[data-topbar=hidden\]\{--topbar-h:0px;--topbar-shift:var\(--header-height\)\}~', $css);
        // NAV-03: the top bar's static height - 52 px phones, 64 px desktop, 0 on phones under 480 px tall (it scrolls with
        // the page there) - and the live one follows it
        self::assertStringContainsString(':root{--header-height:52px}', $css);
        self::assertStringContainsString('@media (min-width:768px) and (min-height:501px),(min-width:768px) and (pointer:fine){:root{--header-height:64px}}', $css);
        self::assertStringContainsString('@media (max-width:767.98px) and (max-height:479.98px),(max-height:479.98px) and (pointer:coarse){:root{--header-height:0px}}', $css);
        self::assertMatchesRegularExpression('~:root\{[^}]*--topbar-h:var\(--header-height\)~', $css);
        // The bar's height only while a visible .bottom-nav exists, never in the app, never while the page's stopwatch runs
        self::assertMatchesRegularExpression('~:root:not\(\[data-native-app\]\):not\(\[data-stopwatch-running\]\):has\(> body > \.bottom-nav:not\(\[hidden\]\):not\(\.bottom-nav--tabbar-hidden\)\)\{--bottom-nav-height:calc\(var\(--tabbar-h\) \+ var\(--safe-bottom-max\)\)\}~', $css);
        // ADD-04 (decision 44): a start or a resume on the stopwatch page (stopwatch_controller.js sets
        // html[data-stopwatch-running]) takes the phone tab bar away and keeps no room for it - with and without :has() -
        // the top bar stays, desktop keeps the places (NAV review Q3: the attribute is set now, by its one writer)
        self::assertStringContainsString('@supports not selector(:has(a)){@media (max-width:767.98px),(max-height:500.98px) and (pointer:coarse){:root:not([data-native-app]):not([data-stopwatch-running]){--bottom-nav-height:calc(var(--tabbar-h) + var(--safe-bottom-max))}}}', $css);
        self::assertStringContainsString("\n@media (max-width:767.98px),(max-height:500.98px) and (pointer:coarse){:root[data-stopwatch-running] .bottom-nav{display:none}}\n", $css . "\n");
        self::assertSame(3, substr_count($css, 'data-stopwatch-running'), 'html[data-stopwatch-running] touches the tab bar only');
        self::assertDoesNotMatchRegularExpression('~data-stopwatch-running\][^{]*\.site-header~', $css, 'the top bar is never hidden client-side');
        $writers = [];

        foreach ($this->files('assets', ['js']) as $path => $code) {
            if (str_contains($code, 'data-stopwatch-running')) {
                $writers[] = $path;
            }
        }

        self::assertSame(['assets/controllers/stopwatch_controller.js'], $writers);
        // NAV review F4: a page the server knows has no tab bar says 0 on <body> itself - bar-free and auth pages, the
        // app (no-nav-chrome) and hide_bottom_bar (no-tab-bar) - so browsers without :has() keep no room for it either
        self::assertStringContainsString("\nbody.no-nav-chrome,body.no-tab-bar{--bottom-nav-height:0px}\n", "\n" . $css . "\n");
        $base = (string) file_get_contents($this->root() . '/templates/base.html.twig');
        self::assertStringContainsString("{{ (hide_bottom_bar ?? false) ? ' no-tab-bar' }}", $base);
        // NAV-08: hide_bottom_bar takes the tab bar away on phones only (decision 46) - desktop keeps the places (the rail)
        self::assertStringContainsString("\n@media (max-width:767.98px),(max-height:500.98px) and (pointer:coarse){.bottom-nav--tabbar-hidden{display:none}}\n", $css . "\n");

        // Every reserved chrome size is 0 in the app (plan §3.5 item 7)
        if (preg_match('~:root\[data-native-app\]\{([^}]*)\}~', $css, $native) !== 1) {
            self::fail('No html[data-native-app] rule in critical CSS');
        }

        foreach (['--header-height', '--topbar-h', '--topbar-shift', '--bottom-nav-height', '--nav-left'] as $name) {
            self::assertStringContainsString($name . ':0px', $native[1]);
        }

        // README §5 tokens
        // The bar: README's 56 px at the default text size (39.5 px + .875rem = 53.5 px at 16 px), taller only with a larger
        // text size so its label is never cut (NAV-11: 68 px at 200 %)
        self::assertStringContainsString('--tabbar-h:max(56px,calc(39.5px + .875rem))', $css);

        foreach (['--nav-bg:#fff', '--nav-active-ink:#c8323a', '--nav-add-bg:#ec4f52', '--desk-topbar-h:64px', '--nav-col-w:256px', '--nav-rail-w:88px', '--nav-hide-dur:200ms', '--nav-show-dur:280ms'] as $token) {
            self::assertStringContainsString($token, $css);
        }

        // One rule per line
        foreach (explode("\n", trim($css)) as $line) {
            self::assertStringEndsWith('}', $line, 'One rule per line: ' . $line);
        }
    }

    /**
     * NAV-13 (README §4.3 / D16): the desktop Menu's width --nav-left is decided before first paint, by CSS alone -
     * 88 px (the rail, #bottom-nav restyled) from 1024 px, 256 px (the docked column, #site-menu in #menu-slot) at
     * 1280-1439 px with html[data-nav-layout="docked"] and from 1440 px unless html[data-nav-layout="rail"]; never on
     * pages without the bars (body.no-nav-chrome) or in print; the `menu` page (body.no-nav-column) keeps the rail.
     * main and the footer start right of it; the folded groups are hidden before paint.
     */
    public function testTheDesktopMenuTiersAreCriticalCss(): void
    {
        $css = $this->criticalRules();
        $desktop = static fn (int $from): string => sprintf('@media (min-width:%1$dpx) and (min-height:501px),(min-width:%1$dpx) and (pointer:fine){', $from);

        // The rail from 1024 px - fixed under the top bar, 88 px, one under the bar on the z-scale; hide_bottom_bar hides
        // the phone tab bar only, the rail stays (NAV-08)
        self::assertStringContainsString($desktop(1024) . ':root{--nav-left:var(--nav-rail-w)}body:not(.no-nav-chrome)>.bottom-nav:not([hidden]){display:flex;flex-direction:column;top:var(--desk-topbar-h);right:auto;bottom:0;z-index:1029;width:var(--nav-rail-w);', $css);

        // The docked column: the player's choice from 1280 px, the default from $nav-dock-default-from (D16's one switch);
        // the `menu` page keeps the rail (its content is the Menu)
        $dock = 'body:not(.no-nav-column)';
        self::assertSame(1, preg_match('~^\$nav-dock-default-from:\s*(\d+)px;~m', (string) file_get_contents($this->root() . '/assets/styles/_nav-layout.scss'), $default));
        self::assertSame('1440', $default[1]);

        foreach ([1280 => ':root[data-nav-layout=docked]', (int) $default[1] => ':root:not([data-nav-layout=rail])'] as $from => $state) {
            self::assertStringContainsString(sprintf('%s%2$s{--nav-left:var(--nav-col-w)}%2$s %3$s>.bottom-nav{display:none}%2$s %3$s>.menu-slot{display:block}}', $desktop($from), $state, $dock), $css);
        }

        // <body> overrides the tiers after them: the `menu` page keeps the rail's width, the app and the bar-free pages 0
        self::assertStringContainsString($desktop(1024) . 'body.no-nav-column{--nav-left:var(--nav-rail-w)}}', $css);
        self::assertStringContainsString("\n:root[data-native-app],body.no-nav-chrome{--nav-left:0px}\n", $css);
        self::assertGreaterThan((int) strpos($css, ':root:not([data-nav-layout=rail]){--nav-left'), (int) strpos($css, ':root[data-native-app],body.no-nav-chrome{--nav-left:0px}'));

        // The Menu shell closes when the window crosses a tier - the same widths
        $controller = (string) file_get_contents($this->root() . '/assets/controllers/menu_layer_controller.js');
        self::assertStringContainsString("'(min-width: {$default[1]}px) and (min-height: 501px), (min-width: {$default[1]}px) and (pointer: fine)'", $controller);
        self::assertStringContainsString("'(min-width: 1280px) and (min-height: 501px), (min-width: 1280px) and (pointer: fine)'", $controller);

        // The column's box: fixed under the top bar, 256 px, its own scroller that never hands a scroll on to the page
        self::assertSame(1, preg_match('~(?:^|\n)\.menu-slot>\.site-menu\{([^}]*)\}~', $css, $column));
        foreach (['position:fixed', 'top:var(--desk-topbar-h)', 'bottom:0', 'left:0', 'z-index:1029', 'width:var(--nav-col-w)', 'overflow-y:auto', 'overscroll-behavior:contain', 'border-right:1px solid var(--nav-hairline)'] as $declaration) {
            self::assertStringContainsString($declaration, $column[1]);
        }

        // The content and the footer start right of the rail / column; print and the bar-free pages have neither
        self::assertStringContainsString("\nbody>main,body>.site-footer{margin-inline-start:var(--nav-left)}\n", $css);
        self::assertStringContainsString('@media print{body{--nav-left:0px!important}.bottom-nav,.menu-slot{display:none!important}}', $css);
        self::assertSame(2, substr_count($css, '--nav-left:var(--nav-rail-w)'), 'the rail width: from 1024 px, and on the `menu` page');
        self::assertSame(2, substr_count($css, '--nav-left:var(--nav-col-w)'), 'the column width: docked by choice, docked by default');

        // The folded groups of the column, before paint: exactly the groups a player can fold (the places' groups head the
        // column and never fold, decision 30), the same list as the stylesheet's
        $collapsible = [];
        foreach (MenuGroup::cases() as $group) {
            $view = new MenuGroupView($group, 0, 0, []);
            if ($view->isCollapsible()) {
                $collapsible[] = $group->value;
            }
        }

        self::assertSame(['roles', 'puzzling', 'library', 'add', 'account', 'help', 'about'], $collapsible);
        preg_match_all('#:root\[data-nav-collapsed~=([a-z_]+)\] \.menu-slot \[data-menu-group=\1\]>ul>li:not\(\.menu-only-dock\)#', $css, $folded);
        self::assertSame($collapsible, $folded[1]);
        self::assertSame(1, preg_match('~^\$nav-collapsible-groups:\s*([a-z_, ]+);~m', (string) file_get_contents($this->root() . '/assets/styles/_nav-layout.scss'), $scss));
        self::assertSame($collapsible, array_map('trim', explode(',', $scss[1])));
    }

    public function testBaseIncludesTheCriticalCssInTheHeadOrder(): void
    {
        $base = (string) file_get_contents($this->root() . '/templates/base.html.twig');
        $head = substr($base, 0, (int) strpos($base, '</head>'));

        $positions = [
            'viewport' => strpos($head, '<meta name="viewport"'),
            'head flags' => strpos($head, "{{ include('_head_flags.html.twig') }}"),
            'msp-rendered-at' => strpos($head, '<meta name="msp-rendered-at"'),
            'critical style' => strpos($head, '<style>@font-face'),
            'critical partial' => strpos($head, "{{ include('navigation/_critical_css.html.twig') }}</style>"),
            'stylesheet' => strpos($head, "{{ encore_entry_link_tags('app') }}"),
        ];

        $previous = -1;
        $previousWhat = '<head>';

        // Head order (implementation plan §3.11)
        foreach ($positions as $what => $position) {
            self::assertIsInt($position, $what . ' missing from the head');
            self::assertGreaterThan($previous, $position, $what . ' must come after ' . $previousWhat);
            $previous = $position;
            $previousWhat = $what;
        }

        // Inside the critical <style>: no other </style> between its start and the partial
        $style = substr($head, (int) $positions['critical style'], (int) $positions['critical partial'] - (int) $positions['critical style']);
        self::assertStringNotContainsString('</style>', $style);
    }

    /**
     * NAV-06: the old header went with its critical CSS - the topbar band, the navbar with its toolbar, toggler and
     * collapsed menu, the live-event strip, the .legacy-chrome rows' offsets and clip, the test-only weight-probe switch
     * (nav_omit_legacy_chrome) and the .css-loading rules no code set. Neither base.html.twig's critical <style> nor the
     * navigation partial carries any of them; the header block holds the top bar alone.
     */
    public function testTheOldHeaderLeftNoCriticalCss(): void
    {
        $base = (string) file_get_contents($this->root() . '/templates/base.html.twig');
        $start = (int) strpos($base, '<style>@font-face');
        $style = substr($base, $start, (int) strpos($base, '</style>', $start) - $start) . "\n" . $this->criticalRules();

        foreach (['.topbar', '.navbar', 'toggler', '.collapse', 'legacy-chrome', 'competition-panel-info', 'nav_omit_legacy_chrome', 'css-loading', '.no-caret'] as $old) {
            self::assertStringNotContainsString($old, $style, 'Critical CSS of the old header: ' . $old);
        }

        self::assertSame(1, preg_match('~\{% block header %\}(.*?)\{% endblock %\}~s', $base, $header));
        self::assertSame("{% if is_web() %}{{ include('navigation/_topbar.html.twig') }}{% endif %}", trim((string) preg_replace('~\{#.*?#\}~s', '', $header[1])));
    }

    public function testNavBlocksAreWebOnlyAndEmptiedWithTheBars(): void
    {
        $base = (string) file_get_contents($this->root() . '/templates/base.html.twig');

        // The chrome block holds includes only, all inside is_web() (MENU-01 put the Menu slot there)
        self::assertSame(1, preg_match('~\{% block nav_chrome %\}\{% if is_web\(\) %\}((?:\{\{ include\(\'[a-z_/]+\.html\.twig\'\) \}\})*)\{% endif %\}\{% endblock %\}~', $base, $match, PREG_OFFSET_CAPTURE), 'nav_chrome block (web only, includes only) missing');
        $chrome = $match[0][1];
        // The layers block too (MENU-02b put the Menu shell there)
        self::assertSame(1, preg_match('~\{% block nav_layers %\}\{% if is_web\(\) %\}((?:\{\{ include\(\'[a-z_/]+\.html\.twig\'\) \}\})*)\{% endif %\}\{% endblock %\}~', $base, $layersMatch, PREG_OFFSET_CAPTURE), 'nav_layers block (web only, includes only) missing');
        $layers = $layersMatch[0][1];
        $layersBlock = $layersMatch[0][0];
        self::assertStringContainsString("{{ include('navigation/_menu_layer.html.twig') }}", $layersBlock);

        // nav_chrome right after the header block and before <main>; nav_layers last in <body>, after every GlobalSearch
        $headerEnd = strpos($base, '{% endblock %}', (int) strpos($base, '{% block header %}'));
        self::assertGreaterThan($headerEnd, $chrome);
        // <main> may carry data-puzzles-section (PUZ-01)
        self::assertLessThan(strpos($base, '<main id="main-content"'), $chrome);
        self::assertGreaterThan(strrpos($base, 'GlobalSearch'), $layers);
        self::assertGreaterThan(strpos($base, 'id="dynamic-modal-container"'), $layers);
        self::assertLessThan(strpos($base, '</body>'), $layers);
        self::assertSame('', trim(substr($base, $layers + strlen($layersBlock), (int) strpos($base, '</body>') - $layers - strlen($layersBlock))));

        // A page without the bars (it empties header and footer) empties the navigation blocks too (decision 19, 87)
        $barFree = [];

        foreach ($this->files('templates', ['twig']) as $path => $code) {
            if (preg_match('~\{%\s*extends\s+\'base\.html\.twig\'~', $code) !== 1 || preg_match('~\{%\s*block header\s*%\}\s*\{%\s*endblock\s*%\}~', $code) !== 1) {
                continue;
            }

            $barFree[] = $path;

            foreach (['footer', 'nav_chrome', 'nav_layers'] as $block) {
                self::assertMatchesRegularExpression('~\{%\s*block ' . $block . '\s*%\}\s*\{%\s*endblock\s*%\}~', $code, $path . ' empties header but not ' . $block);
            }

            // NAV-13: and says so - body.no-nav-chrome keeps the desktop column's width (--nav-left) at 0
            self::assertMatchesRegularExpression('~\{%\s*set nav_chrome_off = true\s*%\}~', $code, $path . ' empties the bars but does not set nav_chrome_off');
        }

        sort($barFree);
        self::assertSame([
            // An online round's participant page and its dead-link page (README §4.10: one document for the round)
            'templates/compete/not_found.html.twig',
            'templates/compete/page.html.twig',
            'templates/live_results/live_results.html.twig',
            // The public watch page of an online round's studio (the main sync of 2026-10-14: video + clock, ?obs=1)
            'templates/online_rounds/watch.html.twig',
            'templates/participants_sheet/page.html.twig',
            'templates/round_stopwatch.html.twig',
        ], $barFree);
    }

    // ---------------------------------------------------------------------------------------------------------------

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Own declarations (incl. @include lines) of every rule with this full selector in the file, @media/@supports/
     * @include blocks looked through, `&` resolved
     */
    private function declarationsOf(string $file, string $selector): string
    {
        $code = (string) file_get_contents($this->root() . '/' . $file);
        $rules = $this->rules($file === self::CRITICAL ? $this->criticalRules() : $code);

        self::assertArrayHasKey($selector, $rules, $file . ': no rule for ' . $selector);

        return $rules[$selector];
    }

    private function criticalRules(): string
    {
        $code = (string) file_get_contents($this->root() . '/' . self::CRITICAL);

        return trim((string) preg_replace('~\{#.*?#\}~s', '', $code));
    }

    /**
     * @return array<string, string> full selector => own declarations (ScssReader::rules())
     */
    private function rules(string $code): array
    {
        return ScssReader::rules($code);
    }

    private function withoutComments(string $code): string
    {
        return ScssReader::withoutComments($code);
    }

    /**
     * @return list<string> names of @keyframes that set `top`
     */
    private function keyframesSettingTop(): array
    {
        $names = [];

        foreach ($this->files('assets/styles', ['scss']) as $code) {
            $code = $this->withoutComments($code);

            if (preg_match_all('~@keyframes\s+([\w-]+)\s*\{~', $code, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[1] as $index => [$name]) {
                $start = $matches[0][$index][1] + strlen($matches[0][$index][0]);
                $depth = 1;
                $end = $start;

                while ($depth > 0 && $end < strlen($code)) {
                    $depth += $code[$end] === '{' ? 1 : ($code[$end] === '}' ? -1 : 0);
                    $end++;
                }

                if (preg_match('~(?<![\w-])top\s*:~', substr($code, $start, $end - $start)) === 1) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    private function mixin(string $name): string
    {
        $code = $this->withoutComments((string) file_get_contents($this->root() . '/assets/styles/_nav-tokens.scss'));
        $start = strpos($code, '@mixin ' . $name);
        self::assertIsInt($start, 'no @mixin ' . $name);
        $open = (int) strpos($code, '{', $start);
        $depth = 1;
        $end = $open + 1;

        while ($depth > 0 && $end < strlen($code)) {
            if ($code[$end] === '#' && ($code[$end + 1] ?? '') === '{') {
                $end = (int) strpos($code, '}', $end) + 1;
                continue;
            }

            $depth += $code[$end] === '{' ? 1 : ($code[$end] === '}' ? -1 : 0);
            $end++;
        }

        return substr($code, $open, $end - $open);
    }

    /**
     * @param list<string> $extensions
     * @return array<string, string> path relative to the project => contents
     */
    private function files(string $directory, array $extensions): array
    {
        $files = [];
        $root = $this->root();

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true) && !str_contains($file->getPathname(), '/vendor/')) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
