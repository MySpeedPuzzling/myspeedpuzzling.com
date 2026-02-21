<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * @phpstan-type PlayerOAuth2ConsentRow = array{
 *     id: string,
 *     client_identifier: string,
 *     client_name: string,
 *     scopes: string,
 *     consented_at: string,
 *     last_used_at: null|string,
 * }
 */
readonly final class PlayerOAuth2Consent
{
    /**
     * @param array<string> $scopes
     */
    public function __construct(
        public string $id,
        public string $clientIdentifier,
        public string $clientName,
        public array $scopes,
        public DateTimeImmutable $consentedAt,
        public null|DateTimeImmutable $lastUsedAt,
    ) {
    }

    /**
     * @param PlayerOAuth2ConsentRow $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        /** @var array<string> $scopes */
        $scopes = json_decode($row['scopes'], true, 512, JSON_THROW_ON_ERROR);

        return new self(
            id: $row['id'],
            clientIdentifier: $row['client_identifier'],
            clientName: $row['client_name'],
            scopes: $scopes,
            consentedAt: new DateTimeImmutable($row['consented_at']),
            lastUsedAt: $row['last_used_at'] !== null ? new DateTimeImmutable($row['last_used_at']) : null,
        );
    }
}
