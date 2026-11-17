<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\SeededEntry;

/**
 * The advancement seed - one order of the entries of one or more earlier rounds, best first
 * (docs/features/competitions-management/official-results.md). AdvanceQualified distributes by it ("balanced" =
 * serpentine 1→T1, 2→T2, 3→T2, 4→T1, ...) and seating proposals number tables by it.
 *
 * 1. rank within the own round (OfficialResultsRanking) - every round's winner before every round's second, ...
 * 2. the same rank (a tie, or the same place in different rounds): finished before unfinished, then the result
 *    relative to the round's winner - time ÷ the winner's time (a 1:05 in a round won in 1:00 beats a 1:10 in a round
 *    won in 1:05); unfinished by pieces placed ÷ the round's piece count (or pieces placed without one), most first -
 *    in a `pick` round the pieces of the entry's chosen puzzles, else of the round's K biggest (RoundResultEntry::$piecesCount)
 * 3. then the organiser's order of the rounds, the name, the id - never random
 * Entries without a ranked result (did not start, no result yet) come last, by name.
 */
final readonly class AdvancementSeeding
{
    /**
     * @param array<string, list<RoundResultEntry>> $entriesByRound source round id => its entries, in the organiser's
     *        order of the rounds; ranks as GetRoundResultEntries computes them
     * @param array<string, null|int> $piecesCountByRound source round id => its puzzle's piece count, when one puzzle
     * @param null|callable(RoundResultEntry): bool $include which entries take part (all when null)
     * @return list<SeededEntry> seed 1..N
     */
    public static function seed(array $entriesByRound, array $piecesCountByRound = [], null|callable $include = null): array
    {
        $seeded = [];
        foreach (self::sortedCandidates($entriesByRound, $piecesCountByRound, $include) as $index => $candidate) {
            $seeded[] = new SeededEntry($index + 1, $candidate['roundId'], $candidate['entry']);
        }

        return $seeded;
    }

    /**
     * "Top N over all the source rounds together" - an advancement step's preset (competition toolkit A8): the best
     * `count` ranked entries by the seed above, plus everybody who shares the last place that fits - the same rank in
     * their own round, finished or not alike and the same result relative to their round's winner (the seed's own keys;
     * the round order and the name only break the seed's ties, never the qualification). When those take the list past
     * `count`, they are "tied at the cut": proposed, but the organiser decides (like the desk's Top N).
     *
     * @param array<string, list<RoundResultEntry>> $entriesByRound as seed()
     * @param array<string, null|int> $piecesCountByRound as seed()
     * @return array{selected: list<SeededEntry>, tiedAtCut: list<SeededEntry>}
     */
    public static function topOverall(array $entriesByRound, array $piecesCountByRound, int $count): array
    {
        $candidates = self::sortedCandidates($entriesByRound, $piecesCountByRound, static fn (RoundResultEntry $entry): bool => $entry->rank !== null);

        if ($count < 1 || $candidates === []) {
            return ['selected' => [], 'tiedAtCut' => []];
        }

        $key = static fn (array $candidate): array => [$candidate['entry']->rank, $candidate['entry']->result->isUnfinished(), $candidate['relative']];
        $cutKey = $key($candidates[min($count, count($candidates)) - 1]);
        $selected = [];
        $tied = [];

        foreach ($candidates as $index => $candidate) {
            $atCut = $key($candidate) === $cutKey;

            if ($index >= $count && !$atCut) {
                break;
            }

            $seeded = new SeededEntry($index + 1, $candidate['roundId'], $candidate['entry']);
            $selected[] = $seeded;

            if ($atCut) {
                $tied[] = $seeded;
            }
        }

        return ['selected' => $selected, 'tiedAtCut' => count($selected) > $count ? $tied : []];
    }

    /**
     * @param array<string, list<RoundResultEntry>> $entriesByRound
     * @param array<string, null|int> $piecesCountByRound
     * @param null|callable(RoundResultEntry): bool $include
     * @return list<array{roundId: string, roundPosition: int, entry: RoundResultEntry, relative: float}> best first
     */
    private static function sortedCandidates(array $entriesByRound, array $piecesCountByRound, null|callable $include): array
    {
        $candidates = [];
        $roundPosition = 0;

        foreach ($entriesByRound as $roundId => $entries) {
            $roundId = (string) $roundId;
            $winnerSeconds = null;

            foreach ($entries as $entry) {
                if ($entry->result->seconds !== null && ($winnerSeconds === null || $entry->result->seconds < $winnerSeconds)) {
                    $winnerSeconds = $entry->result->seconds;
                }
            }

            foreach ($entries as $entry) {
                if ($include !== null && $include($entry) === false) {
                    continue;
                }

                $candidates[] = [
                    'roundId' => $roundId,
                    'roundPosition' => $roundPosition,
                    'entry' => $entry,
                    // A `pick` round's entry brings its own bound (GetRoundResultEntries, competition toolkit A3)
                    'relative' => self::relative($entry, $winnerSeconds, $entry->piecesCount ?? $piecesCountByRound[$roundId] ?? null),
                ];
            }

            $roundPosition++;
        }

        usort($candidates, static function (array $a, array $b): int {
            $aEntry = $a['entry'];
            $bEntry = $b['entry'];

            return [$aEntry->rank === null, $aEntry->rank ?? 0] <=> [$bEntry->rank === null, $bEntry->rank ?? 0]
                ?: [$aEntry->result->isUnfinished(), $a['relative']] <=> [$bEntry->result->isUnfinished(), $b['relative']]
                ?: $a['roundPosition'] <=> $b['roundPosition']
                ?: strcasecmp($aEntry->displayName(), $bEntry->displayName())
                ?: strcmp($aEntry->ref->id, $bEntry->ref->id);
        });

        return $candidates;
    }

    /**
     * Smaller is better: time ÷ winner's time; for unfinished the negative share of the pieces placed.
     */
    private static function relative(RoundResultEntry $entry, null|int $winnerSeconds, null|int $piecesCount): float
    {
        $result = $entry->result;

        if ($result->seconds !== null) {
            return $winnerSeconds !== null && $winnerSeconds > 0 ? $result->seconds / $winnerSeconds : (float) $result->seconds;
        }

        if ($result->piecesPlaced !== null) {
            return -($piecesCount !== null && $piecesCount > 0 ? $result->piecesPlaced / $piecesCount : (float) $result->piecesPlaced);
        }

        return 0.0;
    }
}
