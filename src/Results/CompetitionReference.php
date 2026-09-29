<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A publicly visible competition a puzzle page links to: a standalone event, an edition of a series,
 * or a whole series (a tag moves to the series when its competition is converted into one).
 *
 * Routing follows templates/_competition_badge.html.twig: standalone → event_detail, edition →
 * edition_detail (an edition slug is only unique within its series), whole series →
 * competition_series_detail. Without the slugs a route needs there is no link, only the name.
 */
readonly final class CompetitionReference
{
    public function __construct(
        public string $name,
        public null|string $slug,
        public null|string $seriesName = null,
        public null|string $seriesSlug = null,
        public bool $isSeries = false,
    ) {
    }

    /**
     * Full names, never shortcuts: this is prose ("Used at World Jigsaw Puzzle Championship 2024"), the words
     * people search for. An edition carries its series name, unless it is named like the series.
     */
    public function displayName(): string
    {
        if ($this->seriesName === null || mb_strtolower($this->seriesName) === mb_strtolower($this->name)) {
            return $this->name;
        }

        return $this->seriesName . ' · ' . $this->name;
    }

    public function routeName(): null|string
    {
        if ($this->slug === null) {
            return null;
        }

        if ($this->isSeries) {
            return 'competition_series_detail';
        }

        if ($this->seriesName !== null) {
            return $this->seriesSlug !== null ? 'edition_detail' : null;
        }

        return 'event_detail';
    }

    /**
     * @return array<string, string>
     */
    public function routeParameters(): array
    {
        return match ($this->routeName()) {
            'event_detail', 'competition_series_detail' => ['slug' => (string) $this->slug],
            'edition_detail' => ['seriesSlug' => (string) $this->seriesSlug, 'editionSlug' => (string) $this->slug],
            default => [],
        };
    }
}
