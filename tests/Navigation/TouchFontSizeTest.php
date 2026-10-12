<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * F-04's touch rule (assets/styles/_nav-tokens.scss) lifts every text-entry control to 16 px under `(pointer: coarse)`
 * and must never shrink one that is bigger (phase F review F1: it took the live-results referee fields from 32 px to
 * 16 px). A control can be bigger in two ways:
 * - its own rule: every stylesheet rule (SCSS under assets/styles, the templates' inline <style>) that gives a
 *   text-entry control more than 16 px names, in the selector's last part, a class of $nav-own-size-controls - the
 *   list the rule leaves out;
 * - inherited (no .form-control / .form-select): the second rule keeps 1em.
 * The browser side (computed sizes with and without the rule on the pages with custom input sizes) is
 * var/navigation-delivery/scripts/checks/F-04.py.
 */
final class TouchFontSizeTest extends TestCase
{
    private const string TOKENS = 'assets/styles/_nav-tokens.scss';

    // Bootstrap's own -lg sizes live in node_modules (not scanned here) - listed, never stale
    private const array BOOTSTRAP_LARGE = ['form-control-lg', 'form-select-lg', 'input-group-lg'];

    // Sizes that are 16 px or less however they are written (variables of the theme, keywords)
    private const array SMALL_VALUES = ['inherit', 'initial', 'unset', 'smaller', '100%', '1em', '$font-size-sm', '$font-size-xs', '$font-size-base', '$input-font-size', '$input-font-size-sm', '$form-select-font-size-sm', '$small-font-size'];

    // input types that take no text (the rule's own :not() list)
    private const array NO_TEXT_TYPES = ['checkbox', 'radio', 'range', 'file', 'color', 'button', 'submit', 'reset', 'image', 'hidden'];

    public function testTheTouchRuleLeavesOutTheOwnSizeListAndKeepsInheritedSizes(): void
    {
        $tokens = ScssReader::withoutComments($this->read(self::TOKENS));

        self::assertMatchesRegularExpression('~:is\(#\{\$nav-text-entry\}\):not\(#\{\$nav-own-size-controls\}\)\s*\{\s*font-size:\s*max\(1rem,\s*16px\)~', $tokens);
        self::assertMatchesRegularExpression('~:is\(#\{\$nav-text-entry\}\):not\(\.form-control,\s*\.form-select,\s*#\{\$nav-own-size-controls\}\)\s*\{\s*font-size:\s*max\(1rem,\s*16px,\s*1em\)~', $tokens);
        // The inherited-size rule comes later, at the same specificity
        self::assertGreaterThan(strpos($tokens, ':not(#{$nav-own-size-controls})'), (int) strpos($tokens, ':not(.form-control, .form-select, #{$nav-own-size-controls})'));
        self::assertNotSame([], $this->ownSizeClasses());
    }

    public function testEveryControlRuleAbove16PxIsLeftOutOfTheTouchRule(): void
    {
        $controlClasses = $this->textEntryControlClasses();
        $excluded = $this->ownSizeClasses();
        $offenders = [];
        $unknown = [];
        $justified = [];

        foreach ($this->stylesheets() as $source => $code) {
            foreach (ScssReader::rules($code) as $selector => $declarations) {
                $last = $this->lastCompound($selector);

                if ($last === null || !$this->targetsTextEntryControl($last, $controlClasses)) {
                    continue;
                }

                foreach ($this->fontSizes($declarations) as $value) {
                    $size = $this->classify($value);

                    if ($size === 'small') {
                        continue;
                    }

                    if ($size === 'unknown') {
                        $unknown[] = sprintf('%s %s { font-size: %s } - add the value to SMALL_VALUES, or give it in px/rem', $source, $selector, $value);
                        continue;
                    }

                    $leftOut = array_values(array_intersect($this->classesOf($last), $excluded));

                    if ($leftOut === []) {
                        $offenders[] = sprintf('%s %s { font-size: %s }', $source, $selector, $value);
                    } else {
                        array_push($justified, ...$leftOut);
                    }
                }
            }
        }

        self::assertSame([], $unknown, 'Font sizes on text-entry controls that this guard cannot tell');
        self::assertSame([], $offenders, 'Text-entry controls above 16 px that the touch rule would shrink to 16 px under (pointer: coarse): add the class to $nav-own-size-controls in ' . self::TOKENS);

        // No stale entry: every class of the list (besides Bootstrap's own -lg) is there for a rule found above
        $stale = array_diff($excluded, self::BOOTSTRAP_LARGE, $justified);
        self::assertSame([], array_values($stale), 'Classes in $nav-own-size-controls without a rule above 16 px');
        // The live results referee fields are the reason this guard exists
        self::assertContains('lr-find-input', $justified);
        self::assertContains('lr-time-input', $justified);
        self::assertContains('auth-code-input', $justified);
    }

    /**
     * @return list<string>
     */
    private function ownSizeClasses(): array
    {
        $tokens = ScssReader::withoutComments($this->read(self::TOKENS));
        self::assertSame(1, preg_match('~\$nav-own-size-controls:\s*"([^"]+)"\s*;~', $tokens, $match), '$nav-own-size-controls is missing');
        preg_match_all('~\.([a-zA-Z][\w-]*)~', $match[1], $classes);

        return array_values(array_unique($classes[1]));
    }

    /**
     * Classes put on a text-entry control in the templates (literal class attributes of input/select/textarea, the
     * form helpers' attr classes) and the form types (`'class' => …` under src/FormType).
     *
     * @return array<string, true>
     */
    private function textEntryControlClasses(): array
    {
        $classes = [];

        foreach ($this->files('templates', ['twig']) as $code) {
            preg_match_all('~<(input|select|textarea)\b([^>]*)>~s', $code, $tags, PREG_SET_ORDER);

            foreach ($tags as $tag) {
                if ($tag[1] === 'input' && preg_match('~\btype="([a-z]+)"~i', $tag[2], $type) === 1 && in_array(strtolower($type[1]), self::NO_TEXT_TYPES, true)) {
                    continue;
                }

                if (preg_match('~\bclass="([^"]*)"~', $tag[2], $class) === 1) {
                    foreach ($this->tokens($class[1]) as $token) {
                        $classes[$token] = true;
                    }
                }
            }

            preg_match_all('~form_(?:widget|row)\((?:[^()]|\([^()]*\))*?\bclass[\'"]?\s*:\s*[\'"]([^\'"]+)[\'"]~s', $code, $attrs);

            foreach ($attrs[1] as $value) {
                foreach ($this->tokens($value) as $token) {
                    $classes[$token] = true;
                }
            }
        }

        foreach ($this->files('src/FormType', ['php']) as $code) {
            preg_match_all('~[\'"]class[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]~', $code, $attrs);

            foreach ($attrs[1] as $value) {
                foreach ($this->tokens($value) as $token) {
                    $classes[$token] = true;
                }
            }
        }

        // The form helpers' and form types' attr classes include submit buttons - never a text-entry control
        $classes = array_filter($classes, static fn (string $class): bool => preg_match('~^btn(-|$)~', $class) !== 1, ARRAY_FILTER_USE_KEY);

        self::assertArrayHasKey('lr-time-input', $classes, 'The template scan lost the live results time field');
        self::assertArrayHasKey('auth-code-input', $classes, 'The template scan lost the sign-in code field');

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $classAttribute): array
    {
        preg_match_all('~(?<![\w-])[a-z][a-z0-9_-]*[a-z0-9](?![\w-])~', $classAttribute, $tokens);

        return $tokens[0];
    }

    /**
     * Every stylesheet: the SCSS of the site (the touch rule itself excluded) and each inline <style> of a template.
     *
     * @return array<string, string> source => CSS/SCSS code
     */
    private function stylesheets(): array
    {
        $sheets = [];

        foreach ($this->files('assets/styles', ['scss', 'css']) as $path => $code) {
            if ($path !== self::TOKENS) {
                $sheets[$path] = $code;
            }
        }

        foreach ($this->files('templates', ['twig']) as $path => $code) {
            preg_match_all('~<style\b[^>]*>(.*?)</style>~s', $code, $styles);

            foreach ($styles[1] as $index => $css) {
                // Twig inside a style block is no CSS
                $sheets[$path . ' <style>#' . ($index + 1)] = (string) preg_replace('~\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}~s', '', $css);
            }
        }

        self::assertArrayHasKey('templates/live_results/live_results.html.twig <style>#1', $sheets);

        return $sheets;
    }

    /**
     * The compound the declarations apply to (the selector's last part), null for pseudo-elements (::placeholder sizes
     * the placeholder, not the field).
     */
    private function lastCompound(string $selector): null|string
    {
        $parts = preg_split('/\s*[\s>+~]\s*(?![^(]*\))/', trim($selector));
        $last = is_array($parts) ? (string) end($parts) : $selector;

        return str_contains($last, '::') ? null : $last;
    }

    /**
     * @param array<string, true> $controlClasses
     */
    private function targetsTextEntryControl(string $compound, array $controlClasses): bool
    {
        // A type selector (input, select, textarea) or Bootstrap's control classes
        $withoutFunctions = (string) preg_replace('~\([^()]*\)~', '', $compound);

        if (preg_match('~^(input|select|textarea)(?![\w-])~', $withoutFunctions) === 1) {
            return true;
        }

        foreach ($this->classesOf($compound) as $class) {
            if ($class === 'form-control' || $class === 'form-select' || isset($controlClasses[$class])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function classesOf(string $compound): array
    {
        // Classes of the compound itself, not of :not(…) / :has(…) arguments
        $own = (string) preg_replace('~:(not|has)\([^()]*\)~', '', $compound);
        preg_match_all('~\.([a-zA-Z][\w-]*)~', $own, $classes);

        return $classes[1];
    }

    /**
     * The font sizes a rule's own declarations set (font-size, and the size in a `font` shorthand).
     *
     * @return list<string>
     */
    private function fontSizes(string $declarations): array
    {
        $sizes = [];

        foreach (explode(';', $declarations) as $declaration) {
            if (preg_match('~^\s*font-size\s*:\s*(.+?)\s*$~s', $declaration, $match) === 1) {
                $sizes[] = trim(str_replace('!important', '', $match[1]));
            } elseif (preg_match('~^\s*font\s*:\s*(.+?)\s*$~s', $declaration, $match) === 1) {
                $value = trim(str_replace('!important', '', $match[1]));
                $sizes[] = preg_match('~(?<![\w.-])(\d*\.?\d+(?:px|rem|em|%))(?:/|\s|$)~', $value, $size) === 1 ? $size[1] : $value;
            }
        }

        return $sizes;
    }

    /**
     * @return 'small'|'large'|'unknown' (small = 16 px or less)
     */
    private function classify(string $value): string
    {
        if (in_array($value, self::SMALL_VALUES, true) || str_starts_with($value, 'max(1rem, 16px')) {
            return 'small';
        }

        if (preg_match('~^(\d*\.?\d+)(px|rem|em)$~', $value, $match) === 1) {
            $px = (float) $match[1] * ($match[2] === 'px' ? 1 : 16);

            return $px > 16 ? 'large' : 'small';
        }

        // A font shorthand without a size (font: inherit) sets no bigger size
        return $value === 'inherit' ? 'small' : 'unknown';
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    /**
     * @param list<string> $extensions
     * @return array<string, string> path relative to the project => contents
     */
    private function files(string $directory, array $extensions): array
    {
        $files = [];
        $root = dirname(__DIR__, 2);

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
