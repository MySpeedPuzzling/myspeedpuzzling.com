<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

final readonly class ImportAuth0User
{
    public function __construct(
        public string $userId,
        public string $email,
        public bool $emailVerified,
        public null|string $name,
        public null|DateTimeImmutable $registeredAt,
        public null|string $passwordHash,
    ) {
    }
}
