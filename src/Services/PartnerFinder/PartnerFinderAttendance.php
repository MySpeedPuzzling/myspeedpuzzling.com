<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PartnerFinder;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Services\CompetitionSelfJoin;

/**
 * Listing yourself, or asking somebody, in the partner finder marks you going in the same step on a plain event
 * (docs/features/partner-finder.md D2) - the "I'm going" of JoinCompetitionHandler, through the same code
 * (CompetitionSelfJoin), no nested dispatch. An event that manages registration is left alone: a pair often cannot
 * register before it has its partner, so listing needs no spot.
 *
 * The caller has checked the event (PartnerFinderEligibility - publicly visible, in person, not over) and runs under
 * CompetitionParticipantsLock (its message is SerializedByLock with CompetitionParticipantsLock::key($competitionId)).
 */
readonly final class PartnerFinderAttendance
{
    public function __construct(
        private GetCompetitionParticipants $getCompetitionParticipants,
        private CompetitionSelfJoin $competitionSelfJoin,
    ) {
    }

    /**
     * True when the player was made going now. A player already connected to a row of the event - their own or one of
     * the organiser's list - is going already and keeps that row (unlike "I'm going", which lets an organiser's row go).
     */
    public function ensureGoing(Competition $competition, Player $player): bool
    {
        if ($competition->registrationManaged) {
            return false;
        }

        if ($this->getCompetitionParticipants->getPlayerConnections($competition->id->toString(), $player->id->toString()) !== []) {
            return false;
        }

        $participant = $this->competitionSelfJoin->takeOwnRow($competition, $player);
        $this->competitionSelfJoin->handOwnLink($participant);

        return true;
    }
}
