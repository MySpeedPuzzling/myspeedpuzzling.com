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
 */
final class ChromeWhitespace implements NodeVisitorInterface
{
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

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof TextNode && self::appliesTo((string) $node->getSourceContext()?->getName())) {
            $data = $node->getAttribute('data');
            assert(is_string($data));
            $node->setAttribute('data', self::collapse($data));
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
