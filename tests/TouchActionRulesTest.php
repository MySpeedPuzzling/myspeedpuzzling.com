<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SpeedPuzzling\Web\Tests\Navigation\ScssReader;
use SplFileInfo;

/**
 * No accidental zoom (docs/features/navigation README §4.13 G5, requirement GEST-05, implementation plan GEST-05):
 * pinch-zoom works everywhere (F-04 removed maximum-scale=1 - Controller/HeadMetaTest), and a quick second tap on a
 * control is never a double-tap zoom: touch-action: manipulation on the bars, buttons, chips, tab strips, the
 * stopwatch's controls and the sheet bodies (assets/styles/_touch.scss). touch-action: none - no scroll, no zoom under
 * the finger - only on drag handles, never on a large area. The chart canvas keeps its pan-y (decision 59).
 */
final class TouchActionRulesTest extends TestCase
{
    private const string PARTIAL = 'assets/styles/_touch.scss';

    /** README §4.13 G5 - what never double-tap zooms: kind => the selectors of _touch.scss */
    private const array MANIPULATION = [
        'bars and pressed controls (tabs, bar tools, Menu and sheet rows, chips, cards)' => ['.site-header', '.bottom-nav', '[data-press]'],
        'buttons and what acts like one' => ['button', '.btn', '[role="button"]', 'summary', 'label', 'select', 'input[type="checkbox"]', 'input[type="radio"]', '.form-check'],
        'tab strips and chips' => ['.nav-link', '[role="tab"]', '.player-tabs', 'a[class*="chip"]'],
        'stopwatch controls' => ['[data-controller~="stopwatch"] button', '[data-controller~="stopwatch"] a', '[data-controller~="round-stopwatch"] button', '[data-controller~="round-stopwatch"] a'],
        'sheet and panel bodies' => ['.modal-body', '[data-bottom-sheet-target="body"]', '.player-card-box', '.menu-layer-body', '.site-menu'],
    ];

    /**
     * The only touch-action: none - file => selector => why. Each is a handle the finger holds or drags, never an area
     * one scrolls over.
     */
    private const array NONE_ALLOWED = [
        'assets/styles/_bottom-sheet.scss' => [
            '.bottom-sheet-top' => 'the sheets\' handle + header (36 × 5 px handle and the title row): the finger drags the sheet there (GEST-02; the achievement sheet\'s handle since then)',
        ],
        'assets/styles/_seating.scss' => [
            '.seating-handle' => 'the seating drag handle, 1.75 rem wide: a row is dragged by it',
        ],
        'assets/styles/online_rounds.scss' => [
            '.online-talk-hold' => 'the online round\'s hold-to-talk button: held for seconds, a pan would cancel the hold (pointercancel) - a button, not an area',
        ],
    ];

    /**
     * The only touch-action values besides `none` that take pinch-zoom away (no `pinch-zoom` in the list, not `auto` or
     * `manipulation`): file => selector => value
     */
    private const array PAN_ONLY_ALLOWED = [
        // Decision 59: the chart canvas (KEPT below)
        'assets/styles/app.scss' => ['canvas' => 'pan-y'],
    ];

    /** Known exceptions, kept as they are: file => selector => declaration */
    private const array KEPT = [
        // Decision 59: the charts (Chart.js canvases) scroll the page vertically and nothing else - it also keeps the
        // browser's pinch-zoom off the chart; left as it is
        'assets/styles/app.scss' => ['canvas' => 'touch-action:pan-y !important;'],
        // A sideways drag tilts the achievement medal, a vertical one still scrolls the sheet, pinch-zoom works
        'assets/styles/_achievement-medal.scss' => ['.medal-card' => 'touch-action:pan-y pinch-zoom;'],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function manipulationSelectors(): iterable
    {
        foreach (self::MANIPULATION as $kind => $selectors) {
            foreach ($selectors as $selector) {
                yield $kind . ': ' . $selector => [$selector];
            }
        }
    }

    #[DataProvider('manipulationSelectors')]
    public function testNoDoubleTapZoomOn(string $selector): void
    {
        $rules = ScssReader::rules($this->read(self::PARTIAL));

        self::assertArrayHasKey($selector, $rules, self::PARTIAL . ' styles ' . $selector);
        self::assertSame('touch-action:manipulation;', $this->compact($rules[$selector]), $selector);
    }

    public function testThePartialSaysOnlyManipulation(): void
    {
        $listed = array_merge(...array_values(self::MANIPULATION));
        $rules = ScssReader::rules($this->read(self::PARTIAL));

        ksort($rules);
        sort($listed);
        self::assertSame($listed, array_keys($rules), 'every selector of ' . self::PARTIAL . ' is listed above');
    }

    /**
     * Imported early - after the navigation tokens, before every component and every partial that sets a touch-action
     * of its own, so the element's own value (a drag handle's none, the medal's pan-y pinch-zoom) wins on equal weight
     */
    public function testThePartialIsImportedBeforeEveryOwnTouchAction(): void
    {
        $app = $this->read('assets/styles/app.scss');
        preg_match_all('~@import\s+["\']([^"\']+)["\']~', $app, $matches);
        $imports = $matches[1];
        $at = array_search('touch', $imports, true);

        self::assertIsInt($at, 'app.scss imports _touch.scss');
        self::assertSame('nav-tokens', $imports[$at - 1], '_touch.scss right after the navigation tokens');
        self::assertLessThan(array_search('reboot', $imports, true), $at);
        self::assertLessThan(array_search('components', $imports, true), $at);

        $checked = 0;

        foreach ($imports as $index => $import) {
            $file = $this->partialPath($import);

            if ($file === null || $import === 'touch' || !str_contains(ScssReader::withoutComments($this->read($file)), 'touch-action')) {
                continue;
            }

            self::assertGreaterThan($at, $index, $file . ' sets its own touch-action - it must come after _touch.scss');
            $checked++;
        }

        self::assertGreaterThanOrEqual(5, $checked, 'the partials with their own touch-action were found');
    }

    /** touch-action: none only on the drag handles above - in every stylesheet, template and script */
    public function testTouchActionNoneOnlyOnDragHandles(): void
    {
        $found = [];

        foreach ($this->files('assets/styles', ['scss', 'css']) as $path => $code) {
            foreach (ScssReader::rules($code) as $selector => $declarations) {
                if (preg_match('~(^|;)touch-action:\s*none\b~', $this->compact($declarations)) === 1) {
                    $found[$path][] = $selector;
                }
            }
        }

        $allowed = array_map(static fn (array $selectors): array => array_keys($selectors), self::NONE_ALLOWED);
        ksort($found);
        ksort($allowed);
        self::assertSame($allowed, $found, 'touch-action: none belongs on drag handles only (README §4.13 G5) - list a new handle with its reason');

        // Nowhere else: no inline style, no critical CSS, no script setting it
        foreach ([...$this->files('templates', ['twig']), ...$this->files('assets', ['js', 'mjs'])] as $path => $code) {
            $code = ScssReader::withoutComments($code);
            self::assertDoesNotMatchRegularExpression('~touch-action\s*:\s*none~', $code, $path);
            self::assertDoesNotMatchRegularExpression('~touchAction\s*=\s*[\'"]none~', $code, $path);
        }
    }

    /**
     * Pinch-zoom everywhere (README §4.13 G5; WCAG 1.4.4, 1.4.10): any touch-action without `pinch-zoom` - `none`, but
     * just as well `pan-x`, `pan-y` or `pan-x pan-y` - takes it away under the finger (GEST review F9). Only the drag
     * handles and the chart canvas listed above may - in every stylesheet; no inline style or script takes it away
     */
    public function testOnlyTheListedRulesTakePinchZoomAway(): void
    {
        $found = [];

        foreach ($this->files('assets/styles', ['scss', 'css']) as $path => $code) {
            foreach (ScssReader::rules($code) as $selector => $declarations) {
                preg_match_all('~(?:^|;)touch-action:([^;]+)~', $this->compact($declarations), $matches);

                foreach ($matches[1] as $value) {
                    $value = trim(str_replace('!important', '', $value));

                    if (!self::keepsPinchZoom($value)) {
                        $found[$path][$selector] = $value;
                    }
                }
            }
        }

        $allowed = array_map(static fn (array $selectors): array => array_fill_keys(array_keys($selectors), 'none'), self::NONE_ALLOWED);

        foreach (self::PAN_ONLY_ALLOWED as $path => $selectors) {
            $allowed[$path] = ($allowed[$path] ?? []) + $selectors;
        }

        self::assertSame(self::sorted($allowed), self::sorted($found), 'a touch-action without pinch-zoom takes the zoom away (README §4.13 G5) - only on drag handles and the chart canvas; list a new one with its reason');

        foreach ([...$this->files('templates', ['twig']), ...$this->files('assets', ['js', 'mjs'])] as $path => $code) {
            $code = ScssReader::withoutComments($code);
            preg_match_all('~touch-action\s*:\s*([a-z][a-z -]*)~i', $code, $inline);
            preg_match_all('~touchAction\s*=\s*[\'"]([^\'"]*)~', $code, $scripted);

            foreach ([...$inline[1], ...$scripted[1]] as $value) {
                self::assertTrue(self::keepsPinchZoom($value), $path . ': touch-action ' . $value);
            }
        }
    }

    public function testTheKnownExceptionsStayAsTheyAre(): void
    {
        foreach (self::KEPT as $file => $rules) {
            $actual = ScssReader::rules($this->read($file));

            foreach ($rules as $selector => $declaration) {
                self::assertArrayHasKey($selector, $actual, $file);
                self::assertStringContainsString($declaration, $this->compact($actual[$selector]), $file . ' ' . $selector);
            }
        }
    }

    /** Pinch-zoom everywhere: no template blocks it (F-04 removed maximum-scale=1; HeadMetaTest renders the head) */
    public function testNoTemplateBlocksZoom(): void
    {
        foreach ($this->files('templates', ['twig']) as $path => $code) {
            self::assertStringNotContainsString('maximum-scale', $code, $path);
            self::assertStringNotContainsString('user-scalable', $code, $path);
        }
    }

    /**
     * @param array<string, array<string, string>> $rules
     * @return array<string, array<string, string>>
     */
    private static function sorted(array $rules): array
    {
        ksort($rules);

        return array_map(static function (array $selectors): array {
            ksort($selectors);

            return $selectors;
        }, $rules);
    }

    /** auto, manipulation, or a list that keeps pinch-zoom */
    private static function keepsPinchZoom(string $value): bool
    {
        $value = strtolower(trim($value));

        return in_array($value, ['auto', 'manipulation'], true) || str_contains($value, 'pinch-zoom');
    }

    private function partialPath(string $import): null|string
    {
        $directory = dirname($import) === '.' ? '' : dirname($import) . '/';
        $name = basename($import);

        foreach (['_' . $name . '.scss', $name . '.scss'] as $candidate) {
            $path = 'assets/styles/' . $directory . $candidate;

            if (is_file(__DIR__ . '/../' . $path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param list<string> $extensions
     * @return array<string, string>
     */
    private function files(string $directory, array $extensions): array
    {
        $root = dirname(__DIR__);
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            $path = substr($file->getPathname(), strlen($root) + 1);

            if (str_starts_with($path, 'assets/vendor/')) {
                continue;
            }

            $files[$path] = (string) file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
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
