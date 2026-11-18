<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * No claim invitation can go out for this event (docs/features/competitions-management/participant-linking.md §5.5):
 * it is not publicly visible (a draft - the claim link would open nothing for the person), or it uses online timing
 * (its personal links link people instead, §5.6). Nothing was changed.
 */
final class ClaimInvitationsNotAvailable extends ConflictHttpException
{
    public const string NOT_PUBLIC = 'not_public';
    public const string ONLINE_TIMING = 'online_timing';

    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('No claim invitations for this event: ' . $reason);
    }
}
