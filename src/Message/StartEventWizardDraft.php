<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The event creation wizard's first save of Basics on the one-round path (W-creation-wizard-research.md §3.3): the
 * draft event and its single round in one transaction - the handler dispatches AddCompetition (always a draft) and
 * AddCompetitionRound inside its own doctrine_transaction, so a refused round leaves no event behind. An orchestration
 * of existing messages, no domain concept of its own.
 */
readonly final class StartEventWizardDraft
{
    public function __construct(
        public AddCompetition $competition,
        public AddCompetitionRound $round,
    ) {
        if ($competition->isDraft === false) {
            throw new \InvalidArgumentException('The wizard creates a draft - it is published on its Review step.');
        }

        if ($round->competitionId !== $competition->competitionId->toString()) {
            throw new \InvalidArgumentException('The round must belong to the new event.');
        }
    }
}
