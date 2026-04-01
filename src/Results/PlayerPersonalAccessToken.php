<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * @phpstan-type PlayerPersonalAccessTokenRow array{
 *     id: string,
 *     name: string,
 *     token_prefix: string,
 *     created_at: string,
 *     last_used_at: null|string,
 * }
 */
final readonly class PlayerPersonalAccessToken
{
    public function __construct(
        public string $id,
        public string $name,
        public string $tokenPrefix,
        public DateTimeImmutable $createdAt,
        public null|DateTimeImmutable $lastUsedAt,
    ) {
    }

    /**
     * @param PlayerPersonalAccessTokenRow $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: $row['id'],
            name: $row['name'],
            tokenPrefix: $row['token_prefix'],
            createdAt: new DateTimeImmutable($row['created_at']),
            lastUsedAt: $row['last_used_at'] !== null ? new DateTimeImmutable($row['last_used_at']) : null,
        );
    }
}
