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
 * The document stays the scroller (docs/features/navigation README §4.13 G7) and the motion rules (G8) - requirement
 * GEST-07, implementation plan GEST-07:
 * - fixed bars over a scrolling document, never an app-shell scroll box: no html / body / main sets overflow auto or
 *   scroll (a scroll lock - overflow: hidden while the phone search is open - is not a scroller);
 * - sheets in ≤ 300 ms, out ≈ 200 ms; nothing over 400 ms (a transition's duration + delay, an animation's);
 * - nothing moves without an interaction: no endless animation in the navigation and sheet styles but a progress
 *   indicator listed below;
 * - reduced motion → fades or none, instant scroll; a drag still follows the finger (the drag code sets its transform
 *   with no transition - GEST-02's and GEST-06's own tests). Every partial that moves something (a transition of
 *   anything but a colour or opacity, or an animation) has a prefers-reduced-motion block, and each `reduce` block turns
 *   motion off or into a fade. A partial that only fades colours needs none: reduced motion keeps fades (G8). That is
 *   _press-states.scss (GEST-04): its 150 ms fade-out of the pressed layer is a colour, never movement, and it is what
 *   makes a quick tap visible at all - :active lasts a frame or two on a touch screen, so dropping the fade under reduced
 *   motion would drop the feedback itself (decided in GEST-07 rather than a block that would cost that).
 * The browser part (Hub at 360: the document scrolls; the Menu panel's animations; nothing animating at rest) is
 * checks/GEST-07.py.
 */
final class NavigationMotionRulesTest extends TestCase
{
    /** The navigation and sheet styles (the plan's list, plus every navigation partial built since) */
    private const array PARTIALS = [
        'assets/styles/_nav-tokens.scss',
        'assets/styles/_nav-bars.scss',
        'assets/styles/_nav-topbar.scss',
        'assets/styles/_nav-menu.scss',
        'assets/styles/_nav-layout.scss',
        'assets/styles/_nav-label-steps.scss',
        'assets/styles/_bottom-sheet.scss',
        'assets/styles/_dynamic-modal.scss',
        'assets/styles/players/_card.scss',
        'assets/styles/_confirm-dialog.scss',
        'assets/styles/_press-states.scss',
        'assets/styles/_section-chips.scss',
        'assets/styles/_stopwatch-pill.scss',
        'assets/styles/_hub-feeds.scss',
        'assets/styles/_inbox.scss',
        'assets/styles/_community.scss',
        'assets/styles/_touch.scss',
    ];

    /** Partials of later items (GEST-11 ripple, GEST-12/17 view transitions, GEST-19 pull indicator): checked once they exist */
    private const array LATER = [
        'assets/styles/_press-feedback.scss',
        'assets/styles/_view-transitions.scss',
        'assets/styles/_pull-to-refresh.scss',
    ];

    /** The installed app's pull indicator, injected as a <style> by the controller until GEST-19 moves it to _pull-to-refresh.scss */
    private const string PULL_INDICATOR = 'assets/controllers/pwa_lifecycle_controller.js';

    /** Where the partials' shared SCSS variables come from */
    private const array VARIABLE_SOURCES = [
        'assets/styles/_user-variables.scss',
        'assets/styles/_variables.scss',
        'assets/styles/_nav-tokens.scss',
        'assets/styles/_bottom-sheet.scss',
    ];

    /** The navigation's motion custom properties (critical CSS) */
    private const string CRITICAL = 'templates/navigation/_critical_css.html.twig';

    /** Endless animations allowed: keyframes => why (they run only after an interaction, while something loads) */
    private const array LOOPS = [
        'pwa-ptr-spin' => 'the installed app\'s refresh spinner: shown after the release of a pull, until the page reloads',
    ];

    /** Transitions of these properties fade - they never move anything */
    private const array FADES = ['opacity', 'color', 'background-color', 'background', 'border-color', 'box-shadow', 'visibility', 'outline-color', 'fill', 'stroke', '--nav-press-layer'];

    private const int MAX_MS = 400;

    /**
     * @return iterable<string, array{string}>
     */
    public static function sources(): iterable
    {
        foreach ([...self::PARTIALS, ...self::LATER] as $path) {
            if (is_file(__DIR__ . '/../' . $path)) {
                yield $path => [$path];
            }
        }

        yield self::PULL_INDICATOR => [self::PULL_INDICATOR];
    }

    #[DataProvider('sources')]
    public function testNothingTakesLongerThan400Milliseconds(string $path): void
    {
        self::assertFileExists(__DIR__ . '/../' . $path);
        $declarations = $this->motionDeclarations($path);

        foreach ($declarations as [$property, $value]) {
            foreach ($this->items($value) as $item) {
                $loops = array_filter(array_keys(self::LOOPS), static fn (string $name): bool => str_contains($item, $name));

                if (str_contains($item, 'infinite')) {
                    self::assertNotSame([], $loops, sprintf('%s: %s: %s - nothing moves without an interaction (README §4.13 G8)', $path, $property, $item));

                    // A listed progress indicator: its period is no motion's duration
                    continue;
                }

                self::assertLessThanOrEqual(self::MAX_MS, array_sum($this->times($item)), sprintf('%s: %s: %s', $path, $property, $item));
            }
        }

        // The timing variables themselves (a transition may read one through a mixin)
        foreach ($this->localVariables($path) as $name => $value) {
            self::assertLessThanOrEqual(self::MAX_MS, array_sum($this->times($value)), $path . ': $' . $name . ': ' . $value);
        }
    }

    public function testTheMotionTokensStayWithin400Milliseconds(): void
    {
        preg_match_all('~(--nav-[\w-]*dur)\s*:\s*([^;}]+)~', $this->read(self::CRITICAL), $matches, PREG_SET_ORDER);

        self::assertSame(['--nav-hide-dur', '--nav-show-dur'], array_column($matches, 1));

        foreach ($matches as [, $name, $value]) {
            self::assertLessThanOrEqual(self::MAX_MS, array_sum($this->times($value)), $name);
        }
    }

    #[DataProvider('sources')]
    public function testWhatMovesStepsAsideUnderReducedMotion(string $path): void
    {
        self::assertFileExists(__DIR__ . '/../' . $path);
        $code = $this->code($path);

        if ($this->moves($path)) {
            self::assertStringContainsString('prefers-reduced-motion', $code, $path . ' moves something: a prefers-reduced-motion block turns it into a fade or nothing (README §4.13 G8)');
        }

        foreach ($this->reducedMotionBlocks($code) as $block) {
            self::assertMatchesRegularExpression('~\bnone\b|opacity|fade~', $block, $path . ': under reduced motion only fades or nothing');
        }
    }

    /** The decision for GEST-04's pressed layer (see the class comment): a colour fade only, so no block */
    public function testThePressedLayerOnlyFades(): void
    {
        self::assertFalse($this->moves('assets/styles/_press-states.scss'), '_press-states.scss now moves something: give it a prefers-reduced-motion block');
        self::assertNotSame([], $this->motionDeclarations('assets/styles/_press-states.scss'), 'the fade is still there');
    }

    /** README §4.13 G8: sheets in ≤ 300 ms ease-out, out ≈ 200 ms - the CSS and the scripts that drive them */
    public function testSheetsComeInWithin300AndGoIn200Milliseconds(): void
    {
        $tokens = $this->localVariables('assets/styles/_bottom-sheet.scss');
        self::assertLessThanOrEqual(300, array_sum($this->times($tokens['sheet-in'] ?? '')), '$sheet-in');
        self::assertSame([200], $this->times($tokens['sheet-out'] ?? ''), '$sheet-out');

        // The dynamic modal's sheets (achievement, result detail, Help) take both tokens
        $modal = $this->code('assets/styles/_dynamic-modal.scss');
        self::assertStringContainsString('transition: transform $sheet-in $sheet-ease;', $modal);
        self::assertStringContainsString('transition-duration: $sheet-out;', $modal);

        // The player card's sheet rises in 300 ms, its scrim goes in 200 ms (the box slides out in OUT_MS, below)
        $card = $this->compact($this->code('assets/styles/players/_card.scss'));
        self::assertStringContainsString('animation:players-card-rise .3s', $card);
        self::assertStringContainsString('transition:opacity .2s ease-in', $card);

        // The Menu panel: in 250 ms, out 200 ms (README §4.12)
        $menu = $this->compact($this->code('assets/styles/_nav-menu.scss'));
        self::assertStringContainsString('animation:menu-layer-in 250ms', $menu);
        self::assertStringContainsString('animation:menu-layer-out 200ms', $menu);

        // Scripted motion: the swipe's slide out and spring back, the card's and the Menu panel's way out - and each
        // script reads prefers-reduced-motion
        foreach (
            [
                ['assets/sheet_drag.js', 'OUT_MS', 200, 'assets/controllers/bottom_sheet_controller.js'],
                ['assets/sheet_drag.js', 'SPRING_MS', 200, 'assets/controllers/bottom_sheet_controller.js'],
                ['assets/player_card_layer.js', 'OUT_MS', 200, 'assets/player_card_layer.js'],
                ['assets/controllers/menu_layer_controller.js', 'OUT_MS', 200, 'assets/controllers/menu_layer_controller.js'],
            ] as [$file, $constant, $expected, $reader]
        ) {
            self::assertSame(1, preg_match('~const ' . $constant . ' = (\d+);~', $this->read($file), $match), $file . ' ' . $constant);
            self::assertSame($expected, (int) $match[1], $file . ' ' . $constant);
            self::assertStringContainsString('prefers-reduced-motion: reduce', $this->read($reader), $reader);
        }
    }

    /**
     * Reduced motion → instant scroll: the page (app.scss) and the navigation scroll smoothly only for visitors who did
     * not ask for less motion. Scripts pass `behavior` themselves (GEST-03's scroll to the top: TabReselectScriptTest).
     */
    public function testSmoothScrollingOnlyWithoutReducedMotion(): void
    {
        $found = 0;

        foreach (['assets/styles/app.scss', ...self::PARTIALS] as $path) {
            $code = ScssReader::withoutComments($this->read($path));
            preg_match_all('~(?<![\w-])scroll-behavior\s*:\s*smooth~', $code, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [, $offset]) {
                $found++;
                $preludes = implode(' | ', $this->enclosingPreludes($code, $offset));
                self::assertStringContainsString('prefers-reduced-motion: no-preference', $preludes, $path . ': scroll-behavior: smooth inside ' . $preludes);
            }
        }

        // html { scroll-behavior: smooth } stays (README §4.13 G8; GEST-15 switches it off during restores)
        self::assertSame(1, preg_match('~@media \(prefers-reduced-motion: no-preference\) \{\s*html \{\s*scroll-behavior: smooth;\s*\}\s*\}~', $this->read('assets/styles/app.scss')));
        self::assertGreaterThanOrEqual(1, $found);
    }

    /** README §4.13 G7: fixed bars over a scrolling document - never main { overflow: auto } or another app-shell box */
    public function testTheDocumentStaysTheScroller(): void
    {
        $compound = '(?::root|html|body|main|#main-content)(?:\[[^\]]*\]|\.[\w-]+|:[\w-]+(?:\([^)]*\))?)*';
        $root = '~^' . $compound . '(?:\s*>?\s*' . $compound . ')*$~';
        $scroller = '~(^|;)overflow(-y)?:\s*(auto|scroll|overlay)\b~';
        $checked = 0;

        foreach ($this->files('assets/styles', ['scss']) as $path => $code) {
            foreach (ScssReader::rules($code) as $selector => $declarations) {
                foreach (array_map('trim', explode(',', $selector)) as $one) {
                    if (preg_match($root, $one) === 1) {
                        $checked++;
                        self::assertDoesNotMatchRegularExpression($scroller, $this->compact($declarations), $path . ': ' . $one . ' must not scroll - the document is the scroller');
                    }
                }
            }
        }

        self::assertGreaterThan(5, $checked, 'the html / body / main rules were found');

        // The critical CSS (base.html.twig's <style> and the navigation partial) and the markup itself
        foreach (['templates/base.html.twig', self::CRITICAL] as $path) {
            $css = (string) preg_replace('~\{#.*?#\}~s', '', $this->read($path));
            self::assertDoesNotMatchRegularExpression('~(?:^|[}\s,])' . $compound . '\{[^}]*overflow(?:-y)?\s*:\s*(?:auto|scroll|overlay)~', $css, $path);
        }

        foreach ($this->files('templates', ['twig']) as $path => $code) {
            self::assertDoesNotMatchRegularExpression('~<(?:main|body)\b[^>]*style="[^"]*overflow~', $code, $path);
        }
    }

    /**
     * The transition / animation declarations of a source: [property, value with the SCSS variables resolved]
     *
     * @return list<array{string, string}>
     */
    private function motionDeclarations(string $path): array
    {
        $variables = $this->variables($path);
        preg_match_all('~(?<![\w-])((?:transition|animation)(?:-duration|-delay|-name)?)\s*:\s*((?:#\{[^}]*\}|[^;{}])+)~', $this->code($path), $matches, PREG_SET_ORDER);

        $declarations = [];

        foreach ($matches as [, $property, $value]) {
            $declarations[] = [$property, $this->resolve(trim($value), $variables)];
        }

        return $declarations;
    }

    /** Does the source move something: an animation, or a transition of anything but a fade? */
    private function moves(string $path): bool
    {
        foreach ($this->motionDeclarations($path) as [$property, $value]) {
            if ($value === 'none' || $value === 'initial' || $value === '') {
                continue;
            }

            if (str_starts_with($property, 'animation')) {
                return true;
            }

            if ($property !== 'transition') {
                continue;
            }

            foreach ($this->items($value) as $item) {
                $what = strtok(trim($item), " \t") ?: '';

                if ($what === 'none' || array_sum($this->times($item)) === 0) {
                    continue;
                }

                if (!in_array($what, self::FADES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The comma-separated items of a value (commas inside parentheses stay)
     *
     * @return list<string>
     */
    private function items(string $value): array
    {
        $items = [];
        $depth = 0;
        $current = '';

        foreach (str_split($value) as $char) {
            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);

            if ($char === ',' && $depth === 0) {
                $items[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $items[] = trim($current);

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * Every time value in milliseconds
     *
     * @return list<int>
     */
    private function times(string $value): array
    {
        preg_match_all('~(?<![\w.#$-])(\d*\.?\d+)(ms|s)\b~', $value, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): int => (int) round((float) $match[1] * ($match[2] === 's' ? 1000 : 1)), $matches);
    }

    /**
     * SCSS variables a source may read: the shared sources, then its own (its own win)
     *
     * @return array<string, string>
     */
    private function variables(string $path): array
    {
        $variables = [];

        foreach (self::VARIABLE_SOURCES as $source) {
            $variables = [...$variables, ...$this->localVariables($source)];
        }

        return [...$variables, ...$this->localVariables($path)];
    }

    /**
     * @return array<string, string>
     */
    private function localVariables(string $path): array
    {
        preg_match_all('~\$([\w-]+)\s*:\s*([^;{}]+?)\s*(?:!default)?\s*;~', $this->code($path), $matches, PREG_SET_ORDER);
        $variables = [];

        foreach ($matches as [, $name, $value]) {
            $variables[$name] = trim($value);
        }

        return $variables;
    }

    /**
     * `$name` and `#{$name}` replaced by their values (a mixin's parameter stays as it is - it holds no time)
     *
     * @param array<string, string> $variables
     */
    private function resolve(string $value, array $variables): string
    {
        for ($i = 0; $i < 5 && str_contains($value, '$'); $i++) {
            $value = (string) preg_replace_callback(
                // One group for both spellings (a branch reset)
                '~(?|#\{\$([\w-]+)\}|\$([\w-]+))~',
                static fn (array $match): string => $variables[$match[1]] ?? $match[0],
                $value,
            );
        }

        return $value;
    }

    /**
     * The bodies of the `@media … (prefers-reduced-motion: reduce)` blocks
     *
     * @return list<string>
     */
    private function reducedMotionBlocks(string $code): array
    {
        preg_match_all('~@media(?:#\{[^}]*\}|[^{;])*prefers-reduced-motion:\s*reduce(?:#\{[^}]*\}|[^{;])*\{~', $code, $matches, PREG_OFFSET_CAPTURE);
        $blocks = [];

        foreach ($matches[0] as [$prelude, $offset]) {
            $start = $offset + strlen($prelude);
            $depth = 1;

            for ($i = $start, $length = strlen($code); $i < $length && $depth > 0; $i++) {
                $depth += $code[$i] === '{' ? 1 : ($code[$i] === '}' ? -1 : 0);
            }

            $blocks[] = substr($code, $start, $i - $start - 1);
        }

        return $blocks;
    }

    /**
     * The preludes of the blocks around an offset, innermost first
     *
     * @return list<string>
     */
    private function enclosingPreludes(string $code, int $offset): array
    {
        $preludes = [];
        $depth = 0;

        for ($i = $offset; $i >= 0; $i--) {
            if ($code[$i] === '}') {
                $depth++;
            } elseif ($code[$i] === '{') {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }

                $before = substr($code, 0, $i);
                $from = max((int) strrpos($before, ';'), (int) strrpos($before, '{'), (int) strrpos($before, '}'));
                $preludes[] = trim(substr($before, $from + 1));
            }
        }

        return $preludes;
    }

    /** A source without its comments: a stylesheet, or the <style> a script injects */
    private function code(string $path): string
    {
        $code = $this->read($path);

        if (str_ends_with($path, '.js')) {
            self::assertSame(1, preg_match('~style\.textContent = `(.*?)`;~s', $code, $match), $path . ': the injected <style>');
            $code = $match[1];
        }

        return ScssReader::withoutComments($code);
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
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
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
