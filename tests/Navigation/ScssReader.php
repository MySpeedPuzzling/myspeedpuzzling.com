<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

/**
 * A small SCSS/CSS reader for the navigation's stylesheet guards (NavLayoutCssTest, TouchFontSizeTest): rules by full
 * selector with their own declarations, comments stripped.
 */
final class ScssReader
{
    /**
     * A small SCSS reader: full selector => own declarations (incl. `@include …;` lines), the rules of one selector
     * concatenated. @media, @supports, @include … { } and @if/@else blocks are looked through; @mixin, @function and
     * @keyframes bodies are skipped.
     *
     * @return array<string, string>
     */
    public static function rules(string $code): array
    {
        $code = self::withoutComments($code);
        $rules = [];
        // One entry per open block: its selectors (null = not a style context), own declarations, whether it is a
        // style rule (or a transparent at-rule inside one)
        /** @var list<list<string>|null> $selectors */
        $selectors = [['']];
        /** @var list<string> $texts */
        $texts = [''];
        /** @var list<bool> $styles */
        $styles = [false];
        $buffer = '';
        $length = strlen($code);

        for ($i = 0; $i < $length; $i++) {
            $char = $code[$i];

            if (($char === '#' && ($code[$i + 1] ?? '') === '{') || $char === '"' || $char === "'") {
                $end = strpos($code, $char === '#' ? '}' : $char, $i + 1);
                $end = $end === false ? $length - 1 : $end;
                $buffer .= substr($code, $i, $end - $i + 1);
                $i = $end;
                continue;
            }

            $top = count($texts) - 1;

            if ($char === '{') {
                $prelude = trim($buffer);
                $buffer = '';
                $outer = $selectors[$top] ?? null;

                if (str_starts_with($prelude, '@')) {
                    $transparent = preg_match('~^@(media|supports|include|if|else|starting-style|container|layer)\b~', $prelude) === 1;
                    $selectors[] = $transparent ? $outer : null;
                    $texts[] = '';
                    $styles[] = $transparent && ($styles[$top] ?? false);
                    continue;
                }

                if ($outer === null) {
                    $selectors[] = null;
                    $texts[] = '';
                    $styles[] = false;
                    continue;
                }

                $resolved = [];
                foreach (self::splitSelectors($prelude) as $part) {
                    foreach ($outer as $parent) {
                        $full = str_contains($part, '&') ? str_replace('&', $parent, $part) : $parent . ' ' . $part;
                        $resolved[] = (string) preg_replace('~\s+~', ' ', trim($full));
                    }
                }

                $selectors[] = $resolved;
                $texts[] = '';
                $styles[] = true;
                continue;
            }

            if ($char === ';') {
                $texts[$top] .= trim($buffer) . ';';
                $buffer = '';
                continue;
            }

            if ($char === '}') {
                $own = $texts[$top] . (trim($buffer) === '' ? '' : trim($buffer) . ';');
                $buffer = '';
                $blockSelectors = $selectors[$top] ?? null;
                $isStyle = $styles[$top] ?? false;
                array_pop($selectors);
                array_pop($texts);
                array_pop($styles);

                if ($isStyle && $blockSelectors !== null) {
                    foreach ($blockSelectors as $selector) {
                        $rules[$selector] = ($rules[$selector] ?? '') . $own;
                    }
                }

                if ($texts === []) {
                    $selectors = [['']];
                    $texts = [''];
                    $styles = [false];
                }

                continue;
            }

            $buffer .= $char;
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private static function splitSelectors(string $prelude): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($prelude) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    public static function withoutComments(string $code): string
    {
        $out = '';
        $length = strlen($code);

        for ($i = 0; $i < $length; $i++) {
            $char = $code[$i];

            if ($char === '"' || $char === "'") {
                $end = strpos($code, $char, $i + 1);
                $out .= substr($code, $i, ($end === false ? $length : $end + 1) - $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            // An unquoted url(…) may hold `//` (scheme, base64)
            if ($char === 'u' && substr($code, $i, 4) === 'url(' && !in_array($code[$i + 4] ?? '', ['"', "'"], true)) {
                $end = strpos($code, ')', $i);
                $out .= substr($code, $i, ($end === false ? $length : $end + 1) - $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            if ($char === '/' && ($code[$i + 1] ?? '') === '*') {
                $end = strpos($code, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            if ($char === '/' && ($code[$i + 1] ?? '') === '/') {
                $end = strpos($code, "\n", $i);
                $i = $end === false ? $length : $end - 1;
                continue;
            }

            $out .= $char;
        }

        return $out;
    }
}
