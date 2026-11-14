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
use SpeedPuzzling\Web\Value\CustomValues;

/**
 * One player's own values on one result (docs/features/custom-fields.md, D16 of the data import design): a pair's or
 * team's result is one row of several players, so the values live beside it - one row per result and player, private
 * to that player. Never joined into a shared read: GetCustomValues is the only reader. Goes with the result and with
 * the player (both foreign keys cascade).
 */
#[Entity]
#[UniqueConstraint(columns: ['solving_time_id', 'player_id'])]
#[Index(columns: ['player_id'])]
class SolvingTimeCustomValues
{
    /** @var array<string, string|int|float|bool|list<string>> keyed by custom field id */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $customValues;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public PuzzleSolvingTime $solvingTime,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        CustomValues $values,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $updatedAt,
    ) {
        $this->customValues = $values->values;
    }

    public function values(): CustomValues
    {
        return CustomValues::fromStored($this->customValues);
    }

    public function change(CustomValues $values, DateTimeImmutable $now): void
    {
        $this->customValues = $values->values;
        $this->updatedAt = $now;
    }
}
