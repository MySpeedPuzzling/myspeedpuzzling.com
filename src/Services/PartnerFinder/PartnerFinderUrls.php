<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PartnerFinder;

use SpeedPuzzling\Web\Entity\Competition;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The partner finder page of an event (docs/features/partner-finder.md: `event_partners` for a one-time event,
 * `edition_partners` for a series edition) - the target of "See their listing" and of the e-mail's "Close your listing".
 *
 * TODO(orchestrator, after merging part A): the finder routes are part A's and did not exist in part C's branch - drop
 * the RouteNotFoundException fallback to the event page below once they are merged.
 */
readonly final class PartnerFinderUrls
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

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

        [$finderRoute, $eventRoute, $parameters] = $seriesSlug !== null
            ? ['edition_partners', 'edition_detail', ['seriesSlug' => $seriesSlug, 'editionSlug' => $competitionSlug]]
            : ['event_partners', 'event_detail', ['slug' => $competitionSlug]];

        try {
            return $this->urlGenerator->generate($finderRoute, [...$parameters, ...$localeParameter], $referenceType);
        } catch (RouteNotFoundException) {
            return $this->urlGenerator->generate($eventRoute, [...$parameters, ...$localeParameter], $referenceType);
        }
    }
}
