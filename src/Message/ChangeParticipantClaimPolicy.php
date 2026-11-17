<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ParticipantClaimPolicy;

/**
 * The organiser's claim policy of one event (docs/features/competitions-management/participant-linking.md D3, §5.4) -
 * null = automatic. Saved from the competition toolkit hub's "Participants & profiles" card. Touches only the
 * competition row, like ChangeCompetitorPageSettings, so it needs no participants lock: switching to `open` approves
 * nothing that waits (the organiser decides those; a claimant who claims again is linked at once).
 */
readonly final class ChangeParticipantClaimPolicy
{
    public function __construct(
        public string $competitionId,
        public null|ParticipantClaimPolicy $policy,
    ) {
    }
}
