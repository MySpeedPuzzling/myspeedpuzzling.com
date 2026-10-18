<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimeline;

/**
 * What a pair/team "should" take from its members' solo paces - the one rule the daily coordination fit
 * (GroupCoordinationFitter) and the synergy (PairsTeamsSynergy) share: the members' paces as of the solve
 * (PaceTimeline::paceAt(), the rule of the as-of difficulty) combined like rates, times the puzzle's difficulty, times
 * the coordination constant c(size, first try / repeat). docs/features/insights/computation.md §5, §4c.1.
 */
final class GroupExpectation
{
    /**
     * Σ 1 / paceᵢ of every member at the solve; null when a member is a guest or has no pace then.
     *
     * @param list<null|string> $memberPlayerIds null = a guest
     */
    public static function combinedRate(PaceTimeline $timeline, array $memberPlayerIds, int $piecesCount, float $solvedEpoch, float $trackedEpoch): null|float
    {
        if ($memberPlayerIds === []) {
            return null;
        }

        $rate = 0.0;

        foreach ($memberPlayerIds as $playerId) {
            if ($playerId === null) {
                return null;
            }

            $pace = $timeline->paceAt($playerId, $piecesCount, $solvedEpoch, $trackedEpoch);

            if ($pace === null || $pace <= 0) {
                return null;
            }

            $rate += 1.0 / $pace;
        }

        return $rate;
    }

    /**
     * Seconds the group is expected to take: c × difficulty ÷ Σ 1/paceᵢ.
     */
    public static function expectedSeconds(float $coordination, float $difficulty, float $combinedRate): float
    {
        return $coordination * $difficulty / $combinedRate;
    }
}
