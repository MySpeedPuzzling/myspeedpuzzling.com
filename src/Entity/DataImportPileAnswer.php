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
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * One answer for a whole pile of an import's puzzles (docs/features/data-import/guided-flow.md §6.2, §6.3): "Keep
 * them just for me" / "Don't import them" for every puzzle of the pile that waited when the answer was given.
 *
 * It is the durable word that **an answer runs**: written under the import's lock, so one answer at a time per
 * import and pile - a second one is told "already being answered", never applied beside the first. It holds what
 * the answer is for: the state of the import the screen showed (`revision` - a turn that finds another one ends the
 * answer), the moment it was given (`startedAt` - a puzzle that entered the pile afterwards is not answered), and
 * where the turns got to. It names no puzzle.
 */
#[Entity]
#[Index(columns: ['import_id', 'pile', 'finished_at'])]
class DataImportPileAnswer
{
    public const string DONE = 'done';
    /** The import does not stand as the screen showed it any more: nothing more is answered */
    public const string CHANGED = 'changed';
    /** No sign of life for too long (a worker died, a job was lost) - ended by the sweeper */
    public const string FAILED = 'failed';

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastActivityAt;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $finishedAt = null;

    /** DONE, CHANGED or FAILED - null while it runs */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, nullable: true)]
    public null|string $endedAs = null;

    /** The last puzzle a turn looked at - the next turn goes on after it */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $lastPuzzleId = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $puzzlesDecided = 0;

    /** Decided by the player meanwhile, and left as they decided */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $puzzlesSkipped = 0;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(name: 'import_id', nullable: false, onDelete: 'CASCADE')]
        public DataImport $import,
        /** A key of GetImportPuzzleScreen::PILES */
        #[Immutable]
        #[Column(length: 16)]
        public string $pile,
        /** An ImportPuzzleChoice's value the pile allows (ImportPuzzlePile::CHOICES) */
        #[Immutable]
        #[Column(length: 16)]
        public string $choice,
        /** The import as the screen showed it (DataImportProgress::puzzlesRevision()) */
        #[Immutable]
        #[Column(length: 120)]
        public string $revision,
        /** How many puzzles of the pile waited when the answer was given */
        #[Immutable]
        #[Column]
        public int $puzzlesTotal,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $startedAt,
    ) {
        $this->lastActivityAt = $startedAt;
    }

    public function isOpen(): bool
    {
        return $this->finishedAt === null;
    }

    public function turnDone(int $decided, int $skipped, null|UuidInterface $cursor, DateTimeImmutable $now): void
    {
        $this->puzzlesDecided += $decided;
        $this->puzzlesSkipped += $skipped;
        $this->lastPuzzleId = $cursor ?? $this->lastPuzzleId;
        $this->lastActivityAt = $now;
    }

    public function ended(string $as, DateTimeImmutable $now): void
    {
        $this->endedAs = $as;
        $this->finishedAt = $now;
        $this->lastActivityAt = $now;
    }
}
