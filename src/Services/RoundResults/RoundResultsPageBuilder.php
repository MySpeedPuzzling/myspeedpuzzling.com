<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\RoundResults;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetRoundResults;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\RoundResultsPage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;

/**
 * Everything the round result page shows - shared by the standalone event route and the series edition
 * route, which differ only in how they find the competition.
 */
readonly final class RoundResultsPageBuilder
{
    public function __construct(
        private GetCompetitionEvents $getCompetitionEvents,
        private GetEditionRounds $getEditionRounds,
        private GetRoundResults $getRoundResults,
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound
     */
    public function build(string $competitionId, string $roundSlug): RoundResultsPage
    {
        // Results of a competition nobody can pick in the add-time form are not public either
        if ($this->isCompetitionPubliclyVisible->check($competitionId) === false) {
            throw new CompetitionRoundNotFound();
        }

        $rounds = array_values(array_filter(
            $this->getEditionRounds->forCompetition($competitionId),
            static fn (EditionRoundDetail $round): bool => $round->slug !== null,
        ));

        $position = null;
        foreach ($rounds as $index => $candidate) {
            if ($candidate->slug === $roundSlug) {
                $position = $index;
                break;
            }
        }

        if ($position === null) {
            throw new CompetitionRoundNotFound();
        }

        $round = $rounds[$position];
        $event = $this->getCompetitionEvents->byId($competitionId);
        $viewer = $this->retrieveLoggedUserProfile->getProfile();
        $hasStarted = $round->startsAt <= $this->clock->now();

        return new RoundResultsPage(
            event: $event,
            round: $round,
            rounds: $rounds,
            previousRound: $rounds[$position - 1] ?? null,
            nextRound: $rounds[$position + 1] ?? null,
            results: $this->getRoundResults->forRound($round, $viewer?->playerId),
            hasStarted: $hasStarted,
            canAddTime: $viewer !== null && $hasStarted,
            officialResultsLink: $round->resultsLink !== null
                ? $round->resultsLink . (str_contains($round->resultsLink, '?') ? '&' : '?') . 'utm_source=myspeedpuzzling'
                : $event->resultsLink,
        );
    }
}
