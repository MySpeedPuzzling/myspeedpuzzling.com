<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use SpeedPuzzling\Web\Tests\Navigation\ScssReader;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pressed states within 100 ms (docs/features/navigation README §4.13 G4, requirement GEST-04, implementation plan
 * GEST-04 and decision 58 D-6): every pressed target - tabs, bar buttons, chips, cards, Menu rows, sheet rows and link
 * rows - gets a state layer in the press ink (--nav-press-ink: the 10 % --nav-pressed-pill, a dark ink on the coral
 * surfaces where 10 % coral would be invisible - GEST review F2) on :active, drawn at once and fading out in 150 ms, with
 * the browser's own tap highlight off; one empty passive touchstart listener on the document makes iOS apply :active.
 * The targets are marked in the markup (data-press="fixed" in the bars, data-press in the page, data-press="layer" on
 * cards; row-link table rows by their controller - the .row-link class a Live Component re-render takes away again,
 * GEST review F7) - the markers and the ink GEST-11's ripple reads too.
 * The measurement in the browser (the tab's pill moves within 100 ms of the pointerdown) is checks/GEST-04.py.
 */
final class PressStatesCssTest extends WebTestCase
{
    private const string PARTIAL = 'assets/styles/_press-states.scss';

    private const string LAYER = '--nav-press-layer: var(--nav-press-ink);';

    private const string DEFAULT_INK = '--nav-press-ink: var(--nav-pressed-pill, rgba(254, 105, 106, .1));';

    private const string ROW_LINK = ':is(.row-link, [data-controller~="row-link"])';

    /** The coral surfaces: target => the token of the colour it is drawn in (critical CSS) */
    private const array CORAL = [
        '.nav-tab--add[data-press]' => '--nav-add-bg',
        '.site-header-add[data-press]' => '--nav-badge-bg',
        '.menu-guest-btn--primary[data-press]' => '--nav-badge-bg',
    ];

    /** The pressed rules, one per kind of target (the selectors of _press-states.scss) */
    private const array PRESSED = [
        'bar controls, Menu rows, sheet rows, chips, link rows' => '[data-press]:not(.nav-tab, .stopwatch-pill, [data-press="layer"]):active',
        'cards: while one of their links is pressed' => '[data-press="layer"]:has(a[href]:active)',
        'table rows that open their link: their cells' => 'tr:is(.row-link, [data-controller~="row-link"]):active > *',
        'tabs: on their pill' => '.nav-tab[data-press]:active .nav-tab-icon',
        'Add\'s tile also while marked pending (the answer at pointerdown on Android)' => '.nav-tab--add[data-press].is-pending .nav-tab-icon',
        'the ⏱ pill: on its body' => '.stopwatch-pill[data-press]:active .stopwatch-pill-body',
    ];

    /** Partials that style a target: _press-states.scss is imported after each of them (it wins on equal weight) */
    private const array TARGET_PARTIALS = ['nav-menu', 'nav-bars', 'nav-topbar', 'nav-layout', 'section-chips', 'stopwatch-pill', 'inbox', 'dynamic-modal', 'players'];

    /**
     * Targets whose own :active background stood in for the layer before GEST-04: gone, so a press never draws two
     * layers (one 20 % instead of 10 %) - file => selector
     */
    private const array NO_OWN_PRESSED_BACKGROUND = [
        'assets/styles/_nav-menu.scss' => '.mr:active',
        'assets/styles/_nav-topbar.scss' => '.site-header-tool:active',
        'assets/styles/_section-chips.scss' => '.section-chip:active',
    ];

    public function testThePartialIsImportedAfterEveryTargetsOwnStyles(): void
    {
        $app = $this->read('assets/styles/app.scss');
        preg_match_all('~@import\s+["\']([^"\']+)["\']~', $app, $matches);
        $imports = $matches[1];
        $at = array_search('press-states', $imports, true);

        self::assertIsInt($at, 'app.scss imports _press-states.scss');

        foreach (self::TARGET_PARTIALS as $partial) {
            $before = array_search($partial, $imports, true);
            self::assertIsInt($before, $partial);
            self::assertLessThan($at, $before, $partial . ' is imported before _press-states.scss');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pressedRules(): iterable
    {
        foreach (self::PRESSED as $name => $selector) {
            yield $name => [$selector];
        }
    }

    #[DataProvider('pressedRules')]
    public function testEveryTargetGetsTheLayerAtOnceWhilePressed(string $selector): void
    {
        $rules = ScssReader::rules($this->read(self::PARTIAL));

        self::assertArrayHasKey($selector, $rules, $selector);
        self::assertStringContainsString($this->compact(self::LAYER), $this->compact($rules[$selector]), $selector . ': the layer in the press ink');
        self::assertStringContainsString('transition:none;', $this->compact($rules[$selector]), $selector . ': drawn at once');
    }

    public function testTheLayerLiesOverTheTargetsOwnBackground(): void
    {
        $code = $this->compact(ScssReader::withoutComments($this->read(self::PARTIAL)));
        $rules = ScssReader::rules($this->read(self::PARTIAL));

        // A registered colour (so it can fade), transparent at rest
        self::assertStringContainsString('@property --nav-press-layer{syntax:"<color>";inherits:false;initial-value:transparent;}', $code);

        // A gradient over the element's own background - never a background colour that would replace it
        self::assertSame(
            'background-image:linear-gradient(var(--nav-press-layer),var(--nav-press-layer));',
            $this->compact($rules['[data-press]:not(.nav-tab, .stopwatch-pill)'] ?? ''),
        );
        // Table cells: a second inset shadow over Bootstrap's accent shadow (it paints above every background - the own
        // row's hover colour hid a gradient), on a selector of the same weight as Bootstrap's, imported after it
        self::assertSame(
            'box-shadow:inset 0 0 0 9999px var(--nav-press-layer),inset 0 0 0 9999px var(--#{$prefix}table-accent-bg,transparent);',
            $this->compact($rules['tr' . self::ROW_LINK . ' > *'] ?? ''),
        );
        self::assertStringContainsString('> :not(caption) > * > * {', $this->read('node_modules/bootstrap/scss/_tables.scss'));
        self::assertStringContainsString('box-shadow: inset 0 0 0 9999px var(--#{$prefix}table-accent-bg);', $this->read('node_modules/bootstrap/scss/_tables.scss'));
        self::assertArrayNotHasKey('.row-link > *', $rules, 'the class alone: a Live Component re-render takes it away');

        // A tab's 56 × 32 pill and the ⏱ pill's body: an inset shadow (the current tab's pill keeps its colour)
        foreach (['.nav-tab[data-press] .nav-tab-icon', '.stopwatch-pill[data-press] .stopwatch-pill-body'] as $pill) {
            self::assertSame('box-shadow:inset 0 0 0 2rem var(--nav-press-layer);', $this->compact($rules[$pill] ?? ''), $pill);
        }

        self::assertStringNotContainsString('background-color', $code, 'the layer never replaces a background');

        // The pill moves on tap with its colours (no link colour transition on the tabs)
        self::assertSame('transition:none;', $this->compact($rules['.nav-tab[data-press]'] ?? ''));
    }

    public function testItFadesOutIn150Milliseconds(): void
    {
        $rules = ScssReader::rules($this->read(self::PARTIAL));
        $fade = ':where([data-press]:not(.nav-tab, .stopwatch-pill), tr' . self::ROW_LINK . ' > *, .nav-tab[data-press] .nav-tab-icon, .stopwatch-pill[data-press] .stopwatch-pill-body)';

        self::assertArrayHasKey($fade, $rules);
        self::assertSame('transition:--nav-press-layer .15s ease-out;', $this->compact($rules[$fade]));

        // Links: the theme's `a { transition: $link-transition }` would replace it - their own rule carries both
        $links = 'a:where([data-press]:not(.nav-tab, .stopwatch-pill))';
        self::assertArrayHasKey($links, $rules);
        self::assertSame('transition:$link-transition,--nav-press-layer .15s ease-out;', $this->compact($rules[$links]));
        self::assertStringContainsString('a { transition: $link-transition; }', $this->read('assets/styles/_reboot.scss'));
    }

    public function testTheBrowsersTapHighlightIsOffOnTheTargets(): void
    {
        $rules = ScssReader::rules($this->read(self::PARTIAL));

        foreach (['[data-press]', '[data-press="layer"] a', self::ROW_LINK] as $selector) {
            self::assertStringContainsString('-webkit-tap-highlight-color:transparent;', $this->compact($rules[$selector] ?? ''), $selector);
        }
    }

    /** The ink every target presses in: the 10 % --nav-pressed-pill, set on the targets (GEST-11's ripple reads it there) */
    public function testThePressInkIsTheTenPercentPillByDefault(): void
    {
        $rules = ScssReader::rules($this->read(self::PARTIAL));

        foreach (['[data-press]', self::ROW_LINK] as $selector) {
            self::assertStringContainsString($this->compact(self::DEFAULT_INK), $this->compact($rules[$selector] ?? ''), $selector);
        }

        // The tokens: the default ink over the coral Add tile is invisible - why the coral surfaces have their own
        self::assertLessThan(1.05, $this->contrast($this->rgba('rgba(254, 105, 106, .1)'), $this->token('--nav-add-bg')));
        self::assertSame('rgba(254,105,106,.1)', $this->tokenValue('--nav-pressed-pill'));
    }

    /**
     * GEST review F2: a press on the coral Add tile (+ Add, the guest card's Sign in) must show - its ink composited over
     * the coral differs from the coral by a contrast ratio of at least 1.2 (the 10 % coral ink gave about 1.01)
     */
    public function testTheCoralSurfacesPressInAVisibleInk(): void
    {
        $rules = ScssReader::rules($this->read(self::PARTIAL));

        foreach (self::CORAL as $selector => $token) {
            self::assertArrayHasKey($selector, $rules, $selector);
            self::assertSame(1, preg_match('~--nav-press-ink:(rgba\([^)]*\));~', $this->compact($rules[$selector]), $ink), $selector . ' sets its own ink');

            $surface = $this->token($token);
            $ratio = $this->contrast($this->rgba($ink[1]), $surface);

            self::assertGreaterThanOrEqual(1.2, $ratio, sprintf('%s: %s over %s gives %.3f', $selector, $ink[1], $token, $ratio));
        }

        // The tile itself is drawn in the token the ratio was computed over
        self::assertStringContainsString('background:var(--nav-add-bg,#ec4f52);', $this->compact(ScssReader::rules($this->read('assets/styles/_nav-bars.scss'))['.nav-tab--add .nav-tab-icon'] ?? ''));
    }

    public function testOnePressedLayerNeverTwo(): void
    {
        foreach (self::NO_OWN_PRESSED_BACKGROUND as $file => $selector) {
            $rules = ScssReader::rules($this->read($file));

            self::assertArrayNotHasKey($selector, $rules, $file . ': ' . $selector . ' draws its own pressed background next to the layer');
        }
    }

    /** iOS Safari applies :active only with a touch listener above the element (WebKit bug 138816) */
    public function testOneEmptyPassiveTouchstartListenerOnTheDocument(): void
    {
        $app = $this->read('assets/app.js');

        self::assertSame(1, preg_match_all("~document\.addEventListener\('touchstart', \(\) => \{\}, \{ passive: true \}\);~", $app));
        self::assertStringContainsString('138816', $app);
    }

    /**
     * @return iterable<string, array{string, string, string, string, int}>
     */
    public static function markedSurfaces(): iterable
    {
        $member = NavigationViewer::MEMBER;
        $guest = NavigationViewer::GUEST;

        // The bars: data-press="fixed" (no ripple delay, GEST-11)
        yield 'tabs (tab bar / rail)' => [$member, '/en/faq', '#bottom-nav a.nav-tab', 'fixed', 5];
        yield 'tabs of the 768-1023 px top bar' => [$member, '/en/faq', 'header.site-header .site-header-places a.nav-tab', 'fixed', 3];
        yield 'top bar tools' => [$member, '/en/faq', 'header.site-header .site-header-tool', 'fixed', 6];
        yield '+ Add' => [$member, '/en/faq', 'header.site-header a.site-header-add', 'fixed', 1];
        yield 'avatar ▾' => [$member, '/en/faq', 'header.site-header button.nav-account-trigger', 'fixed', 1];
        yield 'Sign in' => [$guest, '/en/faq', 'header.site-header a.site-header-signin', 'fixed', 1];
        // In the page: data-press
        yield 'Menu rows' => [$member, '/en/faq', '#site-menu :is(a.mr, summary.mr)', '', 30];
        yield 'Menu You card' => [$member, '/en/faq', '#site-menu .menu-you a', '', 2];
        yield 'chips' => [$guest, '/en/ladder', 'main > nav.section-chips a.section-chip', '', 3];
        yield 'Help sheet rows' => [$member, '/en/help', '.help-rows a.help-row', '', 3];
        yield 'the player card\'s pages' => [$member, '/en/puzzler-card/' . NavigationViewer::playerId(NavigationViewer::PLAYER_WITH_FAVORITES), '.players-card-page-link', '', 3];
        // Cards: data-press="layer"
        yield 'library cards' => [$member, '/en/wish-list/' . NavigationViewer::playerId($member), '[id^="library-wishlist-"] > .card', 'layer', 1];
    }

    #[DataProvider('markedSurfaces')]
    public function testTheTargetsCarryTheirMarkers(string $viewer, string $url, string $selector, string $marker, int $atLeast): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $viewer);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        $targets = $crawler->filter($selector);
        self::assertGreaterThanOrEqual($atLeast, $targets->count(), $url . ' ' . $selector);

        foreach ($targets as $target) {
            assert($target instanceof DOMElement);
            self::assertTrue($target->hasAttribute('data-press'), $url . ' ' . $selector . ': ' . $target->getAttribute('data-nav'));
            self::assertSame($marker, $target->getAttribute('data-press'), $url . ' ' . $selector . ': ' . $target->getAttribute('data-nav'));
        }
    }

    public function testCatalogueAndSearchCardsAndTheStopwatchPillAreMarked(): void
    {
        // Rendered only with results / a running stopwatch: the partials themselves
        self::assertStringContainsString('<div class="card card-body shadow p-2 puzzle-list-item" data-press="layer">', $this->read('templates/_puzzle_item.html.twig'));
        self::assertStringContainsString('<div class="shadow" data-press="layer">', $this->read('templates/components/GlobalSearch.html.twig'));
        self::assertStringContainsString('data-nav="topbar/add/stopwatch_pill" data-press="fixed"', $this->read('templates/navigation/_stopwatch_pill.html.twig'));
    }

    /** The value of a colour token of the critical CSS (templates/navigation/_critical_css.html.twig), without spaces */
    private function tokenValue(string $token): string
    {
        $css = $this->read('templates/navigation/_critical_css.html.twig');
        self::assertSame(1, preg_match('~' . preg_quote($token, '~') . ':\s*([^;}]+)~', $css, $match), $token);

        return (string) preg_replace('~\s+~', '', $match[1]);
    }

    /**
     * @return array{float, float, float}
     */
    private function token(string $token): array
    {
        $hex = $this->tokenValue($token);
        self::assertMatchesRegularExpression('~^#[0-9a-f]{6}$~i', $hex, $token);

        return [(float) hexdec(substr($hex, 1, 2)), (float) hexdec(substr($hex, 3, 2)), (float) hexdec(substr($hex, 5, 2))];
    }

    /**
     * @return array{float, float, float, float}
     */
    private function rgba(string $color): array
    {
        self::assertSame(1, preg_match('~^rgba\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*([\d.]+)\s*\)$~', $color, $m), $color);

        return [(float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4]];
    }

    /**
     * The WCAG contrast ratio between a surface and the same surface with the ink composited over it
     *
     * @param array{float, float, float, float} $ink
     * @param array{float, float, float} $surface
     */
    private function contrast(array $ink, array $surface): float
    {
        $pressed = [
            $ink[0] * $ink[3] + $surface[0] * (1 - $ink[3]),
            $ink[1] * $ink[3] + $surface[1] * (1 - $ink[3]),
            $ink[2] * $ink[3] + $surface[2] * (1 - $ink[3]),
        ];

        $a = self::luminance($surface);
        $b = self::luminance($pressed);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * WCAG relative luminance of an sRGB colour (0-255 channels)
     *
     * @param array{float, float, float} $rgb
     */
    private static function luminance(array $rgb): float
    {
        $linear = static fn (float $value): float => ($value /= 255) <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $linear($rgb[0]) + 0.7152 * $linear($rgb[1]) + 0.0722 * $linear($rgb[2]);
    }

    private function read(string $path): string
    {
        $code = file_get_contents(__DIR__ . '/../' . $path);
        self::assertIsString($code, $path);

        return $code;
    }

    /** Declarations without the spaces around their punctuation */
    private function compact(string $code): string
    {
        return (string) preg_replace(['~\s*([:;{}(),])\s*~', '~\s+~'], ['$1', ' '], trim($code));
    }
}
