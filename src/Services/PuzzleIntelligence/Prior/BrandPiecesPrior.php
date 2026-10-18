<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The mean log difficulty index of the puzzle's brand at its piece count, shrunk toward the average puzzle (0.0) with
 * a pseudo-count of 20 indices and without the puzzle's own indices (leave-one-out) - fitted once a day by the batch
 * (PredictionModelRecalculator): the groups' sums in difficulty_prior_group, each puzzle's share in them in
 * difficulty_prior_puzzle. Unknown brands and groups with fewer than
 * MINIMUM_GROUP_INDICES other indices have no prior. StatisticalInputsSql::priorSql() is the same formula in SQL.
 */
#[AsTaggedItem(priority: 0)]
readonly final class BrandPiecesPrior implements DifficultyPrior
{
    public const string NAME = 'brand_pieces';

    public const int PSEUDO_COUNT = 20;

    public const int MINIMUM_GROUP_INDICES = 5;

    public function priorFor(PriorContext $context): null|PriorEstimate
    {
        $log = self::fromSums($context->brandPiecesGroupSumLog, $context->brandPiecesGroupCount, $context->brandPiecesOwnSumLog, $context->brandPiecesOwnCount);

        return $log !== null ? new PriorEstimate($log, self::NAME) : null;
    }

    /**
     * The prior of a group from its sums, the puzzle's own share left out; null below the minimum.
     */
    public static function fromSums(float $groupSumLog, int $groupCount, float $ownSumLog = 0.0, int $ownCount = 0): null|float
    {
        $count = $groupCount - $ownCount;

        if ($count < self::MINIMUM_GROUP_INDICES) {
            return null;
        }

        // The same IEEE operations as the SQL twin - equal to the last bit
        return ($groupSumLog - $ownSumLog) / (float) ($count + self::PSEUDO_COUNT);
    }
}
