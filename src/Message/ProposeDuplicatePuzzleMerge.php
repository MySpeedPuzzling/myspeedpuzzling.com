<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ProposeDuplicatePuzzleMerge
{
    public function __construct(
        public string $signalId,
        // The admin - reporter of the merge request
        public string $playerId,
        public string $mergeRequestId,
    ) {
    }
}
