<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventsPage;

use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Results\EventsPage\Place;
use SpeedPuzzling\Web\Results\EventsPage\SeriesLine;
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
 * own name), `sl` (a session's label: its one round's name), `cm` (the competition: sessions of one share it, null
 * for a series), `sid` (an edition's series entry), `u` (link), `f`/`t` (Y-m-d first and last day, `t` null for one
 * day), `lr` (long-running), `sc` (scope key: online / country code / ''), `c` (country code), `p` (place label), `st`
 * (EventOccurrenceStatus, null for a series), `r` (results), `w` (waiting for approval), `x` (folded search text:
 * names, a session's label, location, the country's localised and English name, the year, "online").
 */
readonly final class EventsIndexFactory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
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
    ): array {
        $isEdition = $occurrence->isEdition();
        $name = $isEdition ? (string) $occurrence->seriesName : $occurrence->name;
        $longRunning = $occurrence->isLongRunning();

        return [
            'id' => $id,
            'k' => $isEdition ? 'd' : 'e',
            'n' => $name,
            'en' => $occurrence->editionName(),
            'sl' => $occurrence->sessionLabel(),
            'cm' => $occurrence->competitionId,
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
            'x' => self::searchText([
                $occurrence->name,
                $isEdition ? $occurrence->seriesName : null,
                $occurrence->sessionLabel(),
                $occurrence->location,
                $occurrence->countryCode?->localizedName($locale),
                $occurrence->countryCode?->value,
                $occurrence->startDate?->format('Y'),
                $occurrence->isOnline ? 'online' : null,
            ]),
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
            ]),
        ];
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
}
