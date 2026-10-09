<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventsPage;

use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Results\EventsPage\Place;
use SpeedPuzzling\Web\Results\EventsPage\SeriesLine;
use SpeedPuzzling\Web\Results\OrganizationRef;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The entries of the search and calendar index the events page ships (`<script type="application/json"
 * data-events-index>`, read by assets/events_index.js). One entry per occurrence shown on any view and per series
 * line; the position in the list is the entry's `id` - unique also for the sessions of one competition (rounds on
 * separate days), which are separate entries. Keys are short on purpose:
 *
 * `id`, `k` (e = one-time event, d = edition, s = series), `n` (the event's or the series' name), `en` (an edition's
 * own name), `sl` (a session's label: its one round's name), `cm` (the competition, only when it has two or more
 * sessions - they share it; the archive counts competitions by `cm ?? id`), `sid` (an edition's series entry), `u`
 * (link), `f`/`t` (Y-m-d first and last day, `t` null for one day), `lr` (long-running), `sc` (scope key: online /
 * country code / ''), `c` (country code), `p` (place label), `st` (EventOccurrenceStatus, null for a series), `r`
 * (results), `w` (waiting for approval), `x` (folded search text: names, a session's label, location, the country's
 * localised and English name, the year, "online", the name and short name of its organization while that is publicly
 * visible - docs/features/organizations/README.md - and the names of its revealed round puzzles).
 *
 * occurrence() and series() give the full entries (EventsPage::$index - what the server reads); compact() turns them
 * into what the page ships (docs/features/events-page/high-frequency-series.md P25 - a series with 200 editions must
 * not add 80 KB to every events page): keys holding their default (DEFAULTS) are left out, and an edition entry carries
 * only what differs from its series' entry - `n`, `sc`, `c` and `p` only when they differ, its link as `es` (its path
 * after its series' path + "/") when its path starts with its series' path, and in `x` only its own words. The browser
 * rebuilds the full entries (expandEventsIndex() of assets/events_index.js) - EventsIndexScriptTest pins that it gets
 * exactly EventsPage::$index back.
 *
 * An edition's full `x` is its own words followed by its series entry's `x` (a query matches an edition when every word
 * is in the edition's or its series' search text) - occurrence() builds it that way when it gets the series entry.
 */
readonly final class EventsIndexFactory
{
    /**
     * What a key holds when the shipped entry leaves it out
     */
    public const array DEFAULTS = [
        'n' => null,
        'en' => null,
        'sl' => null,
        'cm' => null,
        'sid' => null,
        'u' => null,
        'f' => null,
        't' => null,
        'lr' => false,
        'sc' => '',
        'c' => null,
        'p' => null,
        'st' => null,
        'r' => false,
        'w' => false,
        'x' => '',
    ];

    /**
     * The keys an edition takes from its series entry when the shipped entry leaves them out
     */
    public const array INHERITED = ['n', 'sc', 'c', 'p'];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param null|array<string, mixed> $seriesEntry an edition's series entry (series()) - its `x` then ends the
     *     edition's, which holds only the words the series' does not
     *
     * @return array<string, mixed>
     */
    public function occurrence(
        int $id,
        EventOccurrence $occurrence,
        EventOccurrenceStatus $status,
        null|string $url,
        Place $place,
        null|int $seriesIndexId,
        string $locale,
        null|array $seriesEntry = null,
    ): array {
        $isEdition = $occurrence->isEdition();
        $name = $isEdition ? (string) $occurrence->seriesName : $occurrence->name;
        $longRunning = $occurrence->isLongRunning();

        $searchText = self::searchText([
            $occurrence->name,
            $isEdition ? $occurrence->seriesName : null,
            $occurrence->sessionLabel(),
            $occurrence->location,
            $occurrence->countryCode?->localizedName($locale),
            $occurrence->countryCode?->value,
            $occurrence->startDate?->format('Y'),
            $occurrence->isOnline ? 'online' : null,
            ...self::organizationNames($occurrence->organization),
            ...$occurrence->puzzleNames(),
        ]);

        if ($isEdition && $seriesEntry !== null) {
            $seriesText = is_string($seriesEntry['x'] ?? null) ? $seriesEntry['x'] : '';
            $searchText = self::withSeriesText(implode(' ', self::wordsNotIn($searchText, $seriesText)), $seriesText);
        }

        return [
            'id' => $id,
            'k' => $isEdition ? 'd' : 'e',
            'n' => $name,
            'en' => $occurrence->editionName(),
            'sl' => $occurrence->sessionLabel(),
            // Sessions of one competition share it - a competition of one session is counted by its own id
            'cm' => $occurrence->session !== null ? $occurrence->competitionId : null,
            'sid' => $seriesIndexId,
            'u' => $url,
            'f' => $occurrence->startDate?->format('Y-m-d'),
            't' => $occurrence->endDate?->format('Y-m-d'),
            'lr' => $longRunning,
            'sc' => EventsScope::keyOf($occurrence->isOnline, $occurrence->countryCode),
            'c' => $occurrence->countryCode?->name,
            'p' => $this->placeLabel($place, $locale),
            'st' => $status->value,
            'r' => $occurrence->hasResults,
            'w' => $occurrence->isPublic === false,
            'x' => $searchText,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function series(SeriesLine $line, EventSeriesRow $series, string $locale): array
    {
        return [
            'id' => $line->indexId,
            'k' => 's',
            'n' => $series->name,
            'en' => null,
            'sl' => null,
            'cm' => null,
            'sid' => null,
            'u' => $line->url,
            'f' => null,
            't' => null,
            'lr' => false,
            'sc' => $line->scopeKey,
            'c' => $series->countryCode?->name,
            'p' => $this->placeLabel($line->place, $locale),
            'st' => null,
            'r' => false,
            'w' => $line->isPending,
            'x' => self::searchText([
                $series->name,
                $series->location,
                $series->countryCode?->localizedName($locale),
                $series->countryCode?->value,
                $series->isOnline ? 'online' : null,
                ...self::organizationNames($line->organization),
            ]),
        ];
    }

    /**
     * The index as the page ships it - see the class comment. `id` and `k` always stay.
     *
     * @param list<array<string, mixed>> $index full entries, the position is the id
     *
     * @return list<array<string, mixed>>
     */
    public function compact(array $index): array
    {
        $compact = [];

        foreach ($index as $entry) {
            $series = self::seriesEntryOf($entry, $index);
            $shipped = [];

            foreach ($entry as $key => $value) {
                if ($key === 'id' || $key === 'k') {
                    $shipped[$key] = $value;

                    continue;
                }

                if ($series !== null && in_array($key, self::INHERITED, true)) {
                    // Inherited unless it differs - then even an empty value is its own
                    if ($value !== ($series[$key] ?? self::DEFAULTS[$key])) {
                        $shipped[$key] = $value;
                    }

                    continue;
                }

                if ($series !== null && $key === 'u' && is_string($value)) {
                    $seriesPath = $series['u'] ?? null;

                    if (is_string($seriesPath) && $seriesPath !== '' && str_starts_with($value, $seriesPath . '/')) {
                        $shipped['es'] = substr($value, strlen($seriesPath) + 1);

                        continue;
                    }
                }

                if ($series !== null && $key === 'x' && is_string($value)) {
                    $value = implode(' ', self::wordsNotIn($value, is_string($series['x'] ?? null) ? $series['x'] : ''));
                }

                if (array_key_exists($key, self::DEFAULTS) && $value === self::DEFAULTS[$key]) {
                    continue;
                }

                $shipped[$key] = $value;
            }

            $compact[] = $shipped;
        }

        return $compact;
    }

    /**
     * The full entries of a shipped index - the inverse of compact(), rule for rule what expandEventsIndex() of
     * assets/events_index.js does in the browser (EventsIndexScriptTest pins all three against each other). Tests read
     * a rendered page's index through it.
     *
     * @param list<array<string, mixed>> $shipped
     *
     * @return list<array<string, mixed>>
     */
    public static function expand(array $shipped): array
    {
        $full = [];

        foreach ($shipped as $entry) {
            $expanded = ['id' => $entry['id'] ?? null, 'k' => $entry['k'] ?? null];

            foreach (self::DEFAULTS as $key => $default) {
                $expanded[$key] = array_key_exists($key, $entry) ? $entry[$key] : $default;
            }

            $seriesId = $entry['sid'] ?? null;
            $series = ($entry['k'] ?? null) === 'd' && is_int($seriesId) ? ($shipped[$seriesId] ?? null) : null;

            if (is_array($series) && ($series['k'] ?? null) === 's' && ($series['id'] ?? null) === $seriesId) {
                foreach (self::INHERITED as $key) {
                    if (array_key_exists($key, $entry) === false) {
                        $expanded[$key] = array_key_exists($key, $series) ? $series[$key] : self::DEFAULTS[$key];
                    }
                }

                $seriesPath = $series['u'] ?? null;

                if (array_key_exists('u', $entry) === false && array_key_exists('es', $entry) && is_string($seriesPath) && $seriesPath !== '') {
                    $expanded['u'] = $seriesPath . '/' . (is_scalar($entry['es']) ? (string) $entry['es'] : '');
                }

                $expanded['x'] = self::withSeriesText(
                    is_string($entry['x'] ?? null) ? $entry['x'] : '',
                    is_string($series['x'] ?? null) ? $series['x'] : '',
                );
            }

            $full[] = $expanded;
        }

        return $full;
    }

    /**
     * "Hamburg, Germany" / "Germany" / "Online"
     */
    public function placeLabel(Place $place, string $locale): null|string
    {
        if ($place->isOnline) {
            return $this->translator->trans('events_page.place.online', locale: $locale);
        }

        $parts = array_filter([$place->city, $place->country], static fn (null|string $part): bool => $part !== null && $part !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * An edition's series entry - the entry its `sid` points at, when that is a series entry
     *
     * @param array<string, mixed> $entry
     * @param list<array<string, mixed>> $index
     *
     * @return null|array<string, mixed>
     */
    private static function seriesEntryOf(array $entry, array $index): null|array
    {
        $seriesId = $entry['sid'] ?? null;

        if (($entry['k'] ?? null) !== 'd' || is_int($seriesId) === false) {
            return null;
        }

        $series = $index[$seriesId] ?? null;

        return is_array($series) && ($series['k'] ?? null) === 's' && ($series['id'] ?? null) === $seriesId ? $series : null;
    }

    /**
     * The name and short name of a publicly visible organization - nothing for a draft, pending or rejected one
     *
     * @return list<null|string>
     */
    private static function organizationNames(null|OrganizationRef $organization): array
    {
        if ($organization === null || $organization->isPublic === false) {
            return [];
        }

        return [$organization->name, $organization->shortName];
    }

    /**
     * @param list<null|string> $parts
     */
    private static function searchText(array $parts): string
    {
        $texts = [];

        foreach ($parts as $part) {
            if ($part === null || $part === '') {
                continue;
            }

            $folded = SearchText::fold($part);

            if ($folded !== '' && in_array($folded, $texts, true) === false) {
                $texts[] = $folded;
            }
        }

        return implode(' ', $texts);
    }

    /**
     * The words of a folded text, each once - a typed word matches inside one word, so the set of words is what
     * matching reads
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return array_values(array_unique(array_filter(explode(' ', $text), static fn (string $word): bool => $word !== '')));
    }

    /**
     * @return list<string>
     */
    private static function wordsNotIn(string $text, string $other): array
    {
        return array_values(array_diff(self::words($text), self::words($other)));
    }

    /**
     * An edition's full search text: its own words, then its series' text as it is - expandEventsIndex() of
     * assets/events_index.js joins them the same way
     */
    private static function withSeriesText(string $own, string $seriesText): string
    {
        if ($own === '' || $seriesText === '') {
            return $own . $seriesText;
        }

        return $own . ' ' . $seriesText;
    }
}
