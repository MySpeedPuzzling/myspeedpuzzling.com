<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Results\Bracket\Bracket;
use SpeedPuzzling\Web\Results\Bracket\BracketRound;
use SpeedPuzzling\Web\Results\StandingRoundScore;
use SpeedPuzzling\Web\Results\StandingRow;
use SpeedPuzzling\Web\Results\StandingStage;
use SpeedPuzzling\Web\Services\AdvancementSeeding;
use SpeedPuzzling\Web\Services\OfficialResultsRanking;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\StandingEntryInput;
use SpeedPuzzling\Web\Value\StandingMissing;
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
 * `missing_rule = as_zero_pieces` (the World Series no-show, world-series-rules.md §4.2): a counted round without a
 * score becomes NoShow = time limit × 60 + penalty × the round's no-show pieces (unknown = no penalty part) - for an
 * explicit "did not start" always, for no result / not in the round once the round is final (organiser preview: its
 * results are published - entrants still solving are "not yet", never no-shows). A round without a limit, an unfinished
 * result without a limit and a pair/team without people (`t:` key, W1-a) stay missing. A NoShow scores like any
 * round, so the entrant is complete and ranked by `order_rule`.
 *
 * Entrants are matched across rounds by `key` (participant id / line-up id). An entrant without a real score (finished
 * or unfinished) in any counted round is left out - also one whose every score is a NoShow, so the organiser preview
 * and the public page (which never sees an absentee) list the same people. Its name and people are the ones of the
 * last round (in the standing's order) it is in.
 *
 * A standing of one division (real events L2a) reads its rounds through StandingRoundInput::forDivision(): only the
 * entries of the division, the entrants of another division in a round are "not in the round" there (missing, never a
 * no-show - `outsideKeys`).
 *
 * stages() is the other mode - the final classification across a bracket (competition toolkit v2 S5), see there.
 *
 * @phpstan-type StageReach array{stage: int, position: int, round: StandingRoundInput, entry: StandingEntryInput}
 * @phpstan-type StageCandidate array{key: string, at: StageReach, group: int, qualified: bool, seed: array{bool, int, bool, float}}
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
            $appeared = 0;

            foreach ($rounds as $round) {
                $score = $byRound[$round->roundId] ?? new StandingRoundScore(
                    $round->roundId,
                    $round->counted ? StandingScoreStatus::NotInRound : StandingScoreStatus::NotCounted,
                );

                // An entrant of another division in this round (a standing of one division) played there - never a no-show
                if ($rules->missing === StandingMissing::AsZeroPieces && !in_array($key, $round->outsideKeys, true)) {
                    $score = self::noShow($rules, $round, $key, $score);
                }

                $perRound[$round->roundId] = $score;

                if ($score->status === StandingScoreStatus::Finished || $score->status === StandingScoreStatus::Unfinished) {
                    $appeared++;
                }

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

            if ($appeared === 0) {
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
     * The final classification across a bracket (`stages`, competition toolkit v2 S5, A-round-formats.md §3.5,
     * displays-standings.md §4.3) - pure. The bracket is the standing's category's (BracketLayout, the same stages as
     * the bracket page); `$rounds` are its rounds that the caller read, `counted` = the round may place anybody (public:
     * published; organiser: somebody is in it). A stage is **classified** once every round of it counts; the rounds of a
     * stage that is not are ignored.
     *
     * 1. An entrant's stage = the deepest classified stage it has an entry in (any entry - a "did not start" in the final
     *    was advanced there; the public view never sees one). Entrants across rounds by `key`, like sum(); an entrant
     *    without a ranked result in any classified round is left out (nobody is invented into the classification).
     * 2. Marked qualified there while the next stage is not classified yet: one unranked group "qualified for" the next
     *    stage, by name, in that stage's place (deeper stages first).
     * 3. Otherwise listed in its stage, deepest first, with its own round's place (`stageRank`). Not numbered: grouped
     *    per round of the stage (schedule order), by the place, unranked last - no combined number. Numbered: one
     *    number 1..N over everybody (`rank`) - within a stage across its parallel rounds by the advancement seed's keys
     *    (AdvancementSeeding::crossRoundKey(): the place, finished before unfinished, the result relative to the round's
     *    winner); equal keys share the number, the next skips ("1, 2, 2, 4"); everybody listed ahead counts, also an
     *    unranked row; a row without a ranked result in its stage round has no number, last in its stage.
     *
     * A row's perRound holds its stage round only (its time, missing pieces, challenge times); totalSeconds = that
     * round's score (time limit for an unfinished result), never a ranking input here.
     *
     * @param array<string, StandingRoundInput> $rounds round id (lower case) => the round
     * @return list<StandingRow>
     */
    public static function stages(Bracket $bracket, array $rounds, bool $numbered): array
    {
        $stages = self::stageList($bracket, $rounds);
        $rules = new StandingRules();
        /** @var array<string, StageReach> $reached */
        $reached = [];
        /** @var array<string, null|int> $winners */
        $winners = [];
        /** @var array<string, true> $ranked */
        $ranked = [];

        foreach ($stages as $index => $stage) {
            if ($stage->classified === false) {
                continue;
            }

            $position = 0;
            foreach (array_keys($stage->rounds) as $roundId) {
                $round = $rounds[$roundId];
                $winners[$roundId] = AdvancementSeeding::roundWinnerSeconds(array_map(static fn (StandingEntryInput $entry): RoundEntryResult => $entry->result, $round->entries));

                foreach (self::bestPerKey($round->entries) as $key => $entry) {
                    $key = (string) $key;

                    if ($entry->rank !== null) {
                        $ranked[$key] = true;
                    }

                    $current = $reached[$key] ?? null;

                    if ($current === null || $current['stage'] < $index || ($current['stage'] === $index && (self::placeOf($entry) <=> self::placeOf($current['entry'])) < 0)) {
                        $reached[$key] = ['stage' => $index, 'position' => $position, 'round' => $round, 'entry' => $entry];
                    }
                }

                $position++;
            }
        }

        /** @var list<StageCandidate> $candidates */
        $candidates = [];

        foreach ($reached as $key => $at) {
            $key = (string) $key;

            if (!isset($ranked[$key])) {
                continue;
            }

            $next = $stages[$at['stage'] + 1] ?? null;
            $entry = $at['entry'];

            $candidates[] = [
                'key' => $key,
                'at' => $at,
                // The group's place: deeper first; "qualified for" sits where the next stage would
                'group' => $entry->qualified && $next !== null && $next->classified === false ? $at['stage'] + 1 : $at['stage'],
                'qualified' => $entry->qualified && $next !== null && $next->classified === false,
                'seed' => AdvancementSeeding::crossRoundKey($entry->rank, $entry->result, $winners[$at['round']->roundId] ?? null, $entry->piecesCount),
            ];
        }

        usort($candidates, static function (array $a, array $b) use ($numbered): int {
            if ($a['group'] !== $b['group']) {
                return $b['group'] <=> $a['group'];
            }

            $aEntry = $a['at']['entry'];
            $bEntry = $b['at']['entry'];

            if ($a['qualified'] === false) {
                $order = $numbered
                    ? ($a['seed'] <=> $b['seed'] ?: $a['at']['position'] <=> $b['at']['position'])
                    : ($a['at']['position'] <=> $b['at']['position'] ?: self::placeOf($aEntry) <=> self::placeOf($bEntry));

                if ($order !== 0) {
                    return $order;
                }
            }

            return strcasecmp($aEntry->name, $bEntry->name) ?: strcmp($a['key'], $b['key']);
        });

        $rows = [];
        $number = null;
        $previous = null;

        foreach ($candidates as $index => $candidate) {
            $at = $candidate['at'];
            $entry = $at['entry'];
            $round = $at['round'];
            $rank = null;

            if ($numbered && $candidate['qualified'] === false && $entry->rank !== null) {
                if ($previous === null || $previous['group'] !== $candidate['group'] || $previous['seed'] !== $candidate['seed']) {
                    $number = $index + 1;
                }

                $rank = $number;
                $previous = $candidate;
            }

            $score = self::score($rules, $round, $entry);
            $stage = $stages[$candidate['group']];

            $rows[] = new StandingRow(
                key: $candidate['key'],
                rank: $rank,
                complete: $candidate['qualified'] === false && $entry->rank !== null,
                name: $entry->name,
                countries: $entry->countries,
                entrants: $entry->entrants,
                totalSeconds: $score->scoreSeconds ?? 0,
                roundsScored: $score->scoreSeconds !== null ? 1 : 0,
                roundsFinished: $score->status === StandingScoreStatus::Finished ? 1 : 0,
                perRound: [$round->roundId => $score],
                totalTimeSeconds: $score->timeSeconds ?? 0,
                totalMissingPieces: $score->missingPieces,
                totalPenaltySeconds: $score->penaltySeconds,
                stage: $stage->number,
                stageName: $stage->name,
                stageRoundId: $candidate['qualified'] ? null : $round->roundId,
                stageRank: $candidate['qualified'] ? null : $entry->rank,
                qualifiedForStage: $candidate['qualified'],
            );
        }

        return $rows;
    }

    /**
     * The stages of a bracket as a `stages` standing reads them (see stages()): numbered from 1, named by their rounds,
     * classified when every round of the stage is in `$rounds` and counts.
     *
     * @param array<string, StandingRoundInput> $rounds
     * @return list<StandingStage>
     */
    public static function stageList(Bracket $bracket, array $rounds): array
    {
        $stages = [];

        foreach ($bracket->stages as $index => $stageRounds) {
            $names = [];
            foreach ($stageRounds as $round) {
                $names[strtolower($round->id)] = $round->name;
            }

            $stages[] = new StandingStage(
                number: $index + 1,
                name: StandingStage::nameOf(array_map(static fn (BracketRound $round): string => $round->name, $stageRounds)),
                rounds: $names,
                classified: $names !== [] && array_all(array_keys($names), static fn (string $roundId): bool => ($rounds[$roundId] ?? null)?->counted === true),
            );
        }

        return $stages;
    }

    /**
     * An entry's place in its round for ordering: ranked by the place, unranked last.
     *
     * @return array{bool, int}
     */
    private static function placeOf(StandingEntryInput $entry): array
    {
        return [$entry->rank === null, $entry->rank ?? 0];
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
                challengeTimes: $entry->challengeTimes,
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
                challengeTimes: $entry->challengeTimes,
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
     * as_zero_pieces: a counted round's missing score as a no-show, when the rule can score it (see the class comment).
     */
    private static function noShow(StandingRules $rules, StandingRoundInput $round, string $key, StandingRoundScore $score): StandingRoundScore
    {
        $absent = match ($score->status) {
            StandingScoreStatus::DidNotStart => true,
            StandingScoreStatus::NoResult, StandingScoreStatus::NotInRound => $round->final,
            default => false,
        };

        // A pair/team without people is no line-up of anybody's (W1-a) - never scored as a no-show
        if ($absent === false || $round->minutesLimit === null || str_starts_with($key, 't:')) {
            return $score;
        }

        $missingPieces = max(0, $round->noShowPiecesCount ?? 0);
        $penalty = $rules->penaltySecondsPerPiece * $missingPieces;

        return new StandingRoundScore(
            $round->roundId,
            StandingScoreStatus::NoShow,
            $score->result,
            $round->minutesLimit * 60 + $penalty,
            null,
            timeSeconds: $round->minutesLimit * 60,
            missingPieces: $missingPieces,
            penaltySeconds: $penalty,
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
