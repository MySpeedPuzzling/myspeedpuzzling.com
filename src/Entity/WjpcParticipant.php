<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class WjpcParticipant
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $connectedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    public null|Player $player = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Column]
        readonly public string $name,

        #[Column]
        readonly public string $country,

        /** @var array<string> */
        #[Column(type: Types::JSON, options: ['default' => '[]'])]
        readonly public array $rounds = [],

        #[Column(nullable: true)]
        readonly public null|int $year2023Rank = null,

        #[Column(nullable: true)]
        readonly public null|int $year2022Rank = null,
    ) {
    }

    public function connect(Player $player, DateTimeImmutable $connectedAt): void
    {
        $this->player = $player;
        $this->connectedAt = $connectedAt;
    }

    public function disconnect(): void
    {
        $this->player = null;
        $this->connectedAt = null;
    }
}
