<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * An organiser takes a player's referee rights for the competition away. Nothing they entered changes: results keep
 * "entered by" them. Removing somebody who is no referee does nothing. The caller checked COMPETITION_EDIT.
 */
readonly final class RemoveCompetitionReferee
{
    public function __construct(
        public string $competitionId,
        public string $playerId,
    ) {
    }
}
