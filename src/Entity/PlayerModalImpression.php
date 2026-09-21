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
use SpeedPuzzling\Web\Value\AnnouncementModal;

/**
 * One row = this player was shown this announcement modal (docs/features/announcement-modals.md).
 * The unique constraint is the whole "never twice" guarantee: the row is claimed with
 * INSERT .. ON CONFLICT DO NOTHING before the modal is rendered (ClaimAnnouncementModalImpressionHandler),
 * so rows are written by SQL and only mapped here for the schema.
 *
 * `displayedAt` = the modal went into a page; `seenAt` = the browser reported it really opened.
 * Only the former decides anything - the latter is there to be counted.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'modal'])]
#[Index(columns: ['modal', 'displayed_at'])]
class PlayerModalImpression
{
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $seenAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[Column(length: 64, enumType: AnnouncementModal::class)]
        public AnnouncementModal $modal,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $displayedAt,
    ) {
    }
}
