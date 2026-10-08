<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventsPage;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every date on the events pages (agenda, archive, "You organize"), in the page's language: an ICU skeleton
 * ("yMMMM", "MMMd", "yMMMEd" …) picks the locale's own order, month form and punctuation - "October 2026",
 * "Oktober 2026", "říjen 2026", "2026年10月". A date is its own calendar day (its Y-m-d, whatever its zone).
 * English pages read "12 Oct 2026" (en_GB), as the page was designed.
 *
 * assets/events_index.js formatDate()/formatDayRange() write the same with Intl.DateTimeFormat and the same
 * options, so the browser's lines match the server's (EventsIndexScriptTest compares both).
 */
final class EventsPageDates implements ResetInterface
{
    /** @var array<string, string> locale|skeleton => pattern */
    private array $patterns = [];

    /** @var array<string, IntlDateFormatter> locale|pattern => formatter */
    private array $formatters = [];

    public function __construct(
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * The locale the dates are written in: the page's, English as en_GB.
     */
    public static function dateLocale(string $locale): string
    {
        $locale = str_replace('-', '_', $locale);

        return $locale === 'en' ? 'en_GB' : $locale;
    }

    public function format(DateTimeInterface $date, string $skeleton, null|string $locale = null): string
    {
        $locale = self::dateLocale($locale ?? $this->translator->getLocale());

        return $this->formatWithPattern($date, $this->pattern($skeleton, $locale), $locale);
    }

    /**
     * Two days (or months) as one compact range: the part they share is written once, on the side the locale puts
     * it - "10–11 Oct", "10.–11. 10.", "10月10日–11日", "30 Oct – 2 Nov 2025", "Mar – Jun 2025", "2025年3月–6月".
     * Only the fields of the skeleton count: "MMM" of 3 and 24 March is one month.
     */
    public function range(DateTimeInterface $from, null|DateTimeInterface $to, string $skeleton, null|string $locale = null): string
    {
        $locale = self::dateLocale($locale ?? $this->translator->getLocale());
        $pattern = $this->pattern($skeleton, $locale);
        $tokens = self::tokens($pattern);
        $fields = array_values(array_filter(array_map(static fn (array $token): null|string => $token['field'], $tokens)));

        $sameYear = $to === null || $from->format('Y') === $to->format('Y');
        $sameMonth = $to === null || $from->format('Y-m') === $to->format('Y-m');
        $sameDay = $to === null || $from->format('Y-m-d') === $to->format('Y-m-d');
        $hasYear = in_array('y', $fields, true);
        $hasMonth = in_array('M', $fields, true);
        $hasDay = in_array('d', $fields, true);

        $full = $this->formatWithPattern($from, $pattern, $locale);

        if ($to === null || (($sameDay || $hasDay === false) && ($sameMonth || $hasMonth === false) && ($sameYear || $hasYear === false))) {
            return $full;
        }

        $fullTo = $this->formatWithPattern($to, $pattern, $locale);

        // Only the weekday-less day, month and year fields are compacted
        if (in_array('E', $fields, true) === false) {
            $first = $fields[0] ?? null;
            $last = $fields[count($fields) - 1] ?? null;

            // One month, two days: the day is written twice, the rest once
            if ($hasDay && $sameMonth) {
                if ($first === 'd') {
                    return $this->formatWithPattern($from, self::patternOf(self::segment($tokens, 'd')), $locale) . '–' . $fullTo;
                }

                if ($last === 'd') {
                    return $full . '–' . $this->formatWithPattern($to, self::patternOf(self::segment($tokens, 'd')), $locale);
                }
            }

            // One year, two months: the year is written once
            if ($hasYear && $sameYear) {
                if ($last === 'y') {
                    return self::joined($this->formatWithPattern($from, self::patternOf(self::without($tokens, 'y')), $locale), $fullTo);
                }

                if ($first === 'y') {
                    return self::joined($full, $this->formatWithPattern($to, self::patternOf(self::without($tokens, 'y')), $locale));
                }
            }
        }

        return self::joined($full, $fullTo);
    }

    public function reset(): void
    {
        $this->patterns = [];
        $this->formatters = [];
    }

    private function pattern(string $skeleton, string $locale): string
    {
        $key = $locale . '|' . $skeleton;

        if (isset($this->patterns[$key]) === false) {
            $pattern = new IntlDatePatternGenerator($locale)->getBestPattern($skeleton);
            $this->patterns[$key] = is_string($pattern) && $pattern !== '' ? $pattern : $skeleton;
        }

        return $this->patterns[$key];
    }

    private function formatWithPattern(DateTimeInterface $date, string $pattern, string $locale): string
    {
        $key = $locale . '|' . $pattern;
        $this->formatters[$key] ??= new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'UTC',
            IntlDateFormatter::GREGORIAN,
            $pattern,
        );

        $formatted = $this->formatters[$key]->format(new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC')));

        return is_string($formatted) ? (string) preg_replace('/^[\s\p{Zs}]+|[\s\p{Zs}]+$/u', '', $formatted) : $date->format('Y-m-d');
    }

    /**
     * Spaces around the dash when a side has spaces of its own ("30 Oct – 2 Nov"), none when not ("10月30日–11月2日")
     */
    private static function joined(string $from, string $to): string
    {
        return preg_match('/[\s\p{Zs}]/u', $from . $to) === 1 ? $from . ' – ' . $to : $from . '–' . $to;
    }

    /**
     * An ICU pattern as fields and literals; `field` is y, M, d, E or another letter, null for a literal.
     *
     * @return list<array{raw: string, field: null|string}>
     */
    private static function tokens(string $pattern): array
    {
        $tokens = [];
        $length = strlen($pattern);
        $i = 0;

        while ($i < $length) {
            $char = $pattern[$i];

            if ($char === "'") {
                $end = $i + 1;

                while ($end < $length) {
                    if ($pattern[$end] === "'" && ($pattern[$end + 1] ?? '') === "'") {
                        $end += 2;
                        continue;
                    }

                    if ($pattern[$end] === "'") {
                        break;
                    }

                    $end++;
                }

                $tokens[] = ['raw' => substr($pattern, $i, $end - $i + 1), 'field' => null];
                $i = $end + 1;
                continue;
            }

            if (ctype_alpha($char)) {
                $end = $i;

                while ($end < $length && $pattern[$end] === $char) {
                    $end++;
                }

                $field = match ($char) {
                    'y', 'Y', 'u', 'U', 'r' => 'y',
                    'M', 'L' => 'M',
                    'd' => 'd',
                    'E', 'c', 'e' => 'E',
                    default => $char,
                };
                $tokens[] = ['raw' => substr($pattern, $i, $end - $i), 'field' => $field];
                $i = $end;
                continue;
            }

            $end = $i;

            while ($end < $length && $pattern[$end] !== "'" && ctype_alpha($pattern[$end]) === false) {
                $end++;
            }

            $tokens[] = ['raw' => substr($pattern, $i, $end - $i), 'field' => null];
            $i = $end;
        }

        return $tokens;
    }

    /**
     * A field with the literals that follow it, up to the next field: "d." of "d. M. y", "d日" of "y年M月d日"
     *
     * @param list<array{raw: string, field: null|string}> $tokens
     * @return list<array{raw: string, field: null|string}>
     */
    private static function segment(array $tokens, string $field): array
    {
        $segment = [];

        foreach ($tokens as $token) {
            if ($segment === []) {
                if ($token['field'] === $field) {
                    $segment[] = $token;
                }

                continue;
            }

            if ($token['field'] !== null) {
                break;
            }

            $segment[] = $token;
        }

        return $segment;
    }

    /**
     * The pattern without its last (or first) field and the literals between it and the rest: "d MMM" of "d MMM y",
     * "M月d日" of "y年M月d日". A dot closing the field before stays: "d. M." of "d. M. y".
     *
     * @param list<array{raw: string, field: null|string}> $tokens
     * @return list<array{raw: string, field: null|string}>
     */
    private static function without(array $tokens, string $field): array
    {
        $fieldIndexes = array_keys(array_filter($tokens, static fn (array $token): bool => $token['field'] !== null));

        if ($fieldIndexes === []) {
            return $tokens;
        }

        $lastIndex = $fieldIndexes[count($fieldIndexes) - 1];

        if ($tokens[$lastIndex]['field'] === $field) {
            $kept = array_slice($tokens, 0, $lastIndex);

            // Trailing literals go, except a leading dot of them ("M." in "d. M. y")
            $dot = '';

            while ($kept !== [] && $kept[count($kept) - 1]['field'] === null) {
                $literal = array_pop($kept);
                $dot = str_starts_with($literal['raw'], '.') ? '.' : '';
            }

            if ($dot !== '') {
                $kept[] = ['raw' => $dot, 'field' => null];
            }

            return [...$kept, ...array_slice($tokens, $lastIndex + 1)];
        }

        $firstIndex = $fieldIndexes[0];

        if ($tokens[$firstIndex]['field'] === $field) {
            $rest = array_slice($tokens, $firstIndex + 1);

            while ($rest !== [] && $rest[0]['field'] === null) {
                array_shift($rest);
            }

            return [...array_slice($tokens, 0, $firstIndex), ...$rest];
        }

        return $tokens;
    }

    /**
     * @param list<array{raw: string, field: null|string}> $tokens
     */
    private static function patternOf(array $tokens): string
    {
        return implode('', array_map(static fn (array $token): string => $token['raw'], $tokens));
    }
}
