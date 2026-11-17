<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Standings;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionStandingNotFound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\PublicStandingPage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\EventTitle;

/**
 * The public standing page (competition toolkit A7, A-round-formats.md §3.4 + §3.6) - shared by the event route and the
 * series edition route, which differ only in how they find the event. 404 for an event that is not public (a draft,
 * unapproved - for its team too, like the round results pages) and for a standing that is not published.
 */
readonly final class StandingPageBuilder
{
    public function __construct(
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private GetCompetitionEvents $getCompetitionEvents,
        private GetCompetitionStandings $getCompetitionStandings,
        private GetStandingResults $getStandingResults,
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionStandingNotFound
     */
    public function build(string $competitionId, string $standingSlug, null|string $seriesName = null): PublicStandingPage
    {
        if ($this->isCompetitionPubliclyVisible->check($competitionId) === false) {
            throw new CompetitionStandingNotFound();
        }

        $event = $this->getCompetitionEvents->byId($competitionId);
        $ref = null;

        foreach ($event->publishedStandings as $published) {
            if ($published->slug === $standingSlug) {
                $ref = $published;

                break;
            }
        }

        if ($ref === null) {
            throw new CompetitionStandingNotFound();
        }

        $standing = $this->getCompetitionStandings->byId($ref->id);
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        return new PublicStandingPage(
            event: $event,
            eventTitle: EventTitle::forCompetition($event, $seriesName, [], $this->clock->now()),
            standing: $ref,
            standings: $event->publishedStandings,
            table: $this->getStandingResults->publicView($standing, $viewer?->playerId),
        );
    }
}
