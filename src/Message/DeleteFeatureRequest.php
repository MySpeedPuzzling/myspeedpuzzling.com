<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class DeleteFeatureRequest
{
    public function __construct(
        public string $featureRequestId,
        public string $playerId,
    ) {
    }
}
