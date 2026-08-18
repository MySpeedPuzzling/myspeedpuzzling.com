<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

final readonly class RequestAccountDeletion
{
    public function __construct(
        public string $userId,
    ) {
    }
}
