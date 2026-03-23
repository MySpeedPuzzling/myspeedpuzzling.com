<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class VoteForFeatureRequest
{
    public function __construct(
        public string $voterId,
        public string $featureRequestId,
    ) {
    }
}
