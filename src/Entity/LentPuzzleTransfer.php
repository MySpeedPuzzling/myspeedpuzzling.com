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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\TransferType;

#[Entity]
class LentPuzzleTransfer
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public LentPuzzle $lentPuzzle,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Player $fromPlayer,
        #[Immutable]
        #[Column(type: Types::STRING, nullable: true, length: 200)]
        public null|string $fromPlayerName,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Player $toPlayer,
        #[Immutable]
        #[Column(type: Types::STRING, nullable: true, length: 200)]
        public null|string $toPlayerName,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $transferredAt,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: TransferType::class)]
        public TransferType $transferType,
    ) {
    }
}
