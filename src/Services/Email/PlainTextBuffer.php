<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Email;

/**
 * The text being written by EmailHtmlToTextConverter - one per conversion. Blocks ask for a line or a paragraph
 * break, the strongest pending one wins once the next text comes, so nested layout tables never stack blank lines.
 *
 * @internal
 */
final class PlainTextBuffer
{
    public const int LINE = 1;
    public const int PARAGRAPH = 2;

    private string $text = '';
    private int $pendingBreak = 0;

    /**
     * Inline text: whitespace is collapsed, a whitespace-only run becomes at most one space between words.
     */
    public function text(string $text): void
    {
        $text = self::normalizeWhitespace($text);

        if ($text === '') {
            return;
        }

        if (trim($text) === '') {
            if ($this->pendingBreak === 0 && $this->text !== '' && !str_ends_with($this->text, ' ') && !str_ends_with($this->text, "\n")) {
                $this->text .= ' ';
            }

            return;
        }

        $this->flushBreak();

        if ($this->text === '' || str_ends_with($this->text, "\n") || str_ends_with($this->text, ' ')) {
            $text = ltrim($text, ' ');
        }

        $this->text .= $text;
    }

    /**
     * Text already laid out in lines (a list item with its indent) - written as it is.
     */
    public function preformatted(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->flushBreak();

        if (!str_ends_with($this->text, "\n")) {
            $this->text = rtrim($this->text, ' ');
        }

        $this->text .= $text;
    }

    /**
     * @param self::LINE|self::PARAGRAPH $level
     */
    public function block(int $level): void
    {
        $this->pendingBreak = max($this->pendingBreak, $level);
    }

    /**
     * <br>: a new line right here - two in a row leave one blank line.
     */
    public function lineBreak(): void
    {
        if ($this->text === '') {
            return;
        }

        $this->flushBreak();
        $this->text = rtrim($this->text, ' ') . "\n";
    }

    public function toString(): string
    {
        $lines = array_map(
            static fn (string $line): string => rtrim($line, " \t"),
            explode("\n", $this->text),
        );

        $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '';

        return trim($text, "\n");
    }

    public static function normalizeWhitespace(string $text): string
    {
        // Zero-width and invisible characters (preheader fillers, soft hyphens) carry nothing readable
        $text = preg_replace('/[\x{00AD}\x{034F}\x{180E}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text) ?? $text;

        // Every kind of space, the no-break ones included, is one space in plain text
        return preg_replace('/[\s\x{00A0}\x{2007}\x{202F}]+/u', ' ', $text) ?? $text;
    }

    private function flushBreak(): void
    {
        if ($this->pendingBreak === 0) {
            return;
        }

        if ($this->text !== '') {
            $this->text = rtrim($this->text, ' ');
            $trailingNewlines = strlen($this->text) - strlen(rtrim($this->text, "\n"));
            $this->text .= str_repeat("\n", max(0, $this->pendingBreak - $trailingNewlines));
        }

        $this->pendingBreak = 0;
    }
}
