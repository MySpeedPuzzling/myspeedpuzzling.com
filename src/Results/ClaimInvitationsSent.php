<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ClaimInvitationSkip;

/**
 * What SendParticipantClaimInvitations did (participant-linking.md §5.5): how many invitations went out and how many
 * people were skipped, by reason - ClaimInvitationSkip values, only the reasons that happened.
 */
readonly final class ClaimInvitationsSent
{
    /**
     * @param array<string, int> $skipped ClaimInvitationSkip value => people
     */
    public function __construct(
        public int $sent,
        public array $skipped,
    ) {
    }

    public function skippedFor(ClaimInvitationSkip $reason): int
    {
        return $this->skipped[$reason->value] ?? 0;
    }
}
