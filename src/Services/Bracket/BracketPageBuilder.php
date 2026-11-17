<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Bracket;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\BracketNotFound;
use SpeedPuzzling\Web\Query\GetBracketRounds;
use SpeedPuzzling\Web\Query\GetCompetitionAdvancementSteps;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Query\OfficialEntrantsView;
use SpeedPuzzling\Web\Results\Bracket\Bracket;
use SpeedPuzzling\Web\Results\Bracket\BracketPage;
use SpeedPuzzling\Web\Results\Bracket\BracketRound;
use SpeedPuzzling\Web\Services\CompetitionToolkit\BracketLayout;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\EventTitle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The public bracket page (competition toolkit A9, A-round-formats.md §3.5) - shared by the event route and the series
 * edition route. 404 for an event that is not public (a draft, unapproved - for its team too, like the round results
 * and standings pages) and for an event without declared advancement steps.
 *
 * Statements: visibility, the event (which brings the rounds of its steps along), the steps, the rounds, and at most
 * two for the entrants of the published rounds (OfficialEntrantsView - published only, hidden players dropped, private
 * ones masked). No final classification across stages in v1 (R-review-and-plan.md §3).
 */
readonly final class BracketPageBuilder
{
    public function __construct(
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private GetCompetitionEvents $getCompetitionEvents,
        private GetCompetitionAdvancementSteps $getCompetitionAdvancementSteps,
        private GetBracketRounds $getBracketRounds,
        private OfficialEntrantsView $officialEntrantsView,
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string $roundRoute event_round_results | edition_round_results
     * @param array<string, string> $routeParams the event's part of the round route's parameters
     * @throws BracketNotFound
     */
    public function build(string $competitionId, string $roundRoute, array $routeParams, null|string $seriesName = null): BracketPage
    {
        if ($this->isCompetitionPubliclyVisible->check($competitionId) === false) {
            throw new BracketNotFound();
        }

        $event = $this->getCompetitionEvents->byId($competitionId);

        if ($event->hasBracket() === false) {
            throw new BracketNotFound();
        }

        $brackets = BracketLayout::of(
            $this->getCompetitionAdvancementSteps->ofCompetition($competitionId),
            $this->getBracketRounds->ofCompetition($competitionId),
        );

        $published = [];
        foreach ($brackets as $bracket) {
            foreach ($bracket->rounds() as $round) {
                if ($round->isPublished()) {
                    $published[$round->id] = $round->category;
                }
            }
        }

        $entrants = $published !== []
            ? $this->officialEntrantsView->forRounds($published, $this->retrieveLoggedUserProfile->getProfile()?->playerId)
            : [];

        $brackets = array_map(fn (Bracket $bracket): Bracket => $bracket->mapRounds(function (BracketRound $round) use ($entrants, $roundRoute, $routeParams): BracketRound {
            if (!isset($entrants[$round->id])) {
                return $round;
            }

            $round = $round->withEntrants($entrants[$round->id]);

            if ($round->slug === null || $round->slug === '') {
                return $round;
            }

            return $round->withUrl($this->urlGenerator->generate($roundRoute, $routeParams + ['roundSlug' => $round->slug]));
        }), $brackets);

        return new BracketPage(
            event: $event,
            eventTitle: EventTitle::forCompetition($event, $seriesName, [], $this->clock->now()),
            brackets: $brackets,
        );
    }
}
