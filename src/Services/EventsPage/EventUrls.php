<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventsPage;

use SpeedPuzzling\Web\Results\EventOccurrence;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The links of the events page: an occurrence's page (CompetitionReference routing), a series page, an archive year.
 * Paths, not absolute URLs. Null when the route's slugs are missing - the item is listed without a link.
 */
readonly final class EventUrls
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * A session of several (rounds on separate days) links to its first round on the event page: `#round-<id>`.
     */
    public function occurrence(EventOccurrence $occurrence): null|string
    {
        $url = $this->occurrencePage($occurrence);

        if ($url === null || $occurrence->session === null) {
            return $url;
        }

        return $url . '#round-' . $occurrence->session->firstRoundId;
    }

    /**
     * The event's (or edition's) page itself - the same for every session (ItemList JSON-LD).
     */
    public function occurrencePage(EventOccurrence $occurrence): null|string
    {
        $reference = $occurrence->reference();
        $route = $reference->routeName();

        if ($route === null) {
            return null;
        }

        return $this->urlGenerator->generate($route, $reference->routeParameters());
    }

    public function series(null|string $slug): null|string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return $this->urlGenerator->generate('competition_series_detail', ['slug' => $slug]);
    }

    public function archive(int $year): string
    {
        return $this->urlGenerator->generate('events_archive', ['year' => $year]);
    }
}
