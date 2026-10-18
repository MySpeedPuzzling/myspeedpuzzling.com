<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\ImageAnalyzer;

/**
 * A worker lets go of its claims (shutting down, giving up on images) - all of them, or only those of an analyzer and/or
 * of the listed puzzles. Another worker's claims are never touched. The handler answers how many were released (int).
 */
readonly final class ReleasePuzzleImageClaims implements SerializedByLock
{
    /**
     * @param null|list<string> $puzzleIds
     */
    public function __construct(
        public string $worker,
        public null|ImageAnalyzer $analyzer = null,
        public null|array $puzzleIds = null,
    ) {
    }

    public function lockKey(): string
    {
        return RecordPuzzleImageAnalysis::LOCK_KEY;
    }
}
