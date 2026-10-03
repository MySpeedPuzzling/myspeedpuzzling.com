<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The page of a competition: event_detail for a standalone event, edition_detail for an edition of a series.
 * Never event_detail for an edition - an edition slug is only unique within its series, so event_detail could
 * resolve it to another series' edition. A competition without the slugs its route needs has no page of its
 * own - then the events list.
 */
readonly final class CompetitionDetailUrl
{
    public function __construct(
        private GetCompetitionEvents $getCompetitionEvents,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     */
    public function of(string $competitionId): string
    {
        $competition = $this->getCompetitionEvents->referenceById($competitionId);
        $routeName = $competition->routeName();

        if ($routeName === null) {
            return $this->urlGenerator->generate('events');
        }

        return $this->urlGenerator->generate($routeName, $competition->routeParameters());
    }
}
