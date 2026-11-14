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
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\CustomFieldDefinition;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\StandardCustomField;

/**
 * One of a player's own fields (docs/features/custom-fields.md): private, at most MAX_ACTIVE_PER_PLAYER in use, the
 * name once per player. A ready-made field carries its fixed key (StandardCustomField) - its name, type, choices and
 * places are the registry's and never change. Archived = hidden everywhere, its values kept.
 *
 * The table is `player_custom_field`, not `custom_field`: Doctrine leaves every table and index whose name starts
 * with `custom_` alone (the schema filter of config/services.php - the prefix of the hand-made indexes).
 */
#[Entity]
#[Table(name: 'player_custom_field')]
#[UniqueConstraint(columns: ['player_id', 'name'])]
#[UniqueConstraint(columns: ['player_id', 'standard_key'])]
class CustomField
{
    public const int MAX_ACTIVE_PER_PLAYER = 20;
    /** With the archived ones - the table can not grow without an end */
    public const int MAX_PER_PLAYER = 60;

    /** @var list<string> */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $choices;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $archivedAt = null;

    /**
     * @param list<string> $choices
     */
    private function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: CustomFieldDefinition::NAME_MAX_LENGTH)]
        public string $name,
        #[Immutable]
        #[Column(length: 40, nullable: true, enumType: StandardCustomField::class)]
        public null|StandardCustomField $standardKey,
        #[Immutable]
        #[Column(length: 16, enumType: CustomFieldType::class)]
        public CustomFieldType $type,
        array $choices,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public bool $forResults,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public bool $forCollectionItems,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public int $position,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
        $this->choices = $choices;
    }

    public static function ownOf(UuidInterface $id, Player $player, CustomFieldDefinition $definition, int $position, DateTimeImmutable $now): self
    {
        return new self($id, $player, $definition->name, null, $definition->type, $definition->choices, $definition->forResults, $definition->forCollectionItems, $position, $now);
    }

    public static function readyMade(UuidInterface $id, Player $player, StandardCustomField $standard, int $position, DateTimeImmutable $now): self
    {
        return new self($id, $player, $standard->storedName(), $standard, $standard->type(), $standard->choices(), $standard->forResults(), $standard->forCollectionItems(), $position, $now);
    }

    public function isReadyMade(): bool
    {
        return $this->standardKey !== null;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function isUsableOnResults(): bool
    {
        return $this->forResults && $this->archivedAt === null;
    }

    /**
     * A player's own field only - a ready-made one is the registry's. The type stays: the stored values are of it.
     */
    public function redefine(CustomFieldDefinition $definition): void
    {
        $this->name = $definition->name;
        $this->choices = $definition->choices;
        $this->forResults = $definition->forResults;
        $this->forCollectionItems = $definition->forCollectionItems;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function archive(DateTimeImmutable $now): void
    {
        $this->archivedAt ??= $now;
    }

    public function restore(): void
    {
        $this->archivedAt = null;
    }
}
