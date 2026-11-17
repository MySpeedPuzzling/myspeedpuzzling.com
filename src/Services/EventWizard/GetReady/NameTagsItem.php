<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;

/**
 * Print name tags - once somebody is going or a round has entries; a tip without a tick (printing leaves no data).
 * With a QR tag mode chosen (the Extras step's "Name tags with QR codes", W6 - for whom the toolkit is on) the row is
 * there from the start, saying the tags come with QR codes and can be printed once competitors are in.
 */
readonly final class NameTagsItem implements GetReadyItem
{
    public function order(): int
    {
        return 60;
    }

    public function row(GetReadyFacts $facts): null|GetReadyRow
    {
        $qr = $facts->toolkitAvailable && $facts->qrTagMode !== null;
        $nobodyYet = $facts->participantsGoing === 0 && $facts->hasEntries() === false;

        if ($nobodyYet && $qr === false) {
            return null;
        }

        return new GetReadyRow(
            key: 'name_tags',
            labelKey: $qr ? 'event_wizard.get_ready.name_tags_qr' : 'event_wizard.get_ready.name_tags',
            noteKey: $qr && $nobodyYet ? 'event_wizard.get_ready.name_tags_later' : null,
            done: null,
            route: 'competition_name_tags',
            routeParameters: ['competitionId' => $facts->competitionId],
            linkKey: 'event_wizard.get_ready.link.print',
        );
    }
}
