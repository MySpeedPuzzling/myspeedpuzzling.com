<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;

/**
 * Competition toolkit tip: "Help people link their profiles" (docs/features/competitions-management/participant-linking.md
 * §7.5) - once somebody on the list has no MySpeedPuzzling profile linked and the event starts within 14 days (or has
 * started). Links the toolkit hub's "Participants & profiles" card (claim links, slips, invitations, suggestions).
 * Without a tick: linking is the players' own act - nothing the organiser does can finish it - so the row never holds
 * "n of m done" back. Not for a draft (nobody can claim a row of an event that is not public yet) or an event without a
 * date (the 14 days count from it).
 */
readonly final class ParticipantLinksTip implements GetReadyItem
{
    public const int DAYS_BEFORE = 14;

    public function order(): int
    {
        return 65;
    }

    public function row(GetReadyFacts $facts): null|GetReadyRow
    {
        if (
            $facts->toolkitAvailable === false
            || $facts->isDraft
            || $facts->participantsNotLinked === 0
            || $facts->dateFrom === null
            || $facts->dateFrom > $facts->now->modify('+' . self::DAYS_BEFORE . ' days')
        ) {
            return null;
        }

        return new GetReadyRow(
            key: 'participant_links',
            labelKey: 'event_wizard.get_ready.participant_links',
            done: null,
            route: 'competition_toolkit',
            routeParameters: ['competitionId' => $facts->competitionId, '_fragment' => 'participant-links'],
            linkKey: 'event_wizard.get_ready.link.open',
            noteKey: 'event_wizard.get_ready.participant_links_note',
            noteParameters: ['%count%' => $facts->participantsNotLinked],
        );
    }
}
