<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Email;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Symfony\Component\Mime\HtmlToTextConverter\HtmlToTextConverterInterface;

/**
 * The plain-text part of every templated e-mail (wired as `twig.mailer.html_to_text_converter`). Symfony's default
 * is strip_tags(): every URL gone and lines glued together ("happy puzzling!Your MySpeedPuzzling team") - a sign-in
 * or password reset e-mail read as text could not be used at all.
 *
 * - links stay usable: "text (URL)", just the URL when the text is the URL, the address for mailto:
 * - a button (Inky's table.button) is a paragraph of its own: "Sign in: URL"
 * - paragraphs, headings, lists and tables keep their breaks, <br> is a new line, list items start with "- "
 * - nothing hidden is written: <head>, <style>, the preheader, anything with display:none or mso-hide:all
 * - a logo linked next to a text link to the same page writes no second URL
 *
 * In-house on purpose (docs/features/transactional-emails.md) - a few rules over PHP's HTML5 parser instead of
 * another dependency.
 */
final readonly class EmailHtmlToTextConverter implements HtmlToTextConverterInterface
{
    private const array SKIPPED_ELEMENTS = [
        'head', 'title', 'style', 'script', 'template', 'meta', 'link', 'base', 'iframe', 'object', 'embed', 'svg',
        'canvas', 'audio', 'video', 'map', 'select', 'option', 'datalist', 'input', 'textarea',
    ];

    private const array PARAGRAPH_ELEMENTS = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'dl', 'table', 'center', 'blockquote', 'hr', 'pre',
        'figure', 'address', 'section', 'article', 'header', 'footer', 'main', 'nav', 'aside', 'form', 'fieldset',
    ];

    private const array LINE_ELEMENTS = [
        'div', 'tr', 'td', 'th', 'caption', 'thead', 'tbody', 'tfoot', 'dt', 'dd', 'li', 'figcaption', 'legend',
        'details', 'summary',
    ];

    public function convert(string $html, string $charset): string
    {
        $document = $this->parse($html, $charset);

        if ($document === null) {
            return self::fallback($html);
        }

        $root = $document->body ?? $document->documentElement;

        if ($root === null) {
            return '';
        }

        $buffer = new PlainTextBuffer();
        $this->renderChildren($root, $buffer, $this->hrefsOfTextLinks($root));

        return $buffer->toString();
    }

    private function parse(string $html, string $charset): null|HTMLDocument
    {
        $encoding = $charset !== '' ? $charset : null;

        try {
            return HTMLDocument::createFromString($html, LIBXML_NOERROR, $encoding);
        } catch (\ValueError) {
            // An encoding name the parser does not know - let it sniff the document instead
        } catch (\Throwable) {
            return null;
        }

        try {
            return HTMLDocument::createFromString($html, LIBXML_NOERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, true> $textLinkHrefs
     */
    private function renderChildren(Node $node, PlainTextBuffer $buffer, array $textLinkHrefs): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                $buffer->text($child->data);
            } elseif ($child instanceof Element) {
                $this->renderElement($child, $buffer, $textLinkHrefs);
            }
            // Comments (Outlook's conditional ones included), processing instructions: nothing to read
        }
    }

    /**
     * @param array<string, true> $textLinkHrefs
     */
    private function renderElement(Element $element, PlainTextBuffer $buffer, array $textLinkHrefs): void
    {
        $name = strtolower($element->localName);

        if (in_array($name, self::SKIPPED_ELEMENTS, true) || self::isHidden($element)) {
            return;
        }

        switch ($name) {
            case 'br':
                $buffer->lineBreak();

                return;

            case 'img':
                $buffer->text(trim($element->getAttribute('alt') ?? ''));

                return;

            case 'a':
                $this->renderLink($element, $buffer, $textLinkHrefs);

                return;

            case 'ul':
            case 'ol':
                $this->renderList($element, $buffer, $textLinkHrefs, $name === 'ol');

                return;

            case 'li':
                // An item outside of any list still reads as one
                $this->renderListItem($element, $buffer, $textLinkHrefs, '- ');

                return;
        }

        $level = match (true) {
            in_array($name, self::PARAGRAPH_ELEMENTS, true) => PlainTextBuffer::PARAGRAPH,
            in_array($name, self::LINE_ELEMENTS, true) => PlainTextBuffer::LINE,
            default => 0,
        };

        if ($level > 0) {
            $buffer->block($level);
        }

        $this->renderChildren($element, $buffer, $textLinkHrefs);

        if ($level > 0) {
            $buffer->block($level);
        }
    }

    /**
     * @param array<string, true> $textLinkHrefs
     */
    private function renderLink(Element $link, PlainTextBuffer $buffer, array $textLinkHrefs): void
    {
        $text = $this->inlineText($link);
        $href = trim($link->getAttribute('href') ?? '');

        if (self::isButton($link)) {
            $line = self::linkLine($text, $href, asButton: true);

            if ($line !== '') {
                $buffer->block(PlainTextBuffer::PARAGRAPH);
                $buffer->text($line);
                $buffer->block(PlainTextBuffer::PARAGRAPH);
            }

            return;
        }

        // A linked logo beside a text link to the same page: the text link already carries the URL
        if ($text === '' && isset($textLinkHrefs[$href])) {
            return;
        }

        $buffer->text(self::linkLine($text, $href, asButton: false));
    }

    /**
     * @param array<string, true> $textLinkHrefs
     */
    private function renderList(Element $list, PlainTextBuffer $buffer, array $textLinkHrefs, bool $ordered): void
    {
        // A list nested in an item follows the item's line, a top-level one is a paragraph
        $level = $list->closest('li') !== null ? PlainTextBuffer::LINE : PlainTextBuffer::PARAGRAPH;
        $buffer->block($level);
        $number = 0;

        foreach ($list->childNodes as $child) {
            if ($child instanceof Element && strtolower($child->localName) === 'li') {
                if (self::isHidden($child)) {
                    continue;
                }

                $number++;
                $this->renderListItem($child, $buffer, $textLinkHrefs, $ordered ? $number . '. ' : '- ');
            } elseif ($child instanceof Element) {
                $this->renderElement($child, $buffer, $textLinkHrefs);
            } elseif ($child instanceof Text) {
                $buffer->text($child->data);
            }
        }

        $buffer->block($level);
    }

    /**
     * The item is written on its own, then indented under its marker - a <br> or a nested list inside it stays
     * aligned with the item's first line.
     *
     * @param array<string, true> $textLinkHrefs
     */
    private function renderListItem(Element $item, PlainTextBuffer $buffer, array $textLinkHrefs, string $marker): void
    {
        $itemBuffer = new PlainTextBuffer();
        $this->renderChildren($item, $itemBuffer, $textLinkHrefs);
        $text = $itemBuffer->toString();

        if ($text === '') {
            return;
        }

        $indent = str_repeat(' ', mb_strlen($marker));
        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            $lines[$index] = match (true) {
                $index === 0 => $marker . $line,
                $line === '' => '',
                default => $indent . $line,
            };
        }

        $buffer->block(PlainTextBuffer::LINE);
        $buffer->preformatted(implode("\n", $lines));
        $buffer->block(PlainTextBuffer::LINE);
    }

    /**
     * A link's visible words on one line - image alts included, hidden parts left out.
     */
    private function inlineText(Node $node): string
    {
        return trim(PlainTextBuffer::normalizeWhitespace($this->rawInlineText($node)));
    }

    private function rawInlineText(Node $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                $text .= $child->data;
            } elseif ($child instanceof Element) {
                $name = strtolower($child->localName);

                if (in_array($name, self::SKIPPED_ELEMENTS, true) || self::isHidden($child)) {
                    continue;
                }

                // A space only where the layout separates words: inline tags must not split <b>Re</b>set
                $separated = $name === 'img' || $name === 'br'
                    || in_array($name, self::PARAGRAPH_ELEMENTS, true)
                    || in_array($name, self::LINE_ELEMENTS, true);
                $inner = $name === 'img' ? trim($child->getAttribute('alt') ?? '') : $this->rawInlineText($child);

                $text .= $separated ? ' ' . $inner . ' ' : $inner;
            }
        }

        return $text;
    }

    private static function linkLine(string $text, string $href, bool $asButton): string
    {
        if ($href === '' || str_starts_with($href, '#') || preg_match('~^\s*javascript:~i', $href) === 1) {
            return $text;
        }

        $target = $href;

        if (preg_match('~^(mailto|tel):([^?]*)~i', $href, $matches) === 1) {
            $target = rawurldecode($matches[2]);
        }

        if ($text === '' || self::sameTarget($text, $target)) {
            return $target;
        }

        if ($asButton) {
            // "Sign in: URL", but "Ready? URL" - no colon after a sentence that already ends
            return preg_match('/[:.!?…]$/u', $text) === 1 ? $text . ' ' . $target : $text . ': ' . $target;
        }

        return $text . ' (' . $target . ')';
    }

    private static function sameTarget(string $text, string $target): bool
    {
        $normalize = static fn (string $value): string => rtrim(
            preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', trim($value)) ?? $value,
            '/',
        );

        return $normalize($text) === $normalize($target);
    }

    private static function isButton(Element $link): bool
    {
        if ($link->classList->contains('button')) {
            return true;
        }

        for ($ancestor = $link->parentElement; $ancestor !== null; $ancestor = $ancestor->parentElement) {
            if (strtolower($ancestor->localName) === 'table' && $ancestor->classList->contains('button')) {
                return true;
            }
        }

        return false;
    }

    private static function isHidden(Element $element): bool
    {
        if ($element->hasAttribute('hidden') || $element->classList->contains('preheader')) {
            return true;
        }

        $style = $element->getAttribute('style');

        return $style !== null && preg_match('~display\s*:\s*none|mso-hide\s*:\s*all~i', $style) === 1;
    }

    /**
     * @return array<string, true>
     */
    private function hrefsOfTextLinks(Element $root): array
    {
        $hrefs = [];

        foreach ($root->getElementsByTagName('a') as $link) {
            $href = trim($link->getAttribute('href') ?? '');

            if ($href !== '' && !self::isHidden($link) && $this->inlineText($link) !== '') {
                $hrefs[$href] = true;
            }
        }

        return $hrefs;
    }

    /**
     * When the document cannot be parsed at all: still no tags, no styles, and breaks where blocks end.
     */
    private static function fallback(string $html): string
    {
        $html = preg_replace('~<(head|style|script|title)\b.*?</\1>~is', '', $html) ?? $html;
        $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
        $html = preg_replace('~</(p|div|h[1-6]|li|tr|table|ul|ol|center|blockquote)>~i', "\n\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = array_map(
            static fn (string $line): string => trim(PlainTextBuffer::normalizeWhitespace($line)),
            explode("\n", $text),
        );

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '', "\n");
    }
}
