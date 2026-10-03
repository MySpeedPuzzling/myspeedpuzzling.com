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
use JetBrains\PhpStorm\Immutable;

/**
 * "The seller is bringing this listing to that event" (docs/features/marketplace/11-events.md). The row is the
 * whole state: no row = not bringing it (buyers may still ask, that is derived). Rows are kept when the event
 * ends or the seller stops going - the read side hides them by the attendance and date checks - and only
 * unticking or the cascades of the listing / the event remove them.
 */
#[Entity]
#[Index(columns: ['competition_id'])]
class SellSwapListItemEvent
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public SellSwapListItem $sellSwapListItem,
        #[Id]
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Competition $competition,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $addedAt,
    ) {
    }
}
