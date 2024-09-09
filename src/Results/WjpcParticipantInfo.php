<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class WjpcParticipantInfo
{
    public function __construct(
        public string $participantId,
        public string $wjpcName,
        public null|int $rank2023,
        /** @var array<string> */
        public array $rounds,
    ) {
    }
}
