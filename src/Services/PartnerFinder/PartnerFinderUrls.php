<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PartnerFinder;

use SpeedPuzzling\Web\Entity\Competition;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where an event's partner finder page and the event page live: a series edition under its series
 * (`edition_partners`), a one-time event under its own slug (`event_partners`).
 */
readonly final class PartnerFinderUrls
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function finder(Competition $competition): string
    {
        if ($competition->series !== null && $competition->series->slug !== null) {
            return $this->urlGenerator->generate('edition_partners', [
                'seriesSlug' => $competition->series->slug,
                'editionSlug' => $competition->slug,
            ]);
        }

        return $this->urlGenerator->generate('event_partners', ['slug' => $competition->slug]);
    }

    public function event(Competition $competition): string
    {
        if ($competition->series !== null && $competition->series->slug !== null) {
            return $this->urlGenerator->generate('edition_detail', [
                'seriesSlug' => $competition->series->slug,
                'editionSlug' => $competition->slug,
            ]);
        }

        return $this->urlGenerator->generate('event_detail', ['slug' => $competition->slug]);
    }

    /**
     * The finder in another locale or as an absolute URL (e-mails) - "See their listing", "Close your listing"
     */
    public function forCompetition(Competition $competition, null|string $locale = null, bool $absolute = false): null|string
    {
        return $this->forSlugs($competition->slug, $competition->series?->slug, $locale, $absolute);
    }

    /**
     * Null when the event has no slug (an old event without a page)
     */
    public function forSlugs(null|string $competitionSlug, null|string $seriesSlug, null|string $locale = null, bool $absolute = false): null|string
    {
        if ($competitionSlug === null) {
            return null;
        }

        $referenceType = $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH;
        $localeParameter = $locale !== null ? ['_locale' => $locale] : [];

        [$route, $parameters] = $seriesSlug !== null
            ? ['edition_partners', ['seriesSlug' => $seriesSlug, 'editionSlug' => $competitionSlug]]
            : ['event_partners', ['slug' => $competitionSlug]];

        return $this->urlGenerator->generate($route, [...$parameters, ...$localeParameter], $referenceType);
    }
}
