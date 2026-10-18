<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\ImageAnalyzer;

/**
 * A worker of the image analysis pipeline takes up to `limit` puzzles whose image is still to be analysed by the
 * analyzer (missing, another picture, or another `version`) and that no other worker holds - for `leaseSeconds`
 * (docs/features/internal-api.md, "Puzzle image analysis" - claims). Its own claims are taken again, the lease
 * extended when there is not enough fresh work (fresh work comes first). The handler answers the claimed puzzle ids
 * (list<string>, in id order).
 *
 * Under the lock every image analysis write takes - two claims, or a claim and a store, never interleave; the
 * statement's ON CONFLICT guard is the backstop (ClaimPuzzleImagesHandler).
 */
readonly final class ClaimPuzzleImages implements SerializedByLock
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    public const int DEFAULT_LEASE_SECONDS = 1800;

    public const int MIN_LEASE_SECONDS = 60;

    public const int MAX_LEASE_SECONDS = 7200;

    public function __construct(
        public ImageAnalyzer $analyzer,
        public string $version,
        public string $worker,
        public int $limit = self::DEFAULT_LIMIT,
        public int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
    ) {
    }

    public function lockKey(): string
    {
        return RecordPuzzleImageAnalysis::LOCK_KEY;
    }
}
