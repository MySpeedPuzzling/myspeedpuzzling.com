<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a participant link request asks (docs/features/competitions-management/participant-linking.md D2, §3.3).
 */
enum LinkRequestKind: string
{
    // The row is not linked - the event's policy is `approval`
    case Claim = 'claim';
    // The row is linked to another account - always the organiser's call
    case Dispute = 'dispute';
}
