<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateModelInput;

/**
 * Records one run of the image pipeline's difficulty model with its evaluation - the new current model record, the
 * earlier ones kept as history (docs/features/internal-api.md, "Puzzle difficulty labels and estimates").
 */
readonly final class RecordPuzzleDifficultyEstimateModel
{
    public function __construct(
        public UuidInterface $recordId,
        public PuzzleDifficultyEstimateModelInput $model,
    ) {
    }
}
