<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\OnlineEventType;

/**
 * One thing a participant's phone reported (docs/features/online-competitions/README.md §5.6) - append-only. The id is
 * the phone's own UUIDv7, never generated here: an event sent again is acknowledged again and never stored twice.
 * `clientMs` is the phone's server-clock time of the moment (its synced clock), with the raw `performance.now()`, the
 * offset and the round trip it used; `receivedAtMs` is when the server got it (an event recorded offline arrives late).
 */
#[Entity]
#[Index(columns: ['online_entry_id', 'client_ms'])]
class OnlineEntryEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        // The phone's UUIDv7
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public OnlineEntry $onlineEntry,
        #[Immutable]
        #[Column(length: 64)]
        public string $deviceId,
        #[Immutable]
        #[Column(length: 32, enumType: OnlineEventType::class)]
        public OnlineEventType $type,
        #[Immutable]
        #[Column(type: Types::BIGINT)]
        public int $clientMs,
        #[Immutable]
        #[Column(type: Types::FLOAT, nullable: true)]
        public null|float $perfMs,
        #[Immutable]
        #[Column(type: Types::FLOAT, nullable: true)]
        public null|float $offsetMs,
        #[Immutable]
        #[Column(type: Types::FLOAT, nullable: true)]
        public null|float $rttMs,
        #[Immutable]
        #[Column(type: Types::BIGINT)]
        public int $receivedAtMs,
        #[Immutable]
        #[Column(type: Types::JSONB, options: ['default' => '{}'])]
        public array $payload = [],
    ) {
    }
}
