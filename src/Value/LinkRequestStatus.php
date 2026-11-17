<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where a participant link request stands (docs/features/competitions-management/participant-linking.md §3.3).
 */
enum LinkRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    // The claimant took it back
    case Withdrawn = 'withdrawn';
    // Somebody else got linked to the row, the claimant claimed another row or got linked meanwhile, or the row was
    // removed - nobody decided it
    case Superseded = 'superseded';
}
