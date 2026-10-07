<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use SpeedPuzzling\Web\Value\RegistrationStatus;

/**
 * The one "is going" rule for a competition_participant row (docs/features/competitions-management/registration.md):
 * not deleted, and not on the waitlist of an event with managed registration. Every reader of "who is going" embeds it -
 * the attendance block, the public participant list, the marketplace at events, series edition counts, the players
 * directory and suggestions.
 *
 * Rows of events without managed registration have no registration status (NULL), so for them the rule is exactly
 * "not deleted", as before managed registration existed. Switching management off promotes the waitlist
 * (ChangeCompetitionRegistrationSettingsHandler), so a waitlisted row only exists on an event that manages registration.
 */
final class CompetitionParticipantGoing
{
    public static function sql(string $alias): string
    {
        $waitlisted = RegistrationStatus::Waitlisted->value;

        return "{$alias}.deleted_at IS NULL AND {$alias}.registration_status IS DISTINCT FROM '{$waitlisted}'";
    }
}
