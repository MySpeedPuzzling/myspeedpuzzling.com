<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

readonly final class SearchHighlighter
{
    public function highlight(string|int|null $text, string $query): string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return '';
        }

        $words = array_filter(explode(' ', trim($query)));

        if ($words === []) {
            return $text;
        }

        $escapedWords = array_map(
            callback: fn (string $word): string => preg_quote($word, '/'),
            array: $words
        );

        $pattern = '/' . implode('|', $escapedWords) . '/i';

        $highlightedText = preg_replace_callback(
            pattern: $pattern,
            callback: fn (array $matches): string => '<span class="search-highlight">' . htmlspecialchars($matches[0], ENT_QUOTES, 'UTF-8') . '</span>',
            subject: $text,
        );

        return $highlightedText ?? $text;
    }
}
