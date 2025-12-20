<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class SubmitPuzzleMergeRequest
{
    /**
     * @param array<string> $duplicatePuzzleIds
     */
    public function __construct(
        public string $mergeRequestId,
        public string $sourcePuzzleId,
        public string $reporterId,
        public array $duplicatePuzzleIds,
    ) {
    }
}
