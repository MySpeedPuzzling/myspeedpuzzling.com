<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\ContentDigestKind;

/**
 * One planned sending of the digest: one kind (activity / no-activity), one frequency, one period, its recipients
 * queued spaced over the day. The counters are the circuit breaker's input (docs/features/content-digest/README.md
 * §"Pacing and the circuit breaker"): they are bumped by ContentDigestRunRepository with atomic UPDATEs, and once
 * `stopped_at` is set every message of the run still in the queue is dropped without sending.
 */
#[Entity]
#[Index(columns: ['period_key'])]
class ContentDigestRun
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: ContentDigestKind::class)]
        public ContentDigestKind $kind,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: ContentDigestFrequency::class)]
        public ContentDigestFrequency $frequency,
        #[Immutable]
        #[Column]
        public string $periodKey,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $recipients,
        #[Immutable]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $startedAt,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $spacingSeconds,
        #[Column(type: Types::INTEGER, options: ['default' => 0])]
        public int $sent = 0,
        #[Column(type: Types::INTEGER, options: ['default' => 0])]
        public int $failedPermanently = 0,
        #[Column(type: Types::INTEGER, options: ['default' => 0])]
        public int $deferred = 0,
        #[Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $stoppedAt = null,
    ) {
    }
}
