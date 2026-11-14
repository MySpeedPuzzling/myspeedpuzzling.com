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
use LogicException;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Events\PuzzleAddedToCollection;
use SpeedPuzzling\Web\Value\CustomValues;

#[Entity]
#[UniqueConstraint(columns: ['collection_id', 'player_id', 'puzzle_id'])]
class CollectionItem implements EntityWithEvents
{
    use HasEvents;

    /**
     * The owner's own values on this item, keyed by custom field id (docs/features/custom-fields.md "Collection
     * items") - null = none. Private on purpose: collection pages are often public and their lists are shared, so
     * nothing but the three methods below reaches the column, and CustomValuesCoverageTest lists who may call them.
     *
     * @var null|array<string, string|int|float|bool|list<string>>
     */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private null|array $customValues = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|Collection $collection,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $player,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Puzzle $puzzle,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT, length: 500, nullable: true)]
        public null|string $comment,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $addedAt,
    ) {
        $this->recordThat(new PuzzleAddedToCollection(
            $this->id,
            $this->player->id->toString(),
            $this->puzzle->id->toString(),
        ));
    }

    public function changeComment(null|string $comment): void
    {
        $this->comment = $comment;
    }

    /**
     * The owner's own values - for the owner's edit form, their export and the writers, never for a page anybody else
     * can open.
     */
    public function customValues(): CustomValues
    {
        return CustomValues::fromStored($this->customValues);
    }

    public function changeCustomValues(CustomValues $values): void
    {
        $this->customValues = $values->isEmpty() ? null : $values->values;
    }

    /**
     * Two items of one player become one (the puzzle was already in the target collection, two puzzles were merged):
     * what this item holds stays, only the fields it does not hold are filled from the item that goes away.
     */
    public function fillCustomValueGapsFrom(self $leaving): void
    {
        if ($leaving->player->id->equals($this->player->id) === false) {
            throw new LogicException('Custom values never move between two players.');
        }

        $own = $this->customValues();
        $filled = $leaving->customValues()->with($own->values);

        if ($filled->values !== $own->values) {
            $this->changeCustomValues($filled);
        }
    }

    /**
     * Moving is not re-adding: the item keeps its id, added date, comment and the owner's own values (null = the
     * system collection). The caller makes sure the target does not hold the puzzle yet (unique per collection, player and puzzle).
     */
    public function moveTo(null|Collection $collection): void
    {
        $this->collection = $collection;
    }
}
