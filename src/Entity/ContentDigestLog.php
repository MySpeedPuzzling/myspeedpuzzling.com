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
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * One row per attempted/sent content digest — the idempotency anchor (one digest per
 * player per period) and the state behind the never-two-no-activity-digests rule.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'digest_type', 'period_key'])]
#[Index(columns: ['player_id'])]
class ContentDigestLog
{
    public const string STATUS_SENT = 'sent';
    public const string STATUS_FAILED_PERMANENT = 'failed_permanent';

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[Immutable]
        public Player $player,
        #[Immutable]
        #[Column]
        public string $digestType,
        #[Immutable]
        #[Column]
        public string $periodKey,
        #[Immutable]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $sentAt,
        /** False = the "we haven't seen a solve" variant went out — drives the never-twice rule. */
        #[Immutable]
        #[Column(type: Types::BOOLEAN)]
        public bool $hadActivity,
        #[Immutable]
        #[Column]
        public string $status,
    ) {
    }
}
