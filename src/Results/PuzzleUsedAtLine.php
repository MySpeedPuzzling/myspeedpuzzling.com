<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * One line of a puzzle page's "Used at" (docs/features/events-page/high-frequency-series.md P24, GetPuzzleSummary):
 *
 * - a **round line** - the puzzle is a revealed puzzle of a round of a publicly visible event: "<series> · <edition> ·
 *   <day> · <category>" (a one-time event: "<event> · <day> · <category>"), the day in the round's own zone, linking
 *   the event or edition page at `#round-<id>`;
 * - a **tag line** - the puzzle carries the tag of a publicly visible event or series no round line names: "<event>",
 *   linking its page (the old "Used at" badges).
 *
 * The names, slugs and routing are CompetitionReference's (reference()).
 */
readonly final class PuzzleUsedAtLine
{
    public function __construct(
        // The event or edition - for a series tag the series
        public string $name,
        public null|string $slug,
        public null|string $seriesName = null,
        public null|string $seriesSlug = null,
        // A tag of a whole series
        public bool $isSeries = false,
        public null|string $roundId = null,
        public null|RoundCategory $category = null,
        // The round's start in the round's own zone - its day is the line's date
        public null|DateTimeImmutable $startsAt = null,
    ) {
    }

    /**
     * @param array{
     *     round_id: string,
     *     category: string,
     *     starts_at: string,
     *     timezone: null|string,
     *     name: string,
     *     slug: null|string,
     *     country_code: null|string,
     *     series_name: null|string,
     *     series_slug: null|string,
     *     series_country_code: null|string,
     * } $row
     */
    public static function ofRound(array $row): self
    {
        $zone = RoundTimezone::resolve($row['timezone'], $row['country_code'], $row['series_country_code']);

        return new self(
            name: $row['name'],
            slug: $row['slug'],
            seriesName: $row['series_name'],
            seriesSlug: $row['series_slug'],
            roundId: $row['round_id'],
            category: RoundCategory::tryFrom($row['category']),
            startsAt: new DateTimeImmutable($row['starts_at'], new DateTimeZone('UTC'))->setTimezone(new DateTimeZone($zone)),
        );
    }

    /**
     * @param array{name: string, slug: null|string, series_name: null|string, series_slug: null|string, is_series: bool} $row
     */
    public static function ofTag(array $row): self
    {
        return new self(
            name: $row['name'],
            slug: $row['slug'],
            seriesName: $row['series_name'],
            seriesSlug: $row['series_slug'],
            isSeries: $row['is_series'],
        );
    }

    public function isRound(): bool
    {
        return $this->roundId !== null;
    }

    public function reference(): CompetitionReference
    {
        return new CompetitionReference(
            name: $this->name,
            slug: $this->slug,
            seriesName: $this->seriesName,
            seriesSlug: $this->seriesSlug,
            isSeries: $this->isSeries,
        );
    }

    /**
     * Full names, never shortcuts - "<series> · <edition>", the edition alone when named like its series
     */
    public function displayName(): string
    {
        return $this->reference()->displayName();
    }

    public function routeName(): null|string
    {
        return $this->reference()->routeName();
    }

    /**
     * @return array<string, string>
     */
    public function routeParameters(): array
    {
        $parameters = $this->reference()->routeParameters();

        if ($parameters !== [] && $this->roundId !== null) {
            $parameters['_fragment'] = 'round-' . $this->roundId;
        }

        return $parameters;
    }
}
