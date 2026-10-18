<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateInput;

/**
 * Stores up to MAX_ITEMS picture-based difficulty estimates in one transaction (docs/features/internal-api.md, "Puzzle
 * difficulty labels and estimates") - each the puzzle's current estimate, created or replacing the stored one; a
 * refused item (hidden, unknown, another picture) is only that item's outcome. The handler answers
 * array<int, PuzzleDifficultyEstimateOutcome>, keyed like `estimates`; items found invalid by the controller never
 * reach it.
 *
 * Under one lock: "is there a row yet?" and the insert never interleave with another push for the same puzzle - the
 * primary key would fail. One pipeline pushes them, so the writes never wait for long.
 */
readonly final class RecordPuzzleDifficultyEstimates implements SerializedByLock
{
    public const int MAX_ITEMS = 500;

    public const string LOCK_KEY = 'puzzle-difficulty-estimate';

    /**
     * @param array<int, PuzzleDifficultyEstimateInput> $estimates keyed by the item's position in the request
     */
    public function __construct(
        public array $estimates,
    ) {
    }

    public function lockKey(): string
    {
        return self::LOCK_KEY;
    }
}
