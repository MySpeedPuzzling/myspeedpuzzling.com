<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class UserProfile
{
    public function __construct(
        public string $userId,
        public null|string $email,
    ) {
    }

    /**
     * @param array{
     *     user_id: string,
     *     email: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            userId: $row['user_id'],
            email: $row['email'],
        );
    }
}
