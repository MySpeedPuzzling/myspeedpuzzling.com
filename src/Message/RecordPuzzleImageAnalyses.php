<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisInput;

/**
 * Stores up to MAX_ITEMS image analyses in one transaction (docs/features/internal-api.md, "Puzzle image analysis") -
 * each like RecordPuzzleImageAnalysis, but a refused one is only that item's outcome, never the others' failure. The
 * handler answers list<PuzzleImageAnalysisOutcome>, keyed like `analyses`; items already found invalid by the
 * controller never reach it. Under RecordPuzzleImageAnalysis's lock.
 */
readonly final class RecordPuzzleImageAnalyses implements SerializedByLock
{
    public const int MAX_ITEMS = 200;

    /**
     * @param array<int, PuzzleImageAnalysisInput> $analyses keyed by the item's position in the request
     */
    public function __construct(
        public array $analyses,
    ) {
    }

    public function lockKey(): string
    {
        return RecordPuzzleImageAnalysis::LOCK_KEY;
    }
}
