<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The chain of difficulty priors, highest priority first: the first one that knows the puzzle answers.
 */
readonly final class DifficultyPriors
{
    /**
     * @param iterable<DifficultyPrior> $priors
     */
    public function __construct(
        #[AutowireIterator(DifficultyPrior::class)]
        private iterable $priors,
    ) {
    }

    public static function brandPiecesOnly(): self
    {
        return new self([new BrandPiecesPrior()]);
    }

    public function priorFor(PriorContext $context): null|PriorEstimate
    {
        foreach ($this->priors as $prior) {
            $estimate = $prior->priorFor($context);

            if ($estimate !== null) {
                return $estimate;
            }
        }

        return null;
    }
}
