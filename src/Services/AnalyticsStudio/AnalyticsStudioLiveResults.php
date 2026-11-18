<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use SpeedPuzzling\Web\Results\AnalyticsStudioEntrant;
use SpeedPuzzling\Web\Results\AnalyticsStudioRound;
use SpeedPuzzling\Web\Results\OfficialRoundEntrants;
use SpeedPuzzling\Web\Results\PublishedEntryPuzzle;
use SpeedPuzzling\Web\Results\RoundPuzzlesNow;
use SpeedPuzzling\Web\Services\OfficialResultsRanking;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\UnfinishedPiecesCount;

/**
 * The results the analytics studio shows (analytics-studio.md §6, as amended by FU-S): one ranking, no
 * "official"/"unofficial" distinction - what the judges and the desk recorded is the result (Jan, 2026-11-18); pure:
 *
 * - a published round: the official rows (OfficialEntrantsView - ranks and qualified marks as the round page has them,
 *   never re-ranked here), in rank order;
 * - not published yet, while the event shows live results (competitor pages AND their live results - the setting the
 *   competitor page follows; FU-R2, Jan 2026-11-18): the live ranking of the recorded results (the entries' result
 *   columns - what a judge or the desk entered; a competitor's time proposal is never one until a judge confirms it),
 *   ranked by OfficialResultsRanking (the round page's rule, ties share a place), in rank order; no qualified mark (the
 *   organiser decides that when publishing); did not start and no result yet are left out, as on the published round;
 * - the same live ranking for the event's staff (organisers, maintainers, referees - FU-ST, Jan 2026-11-19) whatever
 *   the setting: they are not the public, they watch the round they run;
 * - otherwise nothing - the ranking comes with publishing.
 *
 * Every row says `state: "official"` - the one state the page knows.
 *
 * A pick entry's chosen puzzles come by their labels (PublishedEntryPuzzle::listOf() - a hidden one is a label only);
 * the pieces of an unfinished result only when the public may know them (UnfinishedPiecesCount).
 */
final class AnalyticsStudioLiveResults
{
    /**
     * @param list<AnalyticsStudioEntrant> $entrants
     * @param bool $staff the event's staff (FU-ST: its organisers, maintainers and referees) see the live ranking whatever
     *                    the live results setting
     * @return list<array{key: string, state: string, seconds: null|int, piecesPlaced: null|int, piecesCount: null|int, rank: null|int, qualified: bool, puzzles?: list<array{label: string, done: bool, seconds: null|int, piecesPlaced: null|int}>}>
     */
    public static function of(AnalyticsStudioRound $round, array $entrants, RoundPuzzlesNow $puzzles, null|OfficialRoundEntrants $official, bool $staff = false): array
    {
        $results = [];

        if ($round->published()) {
            foreach ($official->entries ?? [] as $entry) {
                $results[] = self::row($round, $entry->ref->id, $entry->result, $entry->puzzles, $entry->rank, $entry->qualified, $puzzles);
            }

            return $results;
        }

        if ($round->liveResults === false && $staff === false) {
            return [];
        }

        $ranked = array_values(array_filter(
            $entrants,
            static fn (AnalyticsStudioEntrant $entrant): bool => $entrant->result->isRanked(),
        ));
        $ranks = OfficialResultsRanking::rank(array_map(static fn (AnalyticsStudioEntrant $entrant): RoundEntryResult => $entrant->result, $ranked));

        // Rank order; equal places keep the entrants' order (by name) - usort is stable
        $order = array_keys($ranked);
        usort($order, static fn (int $a, int $b): int => ($ranks[$a] ?? PHP_INT_MAX) <=> ($ranks[$b] ?? PHP_INT_MAX));

        foreach ($order as $index) {
            $entrant = $ranked[$index];
            $results[] = self::row($round, $entrant->key(), $entrant->result, $entrant->puzzles, $ranks[$index], false, $puzzles);
        }

        return $results;
    }

    /**
     * @return array{key: string, state: string, seconds: null|int, piecesPlaced: null|int, piecesCount: null|int, rank: null|int, qualified: bool, puzzles?: list<array{label: string, done: bool, seconds: null|int, piecesPlaced: null|int}>}
     */
    private static function row(
        AnalyticsStudioRound $round,
        string $key,
        RoundEntryResult $result,
        null|RoundEntryPuzzles $record,
        null|int $rank,
        bool $qualified,
        RoundPuzzlesNow $puzzles,
    ): array {
        $chips = $round->isPick() ? PublishedEntryPuzzle::listOf($record, $puzzles->slots) : [];

        $row = [
            'key' => $key,
            'state' => 'official',
            'seconds' => $result->seconds,
            'piecesPlaced' => $result->piecesPlaced,
            'piecesCount' => $result->isUnfinished() ? UnfinishedPiecesCount::of($round->isPick(), $round->puzzlesToSolve, $chips, $puzzles) : null,
            'rank' => $rank,
            'qualified' => $qualified,
        ];

        if ($round->isPick()) {
            $row['puzzles'] = array_map(static fn (PublishedEntryPuzzle $chip): array => [
                'label' => $chip->label,
                'done' => $chip->done,
                'seconds' => $chip->duration,
                'piecesPlaced' => $chip->piecesPlaced,
            ], $chips);
        }

        return $row;
    }
}
