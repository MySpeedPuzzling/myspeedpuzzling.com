<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
 *   (nav-follow-topbar), never by transitioning or animating `top`; scroll margins read the live --topbar-h;
 * - base.html.twig keeps the head order (§3.11) and the web-only nav_chrome / nav_layers blocks, which every bar-free
 *   page empties together with header and footer.
 * Later items append rows to the providers (the NAV-03 search view, MENU-02b #menu-layer, HELP-01b, CARD-01a, ...).
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
        'assets/styles/_event-parts.scss .ev-month-header' => 'pre-existing: slides up under the events toolbar when --ev-toolbar-height changes (events_page_controller.js); follows the top bar through the translate - decision for NAV-03/NAV-07 (layout shift on /en/events phones)',
    ];

    /** The lengths only critical CSS may set (--header-height is still measured by header_height_controller.js until NAV-03) */
    private const array CSS_ONLY_LENGTHS = ['--nav-left', '--topbar-h', '--topbar-shift', '--bottom-nav-height'];

    /**
     * @return iterable<string, array{string, string}> file => full selector of a closed overlay
     */
    public static function closedOverlays(): iterable
    {
        yield 'global search, critical' => [self::CRITICAL, '.global-search'];
        yield 'global search overlay, critical' => [self::CRITICAL, '.global-search-overlay'];
        yield 'global search' => ['assets/styles/app.scss', '.global-search'];
        yield 'global search overlay' => ['assets/styles/app.scss', '.global-search-overlay'];
        yield 'player card layer' => ['assets/styles/players/_card.scss', '.players-card-layer[hidden]'];
        yield 'events ⋯ menu layer' => ['assets/styles/_events-organizer.scss', '.ev-menu-layer[hidden]'];
        yield 'events country sheet' => ['assets/styles/_events-page.scss', '.ev-sheet'];
        yield 'XP recap sheet' => ['assets/styles/_xp-recap.scss', '.recap-sheet[hidden]'];
        yield 'selection bar' => ['assets/styles/_collection-selection.scss', '.collection-selection-bar[hidden]'];
        // HELP-01b: the Help sheet's own scrim (dynamic_modal_controller.js own-scrim path, implementation plan §7)
        yield 'dynamic modal scrim' => ['assets/styles/_dynamic-modal.scss', '.dynamic-modal-scrim[hidden]'];
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
        self::assertMatchesRegularExpression('~display\s*:\s*block~', $this->declarationsOf('assets/styles/app.scss', '.global-search-shown .global-search'));
        self::assertMatchesRegularExpression('~display\s*:\s*block~', $this->declarationsOf('assets/styles/app.scss', '.global-search-shown .global-search-overlay'));
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
        yield 'player card layer' => ['assets/styles/players/_card.scss', '.players-card-layer', ['bottom']];
        yield 'player card sheet' => ['assets/styles/players/_card.scss', '.is-sheet .players-card-dialog', ['bottom']];
        yield 'events ⋯ menu layer' => ['assets/styles/_events-organizer.scss', '.ev-menu-layer', ['bottom']];
        yield 'events ⋯ menu sheet' => ['assets/styles/_events-organizer.scss', '.is-sheet .ev-menu-dialog', ['bottom']];
        yield 'events country sheet' => ['assets/styles/_events-page.scss', '.ev-sheet', ['bottom']];
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

        // The list of the plan (§4 F-03) - a new sub-bar under the top bar joins it by itself
        self::assertGreaterThanOrEqual(14, count($anchored), implode("\n", $anchored));
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
    }

    public function testScrollMarginsReadTheLiveTopBarHeight(): void
    {
        $violations = [];

        foreach ($this->files('assets/styles', ['scss']) as $path => $code) {
            if (preg_match_all('~scroll-margin-top\s*:[^;]*--header-height[^;]*;~', $code, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $violations[] = $path . ': ' . $match;
                }
            }
        }

        self::assertSame([], $violations, 'Scroll margins read the live var(--topbar-h), not the static --header-height');
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
        // 0 until NAV-13 adds the tier values (decision 17)
        self::assertMatchesRegularExpression('~:root\{[^}]*--nav-left:0px~', $css);
        self::assertMatchesRegularExpression('~:root\[data-topbar=hidden\]\{--topbar-h:0px;--topbar-shift:var\(--header-height\)\}~', $css);
        // The bar's height only while a visible .bottom-nav exists, never with a running stopwatch or in the app
        self::assertMatchesRegularExpression('~:not\(\[data-stopwatch-running\]\):not\(\[data-native-app\]\):has\(> body > \.bottom-nav:not\(\[hidden\]\)\)\{--bottom-nav-height:calc\(var\(--tabbar-h\) \+ var\(--safe-bottom-max\)\)\}~', $css);

        // Every reserved chrome size is 0 in the app (plan §3.5 item 7)
        if (preg_match('~:root\[data-native-app\]\{([^}]*)\}~', $css, $native) !== 1) {
            self::fail('No html[data-native-app] rule in critical CSS');
        }

        foreach (['--header-height', '--topbar-h', '--topbar-shift', '--bottom-nav-height', '--nav-left'] as $name) {
            self::assertStringContainsString($name . ':0px', $native[1]);
        }

        // README §5 tokens
        foreach (['--nav-bg:#fff', '--nav-active-ink:#c8323a', '--nav-add-bg:#ec4f52', '--tabbar-h:56px', '--desk-topbar-h:64px', '--nav-col-w:256px', '--nav-rail-w:88px', '--nav-hide-dur:200ms', '--nav-show-dur:280ms'] as $token) {
            self::assertStringContainsString($token, $css);
        }

        // One rule per line
        foreach (explode("\n", trim($css)) as $line) {
            self::assertStringEndsWith('}', $line, 'One rule per line: ' . $line);
        }
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

    public function testNavBlocksAreWebOnlyAndEmptiedWithTheBars(): void
    {
        $base = (string) file_get_contents($this->root() . '/templates/base.html.twig');

        // The chrome block holds includes only, all inside is_web() (MENU-01 put the Menu slot there)
        self::assertSame(1, preg_match('~\{% block nav_chrome %\}\{% if is_web\(\) %\}((?:\{\{ include\(\'[a-z_/]+\.html\.twig\'\) \}\})*)\{% endif %\}\{% endblock %\}~', $base, $match, PREG_OFFSET_CAPTURE), 'nav_chrome block (web only, includes only) missing');
        $chrome = $match[0][1];
        $layers = strpos($base, '{% block nav_layers %}{% if is_web() %}{% endif %}{% endblock %}');
        self::assertIsInt($layers, 'nav_layers block (web only) missing');

        // nav_chrome right after the header block and before <main>; nav_layers last in <body>, after every GlobalSearch
        $headerEnd = strpos($base, '{% endblock %}', (int) strpos($base, '{% block header %}'));
        self::assertGreaterThan($headerEnd, $chrome);
        self::assertLessThan(strpos($base, '<main id="main-content">'), $chrome);
        self::assertGreaterThan(strrpos($base, 'GlobalSearch'), $layers);
        self::assertGreaterThan(strpos($base, 'id="dynamic-modal-container"'), $layers);
        self::assertLessThan(strpos($base, '</body>'), $layers);
        self::assertSame('', trim(substr($base, $layers + strlen('{% block nav_layers %}{% if is_web() %}{% endif %}{% endblock %}'), (int) strpos($base, '</body>') - $layers - strlen('{% block nav_layers %}{% if is_web() %}{% endif %}{% endblock %}'))));

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
        }

        sort($barFree);
        self::assertSame([
            'templates/live_results/live_results.html.twig',
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
