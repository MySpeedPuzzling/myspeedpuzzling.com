<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\FeatureRequestStatus;

readonly final class UpdateFeatureRequestStatus
{
    public function __construct(
        public string $featureRequestId,
        public FeatureRequestStatus $status,
        public null|string $githubUrl = null,
        public null|string $adminComment = null,
    ) {
    }
}
