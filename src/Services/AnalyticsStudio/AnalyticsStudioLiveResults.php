<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use SpeedPuzzling\Web\Results\AnalyticsStudioEntrant;
use SpeedPuzzling\Web\Results\AnalyticsStudioRound;
use SpeedPuzzling\Web\Results\OfficialRoundEntrants;
use SpeedPuzzling\Web\Results\PublishedEntryPuzzle;
use SpeedPuzzling\Web\Results\RoundPuzzlesNow;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\UnfinishedPiecesCount;

/**
 * The results the analytics studio shows (analytics-studio.md §6, D8, R8) - the competitor page's rule exactly
 * (GetCompetitorPage::entry()), so the two surfaces never disagree; pure:
 *
 * - a published round: the official rows (OfficialEntrantsView - ranks and qualified marks as the round page has them,
 *   never re-ranked here), in rank order;
 * - not published while the event shows confirmed results (competitor pages AND their live results): each entry's
 *   confirmed result as "live" - no rank, no qualified mark, never "Did not start", in the entrants' order (R8: not a
 *   leaderboard);
 * - otherwise nothing.
 *
 * A pick entry's chosen puzzles come by their labels (PublishedEntryPuzzle::listOf() - a hidden one is a label only);
 * the pieces of an unfinished result only when the public may know them (UnfinishedPiecesCount).
 */
final class AnalyticsStudioLiveResults
{
    /**
     * @param list<AnalyticsStudioEntrant> $entrants
     * @return list<array{key: string, state: string, seconds: null|int, piecesPlaced: null|int, piecesCount: null|int, rank: null|int, qualified: bool, puzzles?: list<array{label: string, done: bool, seconds: null|int, piecesPlaced: null|int}>}>
     */
    public static function of(AnalyticsStudioRound $round, array $entrants, RoundPuzzlesNow $puzzles, null|OfficialRoundEntrants $official): array
    {
        $results = [];

        if ($round->published()) {
            foreach ($official->entries ?? [] as $entry) {
                $results[] = self::row($round, $entry->ref->id, 'official', $entry->result, $entry->puzzles, $entry->rank, $entry->qualified, $puzzles);
            }

            return $results;
        }

        if ($round->liveResults === false) {
            return [];
        }

        foreach ($entrants as $entrant) {
            if ($entrant->result->isNone() || $entrant->result->didNotStart) {
                continue;
            }

            $results[] = self::row($round, $entrant->key(), 'live', $entrant->result, $entrant->puzzles, null, false, $puzzles);
        }

        return $results;
    }

    /**
     * @return array{key: string, state: string, seconds: null|int, piecesPlaced: null|int, piecesCount: null|int, rank: null|int, qualified: bool, puzzles?: list<array{label: string, done: bool, seconds: null|int, piecesPlaced: null|int}>}
     */
    private static function row(
        AnalyticsStudioRound $round,
        string $key,
        string $state,
        RoundEntryResult $result,
        null|RoundEntryPuzzles $record,
        null|int $rank,
        bool $qualified,
        RoundPuzzlesNow $puzzles,
    ): array {
        $chips = $round->isPick() ? PublishedEntryPuzzle::listOf($record, $puzzles->slots) : [];

        $row = [
            'key' => $key,
            'state' => $state,
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
