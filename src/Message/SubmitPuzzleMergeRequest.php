<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class SubmitPuzzleMergeRequest
{
    /**
     * @param array<string> $duplicatePuzzleIds
     * @param array<string, string> $reportedNameLanguages Optional: puzzle id => the language its name is in, as the
     *                                                     reporter says ("this record is the Czech box")
     */
    public function __construct(
        public string $mergeRequestId,
        public string $sourcePuzzleId,
        public string $reporterId,
        public array $duplicatePuzzleIds,
        public array $reportedNameLanguages = [],
    ) {
    }
}
