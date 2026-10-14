<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SpeedPuzzling\Web\Tests\Navigation\ScssReader;
use SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The installed PWA's pull-to-refresh (assets/pull_to_refresh.js, driven by controllers/pwa_lifecycle_controller.js)
 * calls preventDefault() on touchmove - whatever it takes, the browser cannot scroll. It used to take every downward
 * move while the page was at its top: inside an open modal (the page behind it does not scroll) a sheet scrolled down
 * could never be scrolled back up, and a sideways swipe over a table drifting a little downwards lost its scroll.
 * GEST-06 (docs/features/navigation README §4.13 G6): only in the installed web app - never in a browser tab, never
 * inside the Hotwire Native app (NAV-14) -, never under a layer holding a history entry (the Menu panel, the player
 * card, the search view), never while the page holds typed form input (GEST review Q2), and about 128 px of finger
 * travel refreshes (it was about 256 px).
 * Runs the real module under node.
 */
final class PullToRefreshGestureTest extends TestCase
{
    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('touchStarts')]
    public function testWhereAPullMayStart(array $case, bool $allowed): void
    {
        self::assertSame($allowed, $this->gesture($case)['allowed']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function touchStarts(): iterable
    {
        yield 'page at its top, plain content' => [['path' => [['tag' => 'P'], ['tag' => 'MAIN']]], true];
        yield 'page scrolled' => [['scrollY' => 120, 'path' => [['tag' => 'P']]], false];
        yield 'a modal or sheet is open' => [['overlayOpen' => true, 'path' => [['tag' => 'P']]], false];
        // The reported bug: the add sheet's body scrolled down, the page behind it at its top
        yield 'inside a scrolled modal body' => [['path' => [['tag' => 'BUTTON'], ['scrollTop' => 340], ['tag' => 'DIV']]], false];
        yield 'inside a sideways scrolling table' => [['path' => [['tag' => 'TD'], ['tag' => 'TABLE'], ['scrollWidth' => 900, 'clientWidth' => 340, 'overflowX' => 'auto']]], false];
        yield 'inside a wide box that does not scroll' => [['path' => [['tag' => 'TD'], ['scrollWidth' => 900, 'clientWidth' => 340, 'overflowX' => 'hidden']]], true];
        yield 'inside an element that opts out' => [['path' => [['tag' => 'CANVAS'], ['attributes' => ['data-ptr-ignore']]]], false];
        yield 'inside a scroll box still at its top' => [['path' => [['tag' => 'LI'], ['scrollTop' => 0, 'overflowX' => 'hidden']]], true];
        // GEST review Q2: a reload would throw a half-filled form away ("Add never reloads")
        yield 'page at its top holding typed form input' => [['formInput' => true, 'path' => [['tag' => 'P'], ['tag' => 'MAIN']]], false];
        yield 'page at its top, its forms untouched' => [['formInput' => false, 'path' => [['tag' => 'P'], ['tag' => 'MAIN']]], true];
    }

    /** The form input is read last - it walks every form of the page, so only for a touch that could pull at all */
    public function testTheFormInputIsAskedOnlyWhereAPullCouldStart(): void
    {
        self::assertSame(1, $this->gesture(['formInput' => false, 'path' => [['tag' => 'P']]])['formInputAsked']);
        self::assertSame(0, $this->gesture(['formInput' => true, 'scrollY' => 120, 'path' => [['tag' => 'P']]])['formInputAsked']);
        self::assertSame(0, $this->gesture(['formInput' => true, 'overlayOpen' => true, 'path' => [['tag' => 'P']]])['formInputAsked']);
        self::assertSame(0, $this->gesture(['formInput' => true, 'path' => [['tag' => 'P'], ['attributes' => ['data-ptr-ignore']]]])['formInputAsked']);
    }

    /**
     * What counts as a layer over the page (pull_to_refresh.js overlayOpen(), on a real DOM): a shown modal, the Menu
     * panel, the site search - but not the released Help sheet (review Q1, HELP-05: the page behind it is usable again,
     * so the page's pull-to-refresh is armed), except for a touch on the sheet itself
     */
    public function testAReleasedSheetLeavesThePageArmed(): void
    {
        $page = '<main><p id="page">Page</p></main>';
        $sheet = static fn (string $classes): string => '<div class="' . $classes . '"><div class="modal-dialog"><p id="sheet">Thanks</p></div></div>';
        $cases = [
            'plain page' => [['html' => $page, 'target' => '#page'], false],
            'a shown modal' => [['html' => $sheet('modal show') . $page, 'target' => '#page'], true],
            'a modal still on body.modal-open' => [['body' => 'modal-open', 'html' => $page, 'target' => '#page'], true],
            'the released sheet, a touch on the page' => [['html' => $sheet('modal show is-released') . $page, 'target' => '#page'], false],
            'the released sheet, a touch on the sheet' => [['html' => $sheet('modal show is-released') . $page, 'target' => '#sheet'], true],
            'the Menu panel (dialog[open])' => [['html' => '<dialog open><p>Menu</p></dialog>' . $page, 'target' => '#page'], true],
            'the site search' => [['body' => 'global-search-shown', 'html' => $page, 'target' => '#page'], true],
            'a closed modal' => [['html' => $sheet('modal') . $page, 'target' => '#page'], false],
        ];

        $results = $this->harness(['overlay' => array_column($cases, 0)]);
        self::assertIsArray($results);

        self::assertSame(
            array_combine(array_keys($cases), array_column($cases, 1)),
            array_combine(array_keys($cases), $results),
        );
    }

    /**
     * GEST-06 / implementation plan §3.4 item 6: while a layer of assets/layer_history.js holds the history entry the
     * page is covered - the Menu panel, the player card, the phone search view (also html[data-search-open]), a sheet,
     * the live results views. The released Help sheet keeps its entry, but the page behind it is armed again (HELP-05).
     */
    public function testALayerHoldingTheHistoryEntryCoversThePage(): void
    {
        $page = '<main><p id="page">Page</p></main>';
        $released = '<div class="modal show is-released"><div class="modal-dialog"><p id="sheet">Thanks</p></div></div>';
        $cases = [
            'no layer' => [['html' => $page, 'target' => '#page', 'layer' => null], false],
            'the Menu panel' => [['html' => $page, 'target' => '#page', 'layer' => 'menu'], true],
            'the player card' => [['html' => $page, 'target' => '#page', 'layer' => 'card'], true],
            'the phone search view' => [['html' => $page, 'target' => '#page', 'layer' => 'search'], true],
            'the live results views' => [['html' => $page, 'target' => '#page', 'layer' => 'live-results'], true],
            'a sheet of the dynamic modal' => [['html' => '<div class="modal show"><p>Sheet</p></div>' . $page, 'target' => '#page', 'layer' => 'modal'], true],
            'the released Help sheet, a touch on the page' => [['html' => $released . $page, 'target' => '#page', 'layer' => 'modal'], false],
            'the released Help sheet, a touch on the sheet' => [['html' => $released . $page, 'target' => '#sheet', 'layer' => 'modal'], true],
            'the open search (html[data-search-open])' => [['htmlAttributes' => 'data-search-open', 'html' => $page, 'target' => '#page'], true],
        ];

        $results = $this->harness(['overlay' => array_column($cases, 0)]);
        self::assertIsArray($results);

        self::assertSame(
            array_combine(array_keys($cases), array_column($cases, 1)),
            array_combine(array_keys($cases), $results),
        );
    }

    /**
     * README §4.13 G6: the indicator follows half of the finger's way and refreshes once it has come 64 px - about 128 px of
     * finger travel, no longer about 256 px (the old curve: 70 px after a resistance of 2.8 + d/300)
     */
    public function testAbout128PxOfFingerTravelRefreshes(): void
    {
        $result = $this->harness(['maths' => [0, 64, 100, 127, 128, 160, 256, -20]]);
        self::assertIsArray($result);

        self::assertSame(0.5, $result['dragRate']);
        self::assertSame(64, $result['pullTarget']);

        self::assertIsArray($result['pulls']);
        $pulls = [];

        foreach ($result['pulls'] as $pull) {
            self::assertIsArray($pull);
            self::assertIsInt($pull['distance']);
            $pulls[(string) $pull['distance']] = [$pull['offset'], $pull['past'], $pull['progress']];
        }

        self::assertEquals([
            '0' => [0, false, 0],
            '64' => [32, false, 0.5],
            '100' => [50, false, 0.78125],
            '127' => [63.5, false, 0.9921875],
            '128' => [64, true, 1],
            '160' => [80, true, 1],
            '256' => [128, true, 1],
            '-20' => [0, false, 0],
        ], $pulls);
    }

    /**
     * README §4.13 G6 + §4.14 (b): our pull-to-refresh only in the installed web app. A browser tab keeps the browser's
     * own; the Hotwire Native app never arms it (its <html data-native-app>; the installed-app class is not even set
     * there - assets/head/standalone.js returns early - but a stray one changes nothing)
     */
    public function testOnlyTheInstalledWebAppPulls(): void
    {
        self::assertSame(
            [true, false, false, false],
            $this->harness(['enabled' => [
                ['standalone' => true, 'nativeApp' => false],
                ['standalone' => false, 'nativeApp' => false],
                ['standalone' => true, 'nativeApp' => true],
                ['standalone' => false, 'nativeApp' => true],
            ]]),
        );

        self::assertSame(
            [true, false, false, false],
            $this->harness(['roots' => [
                ['classes' => ['is-standalone']],
                ['classes' => []],
                ['classes' => ['is-standalone'], 'attributes' => ['data-native-app' => 'ios']],
                ['classes' => [], 'attributes' => ['data-native-app' => 'android']],
            ]]),
        );
    }

    /**
     * README §4.13 G6: browser tabs keep the browser's own overscroll and pull-to-refresh - overscroll-behavior on the
     * document (html, body) only inside the installed app (html.is-standalone, the same flag as the controller's
     * isStandalone()), never in a browser tab
     */
    public function testBrowserTabsKeepTheirOverscroll(): void
    {
        $compound = '(?::root|html|body)(?:\[[^\]]*\]|\.[\w-]+|:[\w-]+(?:\([^)]*\))?)*';
        $document = '~^' . $compound . '(?:\s*>?\s*' . $compound . ')*$~';
        $found = [];

        $root = dirname(__DIR__);
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/assets/styles', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'scss') {
                continue;
            }

            foreach (ScssReader::rules((string) file_get_contents($file->getPathname())) as $selector => $declarations) {
                if (preg_match($document, $selector) === 1 && preg_match('~(^|;)\s*overscroll-behavior(-y)?\s*:~', $declarations) === 1) {
                    $found[$selector] = trim($declarations);
                }
            }
        }

        self::assertSame(['html.is-standalone' => 'overscroll-behavior-y: none;', 'html.is-standalone body' => 'overscroll-behavior-y: none;'], $found);

        // Nor in the critical CSS (base.html.twig's <style>, the navigation partial)
        foreach (['templates/base.html.twig', 'templates/navigation/_critical_css.html.twig'] as $path) {
            self::assertDoesNotMatchRegularExpression('~(?:^|[}\s,])' . $compound . '\{[^}]*overscroll-behavior~', (string) file_get_contents($root . '/' . $path), $path);
        }
    }

    /**
     * The controller asks the module for every decision: the enable rule, the maths, the layer guard - never its own
     * curve or matchMedia (GEST-06 moved the pull-distance maths out of it)
     */
    public function testTheControllerUsesTheModule(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../assets/controllers/pwa_lifecycle_controller.js');

        self::assertStringContainsString('pullEnabled({ standalone: this._isPwa, nativeApp: isNativeApp() })', $controller);
        self::assertStringContainsString('this._isPwa = isStandalone();', $controller);
        self::assertStringContainsString('layer: layers.current(),', $controller);
        // GEST review Q2: never armed over typed form input - the 15-minute reload's own rule
        self::assertStringContainsString('hasFormInput: () => this._hasActiveFormInput(),', $controller);
        // ... counting only controls the form sends: an untouched add form (flatpickr's unnamed date field) still pulls
        self::assertMatchesRegularExpression('~for \(const el of form\.elements\) \{\s*if \(!el\.name\) \{\s*continue;\s*\}~', $controller);
        // GEST review F8: a second finger on a pull sends the indicator back before the gesture starts again
        self::assertMatchesRegularExpression('~if \(this\._gesture\.pulling\) \{\s*this\._resetIndicator\(\);\s*\}\s*this\._gesture\.start\(~', $controller);
        self::assertStringContainsString('pastThreshold(this._gesture.end())', $controller);
        self::assertStringContainsString('top: var(--topbar-h, 0px);', (string) file_get_contents(__DIR__ . '/../assets/styles/_pull-to-refresh.scss'));
        self::assertStringNotContainsString('display-mode', $controller);
        self::assertStringNotContainsString('PULL_RESISTANCE', $controller);
    }

    public function testAClearlyDownwardMoveIsAPull(): void
    {
        $result = $this->gesture(['moves' => [[0, 3], [1, 9], [2, 40], [2, 120]]]);

        // The first pixels decide, nothing is taken from the browser before that
        self::assertSame([false, true, true, true], $result['prevented']);
        self::assertSame(120, $result['distance']);
    }

    /**
     * @param list<array{0: int, 1: int, 2?: array<string, bool|int>}> $moves
     */
    #[DataProvider('gesturesLeftToTheBrowser')]
    public function testNeverPreventsAGestureThatIsNotAPull(array $moves, int $touches = 1): void
    {
        $result = $this->gesture(['moves' => $moves, 'touches' => $touches]);

        self::assertSame(array_fill(0, count($moves), false), $result['prevented']);
        self::assertSame(0, $result['distance']);
    }

    /**
     * @return iterable<string, array{0: list<array{0: int, 1: int, 2?: array<string, bool|int>}>, 1?: int}>
     */
    public static function gesturesLeftToTheBrowser(): iterable
    {
        yield 'sideways swipe drifting downwards' => [[[-6, 1], [-14, 3], [-60, 12], [-140, 30]]];
        yield 'diagonal swipe' => [[[6, 6], [20, 20], [60, 60]]];
        yield 'upward scroll' => [[[0, -9], [0, -40]]];
        yield 'browser already scrolling' => [[[0, 10, ['cancelable' => false]], [0, 40]]];
        yield 'pinch with two fingers' => [[[0, 10], [0, 50]], 2];
    }

    public function testNothingIsPulledWhereThePullMayNotStart(): void
    {
        $result = $this->gesture([
            'path' => [['tag' => 'BUTTON'], ['scrollTop' => 340]],
            'moves' => [[0, 10], [0, 80], [0, 200]],
        ]);

        self::assertFalse($result['allowed']);
        self::assertSame([false, false, false], $result['prevented']);
    }

    public function testMovingBackAboveTheStartEndsThePull(): void
    {
        $result = $this->gesture(['moves' => [[0, 20], [0, 60], [0, -5], [0, 30]]]);

        self::assertSame([true, true, false, false], $result['prevented']);
        self::assertSame(0, $result['distance']);
    }

    /**
     * One touch: where it starts (`path`: the touched element and its ancestors, `scrollY`, `overlayOpen`) and how the
     * finger moves from there (`moves`: [dx, dy, page?]).
     *
     * @param array<string, mixed> $case
     * @return array{allowed: bool, prevented: list<bool>, distance: int|float, formInputAsked: int}
     */
    private function gesture(array $case): array
    {
        /** @var list<array{allowed: bool, prevented: list<bool>, distance: int|float, formInputAsked: int}> $results */
        $results = $this->harness([$case + ['moves' => []]]);

        return $results[0];
    }

    /**
     * @param array<mixed> $input
     */
    private function harness(array $input): mixed
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/pull-to-refresh-harness.mjs']);
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
