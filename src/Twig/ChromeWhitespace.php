<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Twig\Environment;
use Twig\Node\Node;
use Twig\Node\TextNode;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * The chrome's HTML weight (docs/features/navigation README §4.12 "Weight", implementation plan risk 8): the
 * templates every page renders around its content keep their indentation in the source and lose it in the output.
 *
 * At compile time - once per template, nothing at request time - every run of whitespace holding a line break in the
 * literal text of these templates becomes one line break, and the indentation left at either end of a text node (Twig
 * drops the line break after a block tag, not the spaces after it) one space. Only the template's own text changes,
 * never what an expression prints (a translation, a URL, a JSON attribute). The browser reads both the same: HTML
 * collapses any whitespace run into one space, and none of these templates has a <pre>, a <textarea> or an element
 * styled white-space: pre* around literal text, nor literal text Twig or a script reads as data - a {% trans %} body
 * (the message id), a {% set %} capture, {% verbatim %}, {% apply %}, a JS template literal (ChromeWhitespaceTest).
 * A change to this class recompiles the cached templates (ChromeWhitespaceTwigExtension::getLastModified()). The line
 * break, not nothing, keeps every inline script's line endings, so a // comment still ends and automatic semicolon
 * insertion sees what it saw before.
 *
 * A template rendered around the content of every page belongs here; a partial shared with page content does not
 * (its bytes are not the chrome's) - with one exception: the barcode scanner's HUD (_barcode_scanner_hud.html.twig),
 * which the chrome renders on every member page (the page search's camera, navigation/_search_camera.html.twig) and
 * which is most of the indentation left in a member's chrome (~450 B; the avatar's is ~25 B). On its three page
 * scanners (the add form, the scanner panel, multiscan) the collapse changes no element and no attribute value -
 * flex containers and buttons, nothing whitespace-sensitive (ChromeWhitespaceTest checks it like every listed one).
 *
 * The navigation's critical CSS (navigation/_critical_css.html.twig: plain CSS, one rule per line, no Twig output) is
 * compacted instead (compactCss()): the line breaks between its rules go, `@media (` loses its space, and neighbouring
 * rules with the same media query - or the same selector - become one. The same rules in the same order, so the
 * cascade is the one the source reads; the tests keep reading the source's lines.
 */
final class ChromeWhitespace implements NodeVisitorInterface
{
    /** Compacted as CSS (compactCss()) */
    public const string CRITICAL_CSS = 'navigation/_critical_css.html.twig';

    /** Exact template names, and prefixes ending in "/" */
    public const array TEMPLATES = [
        'base.html.twig',
        '_head_flags.html.twig',
        'navigation/',
        'components/GlobalSearch.html.twig',
        'inbox/_link.html.twig',
        '_footer.html.twig',
        '_footer_newsletter.html.twig',
        '_language_menu.html.twig',
        '_barcode_scanner_hud.html.twig',
    ];

    public static function appliesTo(string $template): bool
    {
        foreach (self::TEMPLATES as $name) {
            if ($template === $name || (str_ends_with($name, '/') && str_starts_with($template, $name))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A whitespace run with a line break → one line break; the indentation left at either end of a text node (Twig eats
     * the line break after a block tag, not the spaces after it) → one space
     */
    public static function collapse(string $text): string
    {
        $text = (string) preg_replace('~[ \t\r]*\n[ \t\r\n]*~', "\n", $text);

        return (string) preg_replace('~\A[ \t]{2,}|[ \t]{2,}\z~', ' ', $text);
    }

    /**
     * Plain CSS written one rule per line → the same rules without the line breaks between them: `@media (` → `@media(`,
     * and two neighbouring rules with the same @media query, or the same selector, one rule (bodies in order). Other
     * at-rules (@font-face, @property, @supports, @keyframes) are never merged. Strings and comments are copied as they are.
     */
    public static function compactCss(string $css): string
    {
        /** @var list<array{string, string}> $rules prelude, body */
        $rules = [];
        $length = strlen($css);
        $start = 0;
        $depth = 0;
        $open = null;

        for ($index = 0; $index < $length; $index++) {
            $char = $css[$index];

            if ($char === '"' || $char === "'") {
                // A string (an escaped quote does not end it)
                for ($index++; $index < $length && $css[$index] !== $char; $index++) {
                    $index += $css[$index] === '\\' ? 1 : 0;
                }
            } elseif ($char === '/' && ($css[$index + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $index + 2);
                $index = $end === false ? $length : $end + 1;
            } elseif ($char === '{') {
                if ($depth === 0) {
                    $open = $index;
                }

                $depth++;
            } elseif ($char === '}' && $depth > 0) {
                $depth--;

                if ($depth === 0 && $open !== null) {
                    $rules[] = [self::oneLine(substr($css, $start, $open - $start)), self::oneLine(substr($css, $open + 1, $index - $open - 1))];
                    $start = $index + 1;
                }
            }
        }

        $tail = self::oneLine(substr($css, $start));
        $merged = [];

        foreach ($rules as [$prelude, $body]) {
            $prelude = (string) preg_replace('~^@media\s+\(~', '@media(', $prelude);
            $last = array_key_last($merged);

            if ($last !== null && $merged[$last][0] === $prelude && (str_starts_with($prelude, '@media') || !str_starts_with($prelude, '@'))) {
                $merged[$last][1] .= (str_starts_with($prelude, '@') || str_ends_with($merged[$last][1], ';') ? '' : ';') . $body;

                continue;
            }

            $merged[] = [$prelude, $body];
        }

        return implode('', array_map(static fn (array $rule): string => $rule[0] . '{' . $rule[1] . '}', $merged)) . $tail;
    }

    /** A line break inside a rule (none in the source today) reads as a space; around the rule it goes */
    private static function oneLine(string $css): string
    {
        return trim((string) preg_replace('~\s*\n\s*~', ' ', $css));
    }

    public function enterNode(Node $node, Environment $env): Node
    {
        $template = (string) $node->getSourceContext()?->getName();

        if ($node instanceof TextNode && self::appliesTo($template)) {
            $data = $node->getAttribute('data');
            assert(is_string($data));
            $node->setAttribute('data', $template === self::CRITICAL_CSS ? self::compactCss($data) : self::collapse($data));
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    public function getPriority(): int
    {
        return 0;
    }
}
