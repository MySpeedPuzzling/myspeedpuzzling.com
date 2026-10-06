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
 * One API caller on one UTC day: its busiest minute and its latest request
 * (docs/features/api/usage-statistics.md). The peak per minute is the data a future
 * rate limiter's limits get chosen from. Written only by the 5-minute copy from the
 * Redis counters, pruned after 24 months.
 */
#[Entity]
#[Table(name: 'api_caller_day')]
#[UniqueConstraint(columns: ['day', 'caller_key'])]
#[Index(columns: ['day'])]
#[Index(columns: ['player_id', 'day'])]
#[Index(columns: ['personal_access_token_id'])]
#[Index(columns: ['oauth2_client_identifier', 'day'])]
class ApiCallerDay
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
        #[Immutable]
        #[Column]
        public int $peakRequestsPerMinute,
        #[Immutable]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $lastRequestAt,
    ) {
    }
}
