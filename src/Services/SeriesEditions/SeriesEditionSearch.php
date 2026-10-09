<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SeriesEditions;

use SpeedPuzzling\Web\Results\SeriesEditionChoice;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * How a typed search finds editions on the add-time form (docs/features/events-page/high-frequency-series.md "S1 -
 * typing finds editions" and "The short list"):
 *
 * - words(): whitespace, `#`, `.` and `-` separate the typed words - "No. 15", "#160", "Jam-160";
 * - tokens(): the runs of letters and digits a word or a text is compared by;
 * - an edition whose name - with its series' name and shortcut - holds every word as a whole token ranks first, so a
 *   number finds that number and not the start of a longer one: "No. 1" is Jam No. 1 before Jam No. 10, 15 or 100.
 *   GetSeriesEditionChoices::search() (S1) ranks the same in SQL;
 * - the short list (rank()) searches more: the edition's revealed round puzzle names and its date words (dateWords():
 *   the year, the month in the page's language and in English, the day with the month - "2025", "Oktober", "8 Oct",
 *   "8 October 2026"), all folded like every event search (SearchText::fold()). Every other edition holding every word
 *   anywhere in that text follows the whole-token ones.
 */
readonly final class SeriesEditionSearch
{
    // An edition held over more days gets the date words of each day - up to this many
    private const int MAX_DAYS_WITH_WORDS = 7;

    // ICU skeletons of the date words - the events pages' formats (EventsPageDates)
    private const array DATE_SKELETONS = ['yMMMMd', 'yMMMd', 'MMMM', 'MMM'];

    public function __construct(
        private EventsPageDates $dates,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function words(string $query): array
    {
        return array_values(array_filter(
            preg_split('/[\s#.\-]+/u', trim($query)) ?: [],
            static fn (string $word): bool => $word !== '',
        ));
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        return array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [],
            static fn (string $token): bool => $token !== '',
        ));
    }

    /**
     * The short list's search: the editions holding every typed word - those holding them as whole tokens of the name
     * (with the series' name and shortcut) first, then the rest; each group in the given order (closest to the solve day
     * first).
     *
     * @param list<SeriesEditionChoice> $choices
     * @return list<SeriesEditionChoice>
     */
    public function rank(array $choices, string $query): array
    {
        $words = self::words(SearchText::fold($query));

        if ($words === []) {
            return $choices;
        }

        $queryTokens = array_values(array_unique(array_merge(...array_map(self::tokens(...), $words))));
        $named = [];
        $others = [];

        foreach ($choices as $choice) {
            $name = SearchText::fold($choice->name . ' ' . $choice->seriesName . ' ' . ($choice->seriesShortcut ?? ''));
            $text = $name . ' ' . SearchText::fold(implode(' ', $choice->puzzleNames) . ' ' . $this->dateWords($choice));

            foreach ($words as $word) {
                if (str_contains($text, $word) === false) {
                    continue 2;
                }
            }

            if (self::holdsEveryToken($name, $queryTokens)) {
                $named[] = $choice;
            } else {
                $others[] = $choice;
            }
        }

        return [...$named, ...$others];
    }

    /**
     * The words a date is typed with: each day of the edition's span (up to a week, and its last day) in the page's
     * language and in English - "8 October 2026 8 Oct 2026 October Oct", "8. října 2026 8. 10. 2026 říjen říj 8 October
     * 2026 …"
     */
    public function dateWords(SeriesEditionChoice $choice): string
    {
        if ($choice->dayFrom === null) {
            return '';
        }

        $to = $choice->dayTo ?? $choice->dayFrom;
        $days = [];

        for ($day = $choice->dayFrom; $day <= $to && count($days) < self::MAX_DAYS_WITH_WORDS; $day = $day->modify('+1 day')) {
            $days[] = $day;
        }

        if ($to > $choice->dayFrom && end($days) < $to) {
            $days[] = $to;
        }

        $words = [];

        foreach ($days as $day) {
            foreach ([null, 'en'] as $locale) {
                foreach (self::DATE_SKELETONS as $skeleton) {
                    $words[] = $this->dates->format($day, $skeleton, $locale);
                }
            }
        }

        return implode(' ', array_unique($words));
    }

    /**
     * @param list<string> $queryTokens
     */
    private static function holdsEveryToken(string $text, array $queryTokens): bool
    {
        $tokens = array_flip(self::tokens($text));

        foreach ($queryTokens as $token) {
            if (isset($tokens[$token]) === false) {
                return false;
            }
        }

        return true;
    }
}
