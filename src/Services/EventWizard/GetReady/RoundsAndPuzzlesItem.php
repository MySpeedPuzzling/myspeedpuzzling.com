<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;

/**
 * Round(s) and puzzle(s): done when the event has a round and every round has at least one puzzle. No round yet → the
 * rounds page; a round without a puzzle ("Decide later" in the wizard) → that round's puzzles.
 */
readonly final class RoundsAndPuzzlesItem implements GetReadyItem
{
    public function order(): int
    {
        return 20;
    }

    public function row(GetReadyFacts $facts): GetReadyRow
    {
        $count = count($facts->rounds);

        if ($count === 0) {
            return new GetReadyRow(
                key: 'rounds',
                labelKey: 'event_wizard.get_ready.rounds',
                done: false,
                labelParameters: ['%count%' => 1],
                route: 'manage_competition_rounds',
                routeParameters: ['competitionId' => $facts->competitionId],
                linkKey: 'event_wizard.get_ready.link.add_round',
                noteKey: 'event_wizard.get_ready.rounds_none',
            );
        }

        foreach ($facts->rounds as $round) {
            if ($round->puzzlesCount === 0) {
                return new GetReadyRow(
                    key: 'rounds',
                    labelKey: 'event_wizard.get_ready.rounds',
                    done: false,
                    labelParameters: ['%count%' => $count],
                    route: 'manage_round_puzzles',
                    routeParameters: ['roundId' => $round->roundId],
                    linkKey: 'event_wizard.get_ready.link.add_puzzle',
                    noteKey: $count === 1 ? 'event_wizard.get_ready.round_without_puzzle' : 'event_wizard.get_ready.named_round_without_puzzle',
                    noteParameters: ['%round%' => $round->name],
                );
            }
        }

        return new GetReadyRow(
            key: 'rounds',
            labelKey: 'event_wizard.get_ready.rounds',
            done: true,
            labelParameters: ['%count%' => $count],
        );
    }
}
