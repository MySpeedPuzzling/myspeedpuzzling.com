<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

readonly final class SearchHighlighter
{
    public function highlight(string|int|null $text, string $query): string
    {
        $text = trim((string) $text);

        if ($text === '' || $query === '') {
            return $text;
        }

        $queryWords = array_filter(explode(' ', $this->normalize($query)));

        if (empty($queryWords)) {
            return $text;
        }

        $words = explode(' ', $text);

        foreach ($words as &$word) {
            $normalizedWord = $this->normalize($word);

            foreach ($queryWords as $queryWord) {
                if (stripos($normalizedWord, $queryWord) !== false) {
                    $pattern = '/' . preg_quote($this->mapNormalizedSubstring($queryWord, $normalizedWord, (string) $word), '/') . '/ui';

                    $word = preg_replace_callback(
                        $pattern,
                        fn($matches) => '<span class="search-highlight">' . htmlspecialchars($matches[0], ENT_QUOTES, 'UTF-8') . '</span>',
                        (string) $word
                    );
                }
            }
        }

        return implode(' ', $words);
    }

    private function normalize(string $input): string
    {
        $input = (string) \Normalizer::normalize($input, \Normalizer::FORM_D); // Decompose characters
        $input = (string) preg_replace('/\p{M}/u', '', $input);

        return mb_strtolower($input, 'UTF-8');
    }

    private function mapNormalizedSubstring(string $queryWord, string $normalizedWord, string $originalWord): string
    {
        $start = stripos($normalizedWord, $queryWord);

        if ($start === false) {
            return $queryWord;
        }

        $length = mb_strlen($queryWord, 'UTF-8');
        return  mb_substr($originalWord, $start, $length, 'UTF-8');
    }
}
