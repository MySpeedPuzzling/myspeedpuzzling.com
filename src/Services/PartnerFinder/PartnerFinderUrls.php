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
}
