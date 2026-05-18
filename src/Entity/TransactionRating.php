<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\TransactionRole;

#[Entity]
#[UniqueConstraint(columns: ['sold_swapped_item_id', 'reviewer_id'])]
class TransactionRating
{
    #[Column(type: Types::STRING, length: 200, nullable: true)]
    public null|string $reviewerName = null;

    #[Column(type: Types::STRING, length: 200, nullable: true)]
    public null|string $reviewedPlayerName = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public SoldSwappedItem $soldSwappedItem,
        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Player $reviewer,
        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Player $reviewedPlayer,
        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $stars,
        #[Immutable]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $reviewText,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $ratedAt,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: TransactionRole::class)]
        public TransactionRole $reviewerRole,
    ) {
    }

    public function anonymizeReviewer(string $name): void
    {
        $this->reviewerName = $name;
        $this->reviewer = null;
    }

    public function anonymizeReviewedPlayer(string $name): void
    {
        $this->reviewedPlayerName = $name;
        $this->reviewedPlayer = null;
    }
}
