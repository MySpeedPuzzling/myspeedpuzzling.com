<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class FeatureRequestVoter
{
    public function __construct(
        public string $playerId,
        public string $email,
        public null|string $locale,
    ) {
    }
}
