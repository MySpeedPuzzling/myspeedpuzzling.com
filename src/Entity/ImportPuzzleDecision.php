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
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;

/**
 * A player's remembered answer for one puzzle key (docs/features/data-import/pairing.md "Remembered decisions"): the
 * same puzzle in their next file, or in a newer version of this one, arrives decided. Written whenever the player
 * decides (DecideImportPuzzlesHandler), removed when they take the decision back. One per player and key.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'puzzle_key'])]
class ImportPuzzleDecision
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, enumType: ImportPuzzleChoice::class)]
    public ImportPuzzleChoice $choice;

    /**
     * A remembered pairing is about that catalogue puzzle: a merge moves it to the survivor (puzzleMergedInto()),
     * and it goes with a puzzle that goes any other way.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public null|Puzzle $puzzle = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $decidedAt;

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
        #[Column(length: 80)]
        public string $puzzleKey,
        ImportPuzzleChoice $choice,
        null|Puzzle $puzzle,
        DateTimeImmutable $decidedAt,
    ) {
        $this->decide($choice, $puzzle, $decidedAt);
    }

    public function decide(ImportPuzzleChoice $choice, null|Puzzle $puzzle, DateTimeImmutable $now): void
    {
        if ($choice === ImportPuzzleChoice::Undecided || ($choice === ImportPuzzleChoice::Catalogue) !== ($puzzle !== null)) {
            throw new \LogicException('A remembered decision is a catalogue puzzle, "unlisted" or "left out".');
        }

        $this->choice = $choice;
        $this->puzzle = $puzzle;
        $this->decidedAt = $now;
    }

    /**
     * The puzzle was merged into the survivor of a puzzle merge (ApprovePuzzleMergeRequestHandler).
     */
    public function puzzleMergedInto(Puzzle $survivor): void
    {
        $this->puzzle = $survivor;
    }
}
