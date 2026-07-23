<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ContentDigestFrequency;

readonly final class ChangeContentDigestFrequency
{
    public function __construct(
        public string $playerId,
        public ContentDigestFrequency $frequency,
    ) {
    }
}
