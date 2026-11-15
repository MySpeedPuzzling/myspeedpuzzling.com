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
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * A result of a player on one of their unlisted puzzles (docs/features/data-import/unlisted-puzzles.md) - **never a
 * row of `puzzle_solving_time`**: it is in no ranking, statistic, chart, streak, feed, XP, achievement or API answer,
 * and only its owner ever sees it. It holds what the row of the file said, as it said it (a day known to the month
 * only stays a month - and becomes a result known to its month when the puzzle is paired).
 *
 * When its puzzle is paired with a catalogue puzzle it becomes a real result through the import's writer
 * (ImportedResultWriter) - then `convertedSolvingTimeId` names that result (one the pairing made, or one the player
 * already had: `convertedCreated`) - or it stays unlisted with the reason (`keptReason`).
 */
#[Entity]
// One per row of a file: a row applied twice makes one
#[UniqueConstraint(columns: ['import_row_id'])]
#[Index(columns: ['player_id', 'source_key'])]
#[Index(columns: ['pairing_id'])]
class UnlistedResult
{
    /** The pairing that last took it - converted it, or left it unlisted with a reason */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $pairingId = null;

    /** The real result it became - a plain id: that result may be deleted like any other */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $convertedSolvingTimeId = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $convertedAt = null;

    /** True: the pairing made the result. False: the player had it already - an Undo never deletes that one */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => false])]
    public bool $convertedCreated = false;

    /**
     * What the pairing wrote ({puzzleId, seconds, day, teamId} - ImportRowAdder::resultFacts()) and the wish list
     * item the result removed: what an Undo compares with and puts back. Ids and numbers only.
     *
     * @var null|array<string, mixed>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $made = null;

    /** Why the last pairing left it unlisted (UnlistedKeptReason / ImportRowRefusal / ImportedResultRefusal values) */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 40, nullable: true)]
    public null|string $keptReason = null;

    /**
     * @param null|string $solvedOn the day as the row has it: `2024-03-09`, `2024-03` or `2024` - null: undated
     * @param null|string $datePrecision `day`, `month`, `year` - as the row has it
     * @param array<mixed> $people the other people's names, one per entry
     * @param null|bool $firstAttempt what the file said - null: it said nothing
     */
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
        #[Column(nullable: true)]
        public null|int $secondsToSolve,
        #[Immutable]
        #[Column(length: 10, nullable: true)]
        public null|string $solvedOn,
        #[Immutable]
        #[Column(length: 8, nullable: true)]
        public null|string $datePrecision,
        #[Immutable]
        #[Column(length: 8, enumType: PuzzlingType::class)]
        public PuzzlingType $puzzlingType,
        #[Immutable]
        #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
        public array $people,
        #[Immutable]
        #[Column(nullable: true)]
        public null|bool $firstAttempt,
        /** The column the player mapped to "Public comment" - public only once the result is a real one */
        #[Immutable]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $comment,
        /** The row of the file it came from - a plain id */
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $importRowId,
        /** The row's result key (`<version>:<sha256>`): the same data in a later import is "already yours" */
        #[Immutable]
        #[Column(length: 80)]
        public string $sourceKey,
        /** Where the row stood in its sheet - the order of the results of one day */
        #[Immutable]
        #[Column]
        public int $rowNumber,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        /** The file says the result is on MySpeedPuzzling already - a question when the puzzle is paired */
        #[Immutable]
        #[Column(options: ['default' => false])]
        public bool $saidAlreadyOnProfile = false,
        /** The player said of two equal rows "these are two" - told to the duplicate job when it becomes a real result */
        #[Immutable]
        #[Column(options: ['default' => false])]
        public bool $countedOnItsOwn = false,
    ) {
    }

    public function isUnlisted(): bool
    {
        return $this->convertedAt === null;
    }

    /**
     * The date as the import's writer takes it: the day, or the first day of the month or year the file knows, with
     * how exactly it is known (docs/features/data-import/date-precision.md). Null: undated - nothing to write.
     *
     * @return null|array{day: string, precision: DatePrecision}
     */
    public function dated(): null|array
    {
        if ($this->solvedOn === null || $this->datePrecision === null) {
            return null;
        }

        $precision = DatePrecision::tryFrom($this->datePrecision);

        if ($precision === null) {
            return null;
        }

        [$pattern, $rest] = match ($precision) {
            DatePrecision::Day => ['/^\d{4}-\d{2}-\d{2}$/', ''],
            DatePrecision::Month => ['/^\d{4}-\d{2}$/', '-01'],
            DatePrecision::Year => ['/^\d{4}$/', '-01-01'],
        };

        if (preg_match($pattern, $this->solvedOn) !== 1) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $this->solvedOn . $rest);

        return $day === false || $day->format('Y-m-d') !== $this->solvedOn . $rest ? null : ['day' => $this->solvedOn . $rest, 'precision' => $precision];
    }


    /**
     * A pairing takes it: whatever an earlier pairing said of it is gone.
     */
    public function takenByPairing(UuidInterface $pairingId): void
    {
        $this->pairingId = $pairingId;
        $this->keptReason = null;
    }

    /**
     * It is a real result now - one the pairing made ($created, with what it wrote), or one the player had already.
     *
     * @param null|array<string, mixed> $made
     */
    public function converted(UuidInterface $solvingTimeId, bool $created, null|array $made, DateTimeImmutable $now): void
    {
        $this->convertedSolvingTimeId = $solvingTimeId;
        $this->convertedCreated = $created;
        $this->convertedAt = $now;
        $this->made = $made;
        $this->keptReason = null;
    }

    /**
     * The pairing left it unlisted - or an Undo left the result it became where it is.
     */
    public function kept(string $reason): void
    {
        $this->keptReason = $reason;
    }

    /**
     * An Undo of the pairing: unlisted again, as before.
     */
    public function unlistedAgain(): void
    {
        $this->convertedSolvingTimeId = null;
        $this->convertedCreated = false;
        $this->convertedAt = null;
        $this->made = null;
        $this->keptReason = null;
    }
}
