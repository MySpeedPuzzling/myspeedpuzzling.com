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
 *    won in 1:05); unfinished by pieces placed ÷ the round's piece count (or pieces placed without one), most first
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
                    'relative' => self::relative($entry, $winnerSeconds, $piecesCountByRound[$roundId] ?? null),
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

        $seeded = [];
        foreach ($candidates as $index => $candidate) {
            $seeded[] = new SeededEntry($index + 1, $candidate['roundId'], $candidate['entry']);
        }

        return $seeded;
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
