<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * Requests of one API caller, of one request type, ending in one status class, on
 * one UTC day (docs/features/api/usage-statistics.md). Written only by the 5-minute
 * copy from the Redis counters (ApiUsageRepository::storeSnapshot(), a native upsert
 * that never lowers a number) and pruned after 24 months - never through this class.
 */
#[Entity]
#[Table(name: 'api_usage_day')]
#[UniqueConstraint(columns: ['day', 'caller_key', 'operation', 'status_class'])]
#[Index(columns: ['day'])]
#[Index(columns: ['player_id', 'day'])]
#[Index(columns: ['personal_access_token_id'])]
#[Index(columns: ['oauth2_client_identifier', 'day'])]
class ApiUsageDay
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(type: Types::DATE_IMMUTABLE)]
        public DateTimeImmutable $day,
        // ApiCaller::key() - the identity; the three columns below are the same caller, split for joins
        #[Immutable]
        #[Column(length: 255)]
        public string $callerKey,
        // GDPR: deleting the player deletes their usage; client_credentials rows have no player
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|Player $player,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|PersonalAccessToken $personalAccessToken,
        #[Immutable]
        #[Column(length: 255, nullable: true)]
        public null|string $oauth2ClientIdentifier,
        // HTTP method + URI template, e.g. "GET /api/v1/me/results" (ApiUsageOperation)
        #[Immutable]
        #[Column(length: 255)]
        public string $operation,
        // ApiStatusClass value: 2xx, 3xx, 4xx, 429, 5xx
        #[Immutable]
        #[Column(length: 3)]
        public string $statusClass,
        #[Immutable]
        #[Column]
        public int $requests,
        #[Immutable]
        #[Column(type: Types::BIGINT)]
        public int $durationMsTotal,
    ) {
    }
}
