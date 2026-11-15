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
use SpeedPuzzling\Web\Value\Unlisted\UnlistedOwnershipKind;

/**
 * "I own it" / "I wish for it" about one of a player's unlisted puzzles
 * (docs/features/data-import/unlisted-puzzles.md) - **never a row of `collection_item` or `wish_list_item`**:
 * collection pages are often public, and an unlisted puzzle is nobody's business but its owner's. One mark of a kind
 * per unlisted puzzle.
 *
 * When the puzzle is paired with a catalogue puzzle the mark becomes an item of the player's collection or wish list
 * (`convertedItemId`: one the pairing created - `convertedCreated` - or one that was there).
 */
#[Entity]
#[UniqueConstraint(columns: ['unlisted_puzzle_id', 'kind'])]
#[Index(columns: ['import_row_id'])]
#[Index(columns: ['pairing_id'])]
class UnlistedOwnership
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $pairingId = null;

    /** The collection item / wish list item it became - a plain id, the item may go */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $convertedItemId = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $convertedAt = null;

    /** True: the pairing created the item. False: it was there - an Undo never removes that one */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => false])]
    public bool $convertedCreated = false;

    /**
     * The wish list item the new collection item removed by the player's own setting - what an Undo puts back.
     *
     * @var null|array<string, mixed>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $made = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 40, nullable: true)]
    public null|string $keptReason = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public UnlistedPuzzle $unlistedPuzzle,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[Column(length: 16, enumType: UnlistedOwnershipKind::class)]
        public UnlistedOwnershipKind $kind,
        /** The owned puzzle's comment, as the row carried it */
        #[Immutable]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $comment,
        /** The collection the sheet named - kept as text */
        #[Immutable]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $collectionLabel,
        /** The row of the file it came from - a plain id */
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $importRowId,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function isUnlisted(): bool
    {
        return $this->convertedAt === null;
    }

    public function takenByPairing(UuidInterface $pairingId): void
    {
        $this->pairingId = $pairingId;
        $this->keptReason = null;
    }

    /**
     * @param null|array<string, mixed> $made
     */
    public function converted(null|UuidInterface $itemId, bool $created, null|array $made, DateTimeImmutable $now): void
    {
        $this->convertedItemId = $itemId;
        $this->convertedCreated = $created;
        $this->convertedAt = $now;
        $this->made = $made;
        $this->keptReason = null;
    }

    public function kept(string $reason): void
    {
        $this->keptReason = $reason;
    }

    public function unlistedAgain(): void
    {
        $this->convertedItemId = null;
        $this->convertedCreated = false;
        $this->convertedAt = null;
        $this->made = null;
        $this->keptReason = null;
    }
}
