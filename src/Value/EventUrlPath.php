<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * An old event URL as the key of event_url_redirect (docs/features/organizations/README.md, D6): '' = not part of the
 * path. `('', 'x', '')` = /events/x, `('s', '', '')` = /series/s, `('s', 'e', '')` = /series/s/e, `('s', 'e', 'r')` =
 * the edition's round results, `('', 'x', 'r')` = the event's round results.
 */
readonly final class EventUrlPath
{
    public function __construct(
        public string $seriesSlug,
        public string $competitionSlug,
        public string $roundSlug,
    ) {
    }

    public static function event(string $slug): self
    {
        return new self('', $slug, '');
    }

    public static function series(string $slug): self
    {
        return new self($slug, '', '');
    }

    public static function edition(string $seriesSlug, string $editionSlug): self
    {
        return new self($seriesSlug, $editionSlug, '');
    }

    public static function eventRound(string $slug, string $roundSlug): self
    {
        return new self('', $slug, $roundSlug);
    }

    public static function editionRound(string $seriesSlug, string $editionSlug, string $roundSlug): self
    {
        return new self($seriesSlug, $editionSlug, $roundSlug);
    }

    public function equals(self $other): bool
    {
        return $this->seriesSlug === $other->seriesSlug
            && $this->competitionSlug === $other->competitionSlug
            && $this->roundSlug === $other->roundSlug;
    }
}
