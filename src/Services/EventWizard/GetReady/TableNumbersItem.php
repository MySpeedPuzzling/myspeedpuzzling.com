<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;

/**
 * Assign table numbers - only for an in-person event once a round that uses table numbers has entries (the seating
 * page's rule, docs/features/competitions-management/seating.md): ✓ when every entry of those rounds has a table.
 * Links the first round still missing tables.
 */
readonly final class TableNumbersItem implements GetReadyItem
{
    public function order(): int
    {
        return 50;
    }

    public function row(GetReadyFacts $facts): null|GetReadyRow
    {
        if ($facts->isOnline) {
            return null;
        }

        $total = 0;
        $assigned = 0;
        $link = null;
        $first = null;

        foreach ($facts->rounds as $round) {
            if ($round->tableNumbersOff || $round->entriesTotal === 0) {
                continue;
            }

            $total += $round->entriesTotal;
            $assigned += min($round->entriesWithTableNumber, $round->entriesTotal);

            if ($link === null && $round->entriesWithTableNumber < $round->entriesTotal) {
                $link = $round->roundId;
            }

            $first ??= $round->roundId;
        }

        if ($first === null) {
            return null;
        }

        return new GetReadyRow(
            key: 'tables',
            labelKey: 'event_wizard.get_ready.tables',
            done: $link === null,
            route: 'round_seating',
            routeParameters: ['roundId' => $link ?? $first],
            linkKey: 'event_wizard.get_ready.link.seating',
            noteKey: 'event_wizard.get_ready.tables_assigned',
            noteParameters: ['%assigned%' => $assigned, '%total%' => $total],
        );
    }
}
