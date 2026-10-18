<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisInput;

/**
 * Stores one analysis of a puzzle's box image as the puzzle's current one for its analyzer - created, or replacing the
 * stored one (docs/features/internal-api.md, "Puzzle image analysis"). Refused when the puzzle is unknown or its image
 * hidden (PuzzleNotFound, 404) and when the analysis describes another picture than the puzzle's current one
 * (PuzzleImageChangedMeanwhile, 409).
 *
 * Under one lock for every image analysis write (RecordPuzzleImageAnalyses too): "is there a row yet?" and the insert
 * never interleave with another push for the same puzzle and analyzer - the unique (puzzle_id, analyzer) would fail.
 * One pipeline pushes them, so the writes never wait for long.
 */
readonly final class RecordPuzzleImageAnalysis implements SerializedByLock
{
    public const string LOCK_KEY = 'puzzle-image-analysis';

    public function __construct(
        public PuzzleImageAnalysisInput $analysis,
    ) {
    }

    public function lockKey(): string
    {
        return self::LOCK_KEY;
    }
}
