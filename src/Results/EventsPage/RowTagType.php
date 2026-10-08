<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

/**
 * The tags of an agenda row, in display order (docs/features/events-page/implementation-plan.md, 1.5).
 */
enum RowTagType: string
{
    case WaitingForApproval = 'waiting_for_approval';
    // only its team sees a draft's row (docs/features/organizations/README.md)
    case Draft = 'draft';
    case Going = 'going';
    case Recurring = 'recurring';
    // "Who can enter" - RowTag::$text
    case Eligibility = 'eligibility';
    // only an external registration link - never claims registration is open
    case Registration = 'registration';
    case RegistrationOpen = 'registration_open';
    case RegistrationOpens = 'registration_opens';
    case RegistrationClosed = 'registration_closed';
    case FullWaitlist = 'full_waitlist';
    case Results = 'results';
    case RunsUntil = 'runs_until';
    case GoingCount = 'going_count';

    public function translationKey(): string
    {
        return 'events_page.tag.' . $this->value;
    }
}
