<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The status an organiser sees on "You organize" (docs/features/events-page/README.md). Rejected wins over everything,
 * then waiting for approval.
 */
enum OrganizerBadge: string
{
    case WaitingForApproval = 'waiting_for_approval';
    case Rejected = 'rejected';
    case Live = 'live';
    case Upcoming = 'upcoming';
    case Past = 'past';
    case DateNotSet = 'date_not_set';

    public function translationKey(): string
    {
        return 'events_organizer.badge.' . $this->value;
    }
}
