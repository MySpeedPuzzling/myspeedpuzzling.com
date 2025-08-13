<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

readonly final class SearchHighlighter
{
    public function highlight(null|string|int $text, null|string $query): string
    {
        $text  = trim((string) $text);
        $query = trim((string) $query);

        if ($text === '' || $query === '') {
            return $text;
        }

        // Normalize and split the query into individual words.
        $normalizedQuery = $this->normalize($query);
        $queryWords = array_filter(explode(' ', $normalizedQuery));
        if (empty($queryWords)) {
            return $text;
        }

        // Split the text on spaces
        $words = explode(' ', $text);

        // Process each word to insert highlights.
        foreach ($words as &$word) {
            $word = $this->highlightWord($word, $queryWords);
        }

        return implode(' ', $words);
    }

    /**
     * @param array<string> $queryWords
     */
    private function highlightWord(string $word, array $queryWords): string
    {
        $normalizedWord = $this->normalize($word);
        $matches = [];

        // For each query word, find all occurrences inside the normalized word.
        foreach ($queryWords as $queryWord) {
            $offset = 0;
            while (($pos = mb_stripos($normalizedWord, $queryWord, $offset, 'UTF-8')) !== false) {
                $matches[] = [
                    'start'  => $pos,
                    'length' => mb_strlen($queryWord, 'UTF-8'),
                ];
                // Move offset one character ahead to catch overlapping occurrences.
                $offset = $pos + 1;
            }
        }

        if ($matches === []) {
            return $word;
        }

        // Merge any overlapping or adjacent matches.
        $mergedMatches = $this->mergeMatches($matches);

        // Rebuild the word by inserting highlight spans.
        $result = '';
        $currentPos = 0;
        foreach ($mergedMatches as $match) {
            // Append the part before the match.
            $result .= mb_substr($word, $currentPos, $match['start'] - $currentPos, 'UTF-8');
            // Append the highlighted substring.
            $matchedText = mb_substr($word, $match['start'], $match['length'], 'UTF-8');
            $result .= '<span class="search-highlight">'
                . htmlspecialchars($matchedText, ENT_QUOTES, 'UTF-8')
                . '</span>';
            $currentPos = $match['start'] + $match['length'];
        }
        // Append any remaining part of the word.
        $result .= mb_substr($word, $currentPos, null, 'UTF-8');

        return $result;
    }

    /**
     * @param array<array{start: int, length: int}> $matches
     * @return array<array{start: int, length: int}>
     */
    private function mergeMatches(array $matches): array
    {
        // Sort matches by starting index.
        usort($matches, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        $merged = [];
        foreach ($matches as $match) {
            if ($merged === []) {
                $merged[] = $match;
            } else {
                $last = &$merged[count($merged) - 1];
                // If the current match overlaps or touches the last match, merge them.
                if ($match['start'] <= $last['start'] + $last['length']) {
                    $end = max($last['start'] + $last['length'], $match['start'] + $match['length']);
                    $last['length'] = $end - $last['start'];
                } else {
                    $merged[] = $match;
                }
            }
        }

        return $merged;
    }

    private function normalize(string $input): string
    {
        $normalized = \Normalizer::normalize($input, \Normalizer::FORM_D);
        $withoutDiacritics = preg_replace('/\p{M}/u', '', (string) $normalized);

        return mb_strtolower((string) $withoutDiacritics, 'UTF-8');
    }
}
