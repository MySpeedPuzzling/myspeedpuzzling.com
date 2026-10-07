<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Results\CompetitionReference;
use SpeedPuzzling\Web\Results\CompetitionRoundForManagement;
use SpeedPuzzling\Web\Results\OfficialResultsRound;

/**
 * Every round of an event for the organiser's results tools (OfficialResultsRound), in the event's schedule order
 * (start, then id - the order that decides the automatic badge colours).
 */
readonly final class OfficialResultsRounds
{
    public function __construct(
        private GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private GetRoundResultsOverview $getRoundResultsOverview,
        private GetCompetitionEvents $getCompetitionEvents,
        private OfficialRoundPageUrl $officialRoundPageUrl,
    ) {
    }

    /**
     * Three statements: the rounds as the round list has them, their official results progress, the event's address
     * (for the rounds' public results pages).
     *
     * @return list<OfficialResultsRound>
     */
    public function forCompetition(string $competitionId): array
    {
        return $this->ofRounds(
            $competitionId,
            $this->getCompetitionRoundsForManagement->ofCompetition($competitionId),
            $this->getCompetitionEvents->referenceById($competitionId),
        );
    }

    /**
     * The progress of rounds a page has loaded already (one statement). Without the event's address the rounds have
     * no public page link.
     *
     * @param array<CompetitionRoundForManagement> $managementRounds
     * @return list<OfficialResultsRound>
     */
    public function ofRounds(string $competitionId, array $managementRounds, null|CompetitionReference $reference = null): array
    {
        $overviews = [];
        foreach ($this->getRoundResultsOverview->forCompetition($competitionId) as $overview) {
            $overviews[$overview->roundId] = $overview;
        }

        $rounds = [];
        foreach ($managementRounds as $round) {
            $overview = $overviews[$round->id] ?? null;

            if ($overview === null) {
                continue;
            }

            $rounds[] = new OfficialResultsRound(
                overview: $overview,
                color: $round->color,
                textColor: $round->textColor,
                timezone: $round->timezone,
                timezoneAssumed: $round->timezoneAssumed,
                publicUrl: $reference !== null ? $this->officialRoundPageUrl->of($reference, $overview->slug) : null,
            );
        }

        return $rounds;
    }
}
