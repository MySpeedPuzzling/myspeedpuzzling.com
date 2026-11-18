<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * One claim invitation e-mail (docs/features/competitions-management/participant-linking.md §5.5, §9.1) - queued by
 * SendParticipantClaimInvitations after the invitation was recorded, sent async. Carries the address it was recorded
 * for: a changed or erased address meanwhile gets nothing.
 */
readonly final class SendParticipantClaimInvitation
{
    public function __construct(
        public string $participantId,
        public string $email,
    ) {
    }
}
