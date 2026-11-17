<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Results\StandingRoundScore;
use SpeedPuzzling\Web\Results\StandingRow;
use SpeedPuzzling\Web\Services\OfficialResultsRanking;
use SpeedPuzzling\Web\Value\StandingEntryInput;
use SpeedPuzzling\Web\Value\StandingOrder;
use SpeedPuzzling\Web\Value\StandingRoundInput;
use SpeedPuzzling\Web\Value\StandingRules;
use SpeedPuzzling\Web\Value\StandingScoreStatus;

/**
 * The one `sum` rule of the competition toolkit's standings (A-round-formats.md §3.3) - pure and stateless (worker mode,
 * R-review-and-plan.md §2.5); the organiser's preview and the public page (A7) both compute through it.
 *
 * Round score of an entrant = time + penalty (competition toolkit T6 - both parts and the missing pieces are kept,
 * the pages show time, missing pieces and the penalised total):
 * - finished: its seconds; a finished puzzle that missed pieces (a piece missing from the factory) shows them, and they
 *   cost penalty × pieces only with `penaliseFinishedMissing` on (default off);
 * - unfinished: time limit × 60 + penalty × missing pieces (missing = the entry's pieces − pieces placed; unknown piece
 *   count = no penalty part). A round without a time limit cannot score an unfinished result - a missing round;
 * - did not start, no result, not in the round: missing.
 * A finished round with pieces missing is still a finished round for `finished_rounds_first`.
 * A round that does not count (`counted` false) scores nobody and makes nobody missing.
 *
 * Order (`missing_rule = incomplete`): every complete entrant (a score in each counted round) first, ranked by
 * `order_rule` - `finished_rounds_first`: rounds finished descending, then the total ascending; `total_only`: the total.
 * Equal keys share a rank, the next rank skips ("1, 2, 2, 4"), no hidden tie-break. Then the incomplete entrants
 * without a rank, by rounds scored descending, then the same rule. Ties are listed by name, then key.
 *
 * Entrants are matched across rounds by `key` (participant id / line-up id). An entrant with no score in any counted
 * round is left out. Its name and people are the ones of the last round (in the standing's order) it is in.
 */
final readonly class StandingCalculator
{
    /**
     * @param list<StandingRoundInput> $rounds in the standing's order
     * @return list<StandingRow>
     */
    public static function sum(StandingRules $rules, array $rounds): array
    {
        $countedRounds = 0;
        /** @var array<string, array<string, StandingRoundScore>> $scores key => round id => score */
        $scores = [];
        /** @var array<string, StandingEntryInput> $latest */
        $latest = [];

        foreach ($rounds as $round) {
            if ($round->counted === false) {
                continue;
            }

            $countedRounds++;

            foreach (self::bestPerKey($round->entries) as $key => $entry) {
                $scores[$key][$round->roundId] = self::score($rules, $round, $entry);
                $latest[$key] = $entry;
            }
        }

        $rows = [];

        foreach ($scores as $key => $byRound) {
            $key = (string) $key;
            $perRound = [];
            $total = 0;
            $totalTime = 0;
            $totalMissing = 0;
            $totalPenalty = 0;
            $scored = 0;
            $finished = 0;

            foreach ($rounds as $round) {
                $score = $byRound[$round->roundId] ?? new StandingRoundScore(
                    $round->roundId,
                    $round->counted ? StandingScoreStatus::NotInRound : StandingScoreStatus::NotCounted,
                );
                $perRound[$round->roundId] = $score;

                if ($score->scoreSeconds !== null) {
                    $total += $score->scoreSeconds;
                    $totalTime += $score->timeSeconds ?? $score->scoreSeconds;
                    $totalMissing += $score->missingPieces;
                    $totalPenalty += $score->penaltySeconds;
                    $scored++;
                }

                if ($score->status === StandingScoreStatus::Finished) {
                    $finished++;
                }
            }

            if ($scored === 0) {
                continue;
            }

            $entry = $latest[$key];
            $rows[] = new StandingRow(
                key: $key,
                rank: null,
                complete: $scored === $countedRounds,
                name: $entry->name,
                countries: $entry->countries,
                entrants: $entry->entrants,
                totalSeconds: $total,
                roundsScored: $scored,
                roundsFinished: $finished,
                perRound: $perRound,
                totalTimeSeconds: $totalTime,
                totalMissingPieces: $totalMissing,
                totalPenaltySeconds: $totalPenalty,
            );
        }

        usort($rows, static fn (StandingRow $a, StandingRow $b): int => self::compare($rules, $a, $b)
            ?: strcasecmp($a->name, $b->name)
            ?: strcmp($a->key, $b->key));

        return self::ranked($rules, $rows);
    }

    /**
     * The standing's place order without the name tie-break: complete first, then the rule. 0 = the same place.
     */
    private static function compare(StandingRules $rules, StandingRow $a, StandingRow $b): int
    {
        if ($a->complete !== $b->complete) {
            return $a->complete ? -1 : 1;
        }

        if ($a->complete === false && $a->roundsScored !== $b->roundsScored) {
            return $b->roundsScored <=> $a->roundsScored;
        }

        if ($rules->order === StandingOrder::FinishedRoundsFirst && $a->roundsFinished !== $b->roundsFinished) {
            return $b->roundsFinished <=> $a->roundsFinished;
        }

        return $a->totalSeconds <=> $b->totalSeconds;
    }

    /**
     * @param list<StandingRow> $rows sorted
     * @return list<StandingRow>
     */
    private static function ranked(StandingRules $rules, array $rows): array
    {
        $ranked = [];
        $rank = 0;
        $previous = null;

        foreach ($rows as $index => $row) {
            if ($row->complete === false) {
                $ranked[] = $row;

                continue;
            }

            if ($previous === null || self::compare($rules, $previous, $row) !== 0) {
                $rank = $index + 1;
            }

            $previous = $row;
            $ranked[] = new StandingRow(
                key: $row->key,
                rank: $rank,
                complete: true,
                name: $row->name,
                countries: $row->countries,
                entrants: $row->entrants,
                totalSeconds: $row->totalSeconds,
                roundsScored: $row->roundsScored,
                roundsFinished: $row->roundsFinished,
                perRound: $row->perRound,
                totalTimeSeconds: $row->totalTimeSeconds,
                totalMissingPieces: $row->totalMissingPieces,
                totalPenaltySeconds: $row->totalPenaltySeconds,
            );
        }

        return $ranked;
    }

    private static function score(StandingRules $rules, StandingRoundInput $round, StandingEntryInput $entry): StandingRoundScore
    {
        $result = $entry->result;

        if ($result->seconds !== null) {
            $missingPieces = max(0, $entry->missingPieces ?? 0);
            $penalty = $rules->penaliseFinishedMissing ? $rules->penaltySecondsPerPiece * $missingPieces : 0;

            return new StandingRoundScore(
                $round->roundId,
                StandingScoreStatus::Finished,
                $result,
                $result->seconds + $penalty,
                $entry->rank,
                timeSeconds: $result->seconds,
                missingPieces: $missingPieces,
                penaltySeconds: $penalty,
            );
        }

        if ($result->piecesPlaced !== null) {
            if ($round->minutesLimit === null) {
                return new StandingRoundScore($round->roundId, StandingScoreStatus::UnfinishedWithoutLimit, $result, null, $entry->rank);
            }

            $missingPieces = $entry->piecesCount !== null ? max(0, $entry->piecesCount - $result->piecesPlaced) : 0;
            $penalty = $rules->penaltySecondsPerPiece * $missingPieces;

            return new StandingRoundScore(
                $round->roundId,
                StandingScoreStatus::Unfinished,
                $result,
                $round->minutesLimit * 60 + $penalty,
                $entry->rank,
                timeSeconds: $round->minutesLimit * 60,
                missingPieces: $missingPieces,
                penaltySeconds: $penalty,
            );
        }

        return new StandingRoundScore(
            $round->roundId,
            $result->didNotStart ? StandingScoreStatus::DidNotStart : StandingScoreStatus::NoResult,
            $result,
            null,
            $entry->rank,
        );
    }

    /**
     * One entry per entrant in a round - should the same people be in two entries of one round, the better result
     * counts (OfficialResultsRanking's order).
     *
     * @param list<StandingEntryInput> $entries
     * @return array<string, StandingEntryInput>
     */
    private static function bestPerKey(array $entries): array
    {
        $best = [];

        foreach ($entries as $entry) {
            $current = $best[$entry->key] ?? null;

            if ($current === null || OfficialResultsRanking::compare($entry->result, $current->result) < 0) {
                $best[$entry->key] = $entry;
            }
        }

        return $best;
    }

    /**
     * Whether any result is recorded in a round (ranked or did not start) - what makes a round count in the organiser's
     * provisional view.
     *
     * @param list<StandingEntryInput> $entries
     */
    public static function anyRecorded(array $entries): bool
    {
        return array_any($entries, static fn (StandingEntryInput $entry): bool => $entry->result->isNone() === false);
    }
}
