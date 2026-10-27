<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\FinishedPhotoAnalysisInput;

/**
 * Stores up to MAX_ITEMS analyses of players' finished-puzzle photos in one transaction (docs/features/internal-api.md,
 * "Finished-puzzle photos"); a refused item is only that item's outcome. The handler answers
 * array<int, PuzzleImageAnalysisOutcome> keyed like `analyses`. Serialized: two batches never both create the row of
 * the same photo (the unique index would fail the whole later batch).
 */
readonly final class RecordFinishedPhotoAnalyses implements SerializedByLock
{
    public const int MAX_ITEMS = 200;

    public const string LOCK_KEY = 'finished-photo-analysis';

    /**
     * @param array<int, FinishedPhotoAnalysisInput> $analyses keyed by the item's position in the request
     */
    public function __construct(
        public array $analyses,
    ) {
    }

    public function lockKey(): string
    {
        return self::LOCK_KEY;
    }
}
