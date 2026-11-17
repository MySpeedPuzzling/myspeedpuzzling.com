<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Query\EventIsOnline;
use SpeedPuzzling\Web\Query\GetCompetitionDisplays;
use SpeedPuzzling\Web\Query\GetCompetitionReferees;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Results\CompetitionDisplayOverview;
use SpeedPuzzling\Web\Results\EventWizardExtrasState;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * Reads what an event has of the Extras step's features (W-creation-wizard-research.md §3.8) - for the step and for
 * Review. Organiser tooling: the caller checked COMPETITION_EDIT; $toolkit = COMPETITION_TOOLKIT_MANAGE (the toolkit's
 * own data is read only then).
 */
readonly final class EventWizardExtras
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private GetCompetitionReferees $getCompetitionReferees,
        private GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private GetCompetitionDisplays $getCompetitionDisplays,
        private GetCompetitionStandings $getCompetitionStandings,
    ) {
    }

    public function of(string $competitionId, bool $toolkit): EventWizardExtrasState
    {
        $competition = $this->competitionRepository->get($competitionId);
        $rounds = $this->getCompetitionRoundsForManagement->ofCompetition($competitionId);
        $displays = $toolkit ? $this->getCompetitionDisplays->ofCompetition($competitionId) : [];
        $countKind = static fn (DisplayKind $kind): int => count(array_filter($displays, static fn (CompetitionDisplayOverview $display): bool => $display->kind === $kind));

        // The registration page's zone: the saved one, else of the event's rounds, else of its country
        $timezone = $competition->registrationTimezone !== null && RoundTimezone::isValid($competition->registrationTimezone)
            ? $competition->registrationTimezone
            : ($rounds !== [] ? $rounds[array_key_last($rounds)]->timezone : $competition->registrationZone());

        return new EventWizardExtrasState(
            competitionId: $competition->id->toString(),
            isOnline: EventIsOnline::of($competition),
            registrationManaged: $competition->registrationManaged,
            capacity: $competition->capacity,
            registrationOpensAt: $competition->registrationOpensAt,
            registrationClosesAt: $competition->registrationClosesAt,
            registrationTimezone: $timezone,
            entryFeeText: $competition->entryFeeText,
            paymentInstructions: $competition->paymentInstructions,
            registrationLink: $competition->registrationLink,
            resultsLink: $competition->resultsLink,
            referees: $this->getCompetitionReferees->ofCompetition($competitionId),
            competitorPageSettings: $competition->competitorPageSettings(),
            screensCount: $countKind(DisplayKind::Screen),
            overlaysCount: $countKind(DisplayKind::Overlay),
            standingsCount: $toolkit && count($rounds) >= 2 ? count($this->getCompetitionStandings->ofCompetition($competitionId)) : 0,
            roundsCount: count($rounds),
            toolkit: $toolkit,
        );
    }
}
