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
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\ImportPuzzleDecidedHow;
use SpeedPuzzling\Web\Value\ImportPuzzleTier;

/**
 * A distinct puzzle of an import - the unit of pairing with the catalogue (docs/features/data-import/pairing.md):
 * every row with the same `puzzle_key` is this one puzzle.
 *
 * What the file says (name, brand, pieces, codes, row counts), what the rules say (`tier`, `candidates`) and the
 * brand the text stands for are written set-based by the pairing (DataImportPuzzleRepository - an import holds
 * thousands of them). The **decision** is the entity's: the player's through decidedByPlayer() / decisionTakenBack()
 * (DecideImportPuzzlesHandler), and only a decision that is not the player's is ever replaced by a later run.
 */
#[Entity]
#[UniqueConstraint(columns: ['import_id', 'puzzle_key'])]
#[Index(columns: ['import_id', 'tier', 'choice'])]
class DataImportPuzzle
{
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $brandText = null;

    /** The catalogue brand the text stands for (ImportBrandResolver) - worked out again by every run */
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|Manufacturer $manufacturer = null;

    #[Column(nullable: true)]
    public null|int $piecesCount = null;

    /** @var array<mixed> barcodes of its rows, digits without leading zeros, each once */
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $eans = [];

    /** @var array<mixed> brand codes of its rows, each once */
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $brandCodes = [];

    /** The puzzle's id in a file that is our own export - as read, never followed here (the matcher follows merges) */
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $ownPuzzleId = null;

    #[Column(options: ['default' => 0])]
    public int $rowsResults = 0;

    #[Column(options: ['default' => 0])]
    public int $rowsOwned = 0;

    #[Column(options: ['default' => 0])]
    public int $rowsWishList = 0;

    /** Null: the rules have not finished with it (the similar-name search is still to come) */
    #[Column(length: 16, nullable: true, enumType: ImportPuzzleTier::class)]
    public null|ImportPuzzleTier $tier = null;

    /** @var array<mixed> at most ImportPuzzleTierRules::MAX_CANDIDATES of [{puzzleId, how, score, piecesDiffer}], the best first */
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $candidates = [];

    /** When the similar-name search ran for it - null: not yet, or not needed */
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $searchedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, enumType: ImportPuzzleChoice::class, options: ['default' => 'undecided'])]
    public ImportPuzzleChoice $choice = ImportPuzzleChoice::Undecided;

    /**
     * The catalogue puzzle it was decided to be. No ON DELETE on purpose: a merge moves it to the survivor
     * (puzzleMergedInto(), ApprovePuzzleMergeRequestHandler) - a path that forgets it fails instead of leaving a
     * "catalogue" decision without its puzzle.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: true)]
    public null|Puzzle $puzzle = null;

    /**
     * When the decision last changed - taking it back counts. What a page sends back with its decision
     * (DecideImportPuzzles): another value in the database means somebody decided meanwhile. Null: never decided.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $decidedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, nullable: true, enumType: ImportPuzzleDecidedHow::class)]
    public null|ImportPuzzleDecidedHow $decidedHow = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(name: 'import_id', nullable: false, onDelete: 'CASCADE')]
        public DataImport $import,
        /** `<version>:<sha256>` of name + pieces + brand as the rows say them (ImportPuzzleKey) */
        #[Immutable]
        #[Column(length: 80)]
        public string $puzzleKey,
        /** As the first of its rows writes it */
        #[Column(type: Types::TEXT)]
        public string $name,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * The player's word: a catalogue puzzle, "keep as unlisted" or "leave out".
     */
    public function decidedByPlayer(ImportPuzzleChoice $choice, null|Puzzle $puzzle, DateTimeImmutable $now): void
    {
        if ($choice === ImportPuzzleChoice::Undecided) {
            throw new \LogicException('Taking a decision back is decisionTakenBack().');
        }

        if (($choice === ImportPuzzleChoice::Catalogue) !== ($puzzle !== null)) {
            throw new \LogicException('A catalogue decision names its puzzle, any other names none.');
        }

        $this->choice = $choice;
        $this->puzzle = $puzzle;
        $this->decidedAt = $now;
        $this->decidedHow = ImportPuzzleDecidedHow::Player;
    }

    /**
     * Back to undecided - by the player, so a later run of the pairing does not tick it again.
     */
    public function decisionTakenBack(DateTimeImmutable $now): void
    {
        $this->choice = ImportPuzzleChoice::Undecided;
        $this->puzzle = null;
        $this->decidedAt = $now;
        $this->decidedHow = ImportPuzzleDecidedHow::Player;
    }

    /**
     * Whether a page that saw `$seen` as the decision's moment still looks at the current decision.
     */
    public function isDecisionOf(null|DateTimeImmutable $seen): bool
    {
        return $this->decidedAt?->format('Y-m-d H:i:s') === $seen?->format('Y-m-d H:i:s');
    }

    /**
     * The puzzle was merged into the survivor of a puzzle merge (ApprovePuzzleMergeRequestHandler). The moment of
     * the decision stays: nobody decided anything.
     */
    public function puzzleMergedInto(Puzzle $survivor): void
    {
        $this->puzzle = $survivor;
    }
}
