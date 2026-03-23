<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class EditFeatureRequest
{
    public function __construct(
        public string $featureRequestId,
        public string $playerId,
        public string $title,
        public string $description,
    ) {
    }
}
