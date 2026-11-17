<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;

/**
 * Registration - only while the event manages registration (docs/features/competitions-management/registration.md):
 * ✓ with "x of y places taken" (or "x registered" without a capacity). Without managed registration: no row.
 */
readonly final class RegistrationItem implements GetReadyItem
{
    public function order(): int
    {
        return 40;
    }

    public function row(GetReadyFacts $facts): null|GetReadyRow
    {
        if ($facts->registrationManaged === false) {
            return null;
        }

        return new GetReadyRow(
            key: 'registration',
            labelKey: 'event_wizard.get_ready.registration',
            done: true,
            route: 'manage_competition_registration',
            routeParameters: ['competitionId' => $facts->competitionId],
            linkKey: 'event_wizard.get_ready.link.open',
            noteKey: $facts->capacity !== null ? 'event_wizard.get_ready.places_taken' : 'event_wizard.get_ready.registered',
            noteParameters: $facts->capacity !== null
                ? ['%taken%' => $facts->participantsGoing, '%capacity%' => $facts->capacity]
                : ['%count%' => $facts->participantsGoing],
        );
    }
}
