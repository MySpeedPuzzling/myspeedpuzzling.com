<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Standings;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionStandingNotFound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetPublicStandingHead;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\PublicStandingPage;
use SpeedPuzzling\Web\Results\PublicStandingTableView;
use SpeedPuzzling\Web\Results\PublishedStandingRef;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionStandingsLiveUpdates;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\EventTitle;

/**
 * The public standing page (competition toolkit A7, A-round-formats.md §3.4 + §3.6) - shared by the event route and the
 * series edition route, which differ only in how they find the event. 404 for an event that is not public (a draft,
 * unapproved - for its team too, like the round results pages) and for a standing that is not published.
 *
 * buildById() is the table part alone (competition toolkit v2 S4, the `public_standing_table` fragment the open page
 * reloads on a standings signal) - the same 404 rules, found by the standing's id.
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
        private GetPublicStandingHead $getPublicStandingHead,
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
            liveTopic: CompetitionStandingsLiveUpdates::topic($competitionId),
        );
    }

    /**
     * The table of a published standing of a public event for the signed-in viewer (or a guest) - the page's own rules:
     * 404 for an unknown or unpublished standing and for an event that is not public (a draft - for its team too).
     *
     * @throws CompetitionStandingNotFound
     */
    public function buildById(string $standingId): PublicStandingTableView
    {
        $head = $this->getPublicStandingHead->byId(strtolower($standingId));

        if ($head === null || $head->standing->publishedAt === null || $head->eventSlug === null) {
            throw new CompetitionStandingNotFound();
        }

        $standing = $head->standing;

        if ($this->isCompetitionPubliclyVisible->check($standing->competitionId) === false) {
            throw new CompetitionStandingNotFound();
        }

        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        return new PublicStandingTableView(
            competitionId: strtolower($standing->competitionId),
            standing: new PublishedStandingRef(
                id: strtolower($standing->id),
                name: $standing->name,
                slug: $standing->slug,
                category: $standing->category,
                roundIds: array_map(strtolower(...), $standing->roundIds),
            ),
            table: $this->getStandingResults->publicView($standing, $viewer?->playerId),
            roundRoute: $head->seriesSlug !== null ? 'edition_round_results' : 'event_round_results',
            routeParams: $head->seriesSlug !== null
                ? ['seriesSlug' => $head->seriesSlug, 'editionSlug' => $head->eventSlug]
                : ['slug' => $head->eventSlug],
        );
    }
}
