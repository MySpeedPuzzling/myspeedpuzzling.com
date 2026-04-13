<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\FeatureRequestStatus;

readonly final class FeatureRequestStatusChanged
{
    public function __construct(
        public UuidInterface $featureRequestId,
        public FeatureRequestStatus $oldStatus,
        public FeatureRequestStatus $newStatus,
    ) {
    }
}
