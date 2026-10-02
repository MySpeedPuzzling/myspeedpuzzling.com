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
use SpeedPuzzling\Web\Exceptions\DuplicatePuzzleSignalAlreadyResolved;
use SpeedPuzzling\Web\Value\DuplicatePuzzleSignalStatus;

/**
 * Two puzzle records the same person logged the same time on, on the same day, with the same piece count - most
 * likely one puzzle entered twice (docs/features/duplicate-results.md, Layer 4 "Catalogue signal"). Not a
 * duplicate result: a merge hint for admins. Found by the daily detection; once the puzzles are merged, the
 * signal goes with the deleted puzzle (ON DELETE CASCADE) and the results it was about become ordinary twins.
 *
 * The pair is stored ordered (puzzle A has the lower id), so it has one row whatever the order it was found in.
 */
#[Entity]
#[UniqueConstraint(columns: ['puzzle_a_id', 'puzzle_b_id'])]
#[Index(columns: ['status'])]
class DuplicatePuzzleSignal
{
    // Changed only through the named methods below
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: DuplicatePuzzleSignalStatus::class)]
    public DuplicatePuzzleSignalStatus $status = DuplicatePuzzleSignalStatus::Open;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $resolvedAt = null;

    // The merge request "Propose merge" filed - no foreign key, the request outlives the puzzles it merges
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $mergeRequestId = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Puzzle $puzzleA,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Puzzle $puzzleB,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $detectedAt,
        // Results on either puzzle that have a twin on the other one, and the people who logged them
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::INTEGER)]
        public int $matchingResults,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::INTEGER)]
        public int $matchingPeople,
        // One of them, to look at: who, which time, which day. No foreign key on the player - only shown
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $examplePlayerId,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::INTEGER)]
        public int $exampleSeconds,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATE_IMMUTABLE)]
        public DateTimeImmutable $exampleDay,
        // How strongly the rest points at one puzzle entered twice (DuplicatePuzzleSignalScoring): the points, the
        // reasons (DuplicatePuzzleSignalReason values), the name similarity for the admin, and whether it is weak
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::INTEGER, options: ['default' => 0])]
        public int $score = 0,
        /** @var list<string> */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSON, options: ['default' => '[]'])]
        public array $reasons = [],
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::FLOAT, options: ['default' => 0])]
        public float $nameSimilarity = 0.0,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::BOOLEAN, options: ['default' => false])]
        public bool $weak = false,
    ) {
    }

    public static function key(string $puzzleAId, string $puzzleBId): string
    {
        return $puzzleAId . '|' . $puzzleBId;
    }

    public function pairKey(): string
    {
        return self::key($this->puzzleA->id->toString(), $this->puzzleB->id->toString());
    }

    /**
     * The detection ran again: an open signal shows what is there now, scored anew. A decided one keeps what it was
     * decided on.
     *
     * @param list<string> $reasons
     */
    public function refresh(
        int $matchingResults,
        int $matchingPeople,
        UuidInterface $examplePlayerId,
        int $exampleSeconds,
        DateTimeImmutable $exampleDay,
        int $score,
        array $reasons,
        float $nameSimilarity,
        bool $weak,
    ): void {
        if ($this->status !== DuplicatePuzzleSignalStatus::Open) {
            return;
        }

        $this->matchingResults = $matchingResults;
        $this->matchingPeople = $matchingPeople;
        $this->examplePlayerId = $examplePlayerId;
        $this->exampleSeconds = $exampleSeconds;
        $this->exampleDay = $exampleDay;
        $this->score = $score;
        $this->reasons = $reasons;
        $this->nameSimilarity = $nameSimilarity;
        $this->weak = $weak;
    }

    /**
     * @throws DuplicatePuzzleSignalAlreadyResolved
     */
    public function proposeMerge(UuidInterface $mergeRequestId, DateTimeImmutable $now): void
    {
        $this->resolve(DuplicatePuzzleSignalStatus::MergeProposed, $now);
        $this->mergeRequestId = $mergeRequestId;
    }

    /**
     * @throws DuplicatePuzzleSignalAlreadyResolved
     */
    public function dismiss(DateTimeImmutable $now): void
    {
        $this->resolve(DuplicatePuzzleSignalStatus::Dismissed, $now);
    }

    /**
     * @throws DuplicatePuzzleSignalAlreadyResolved
     */
    private function resolve(DuplicatePuzzleSignalStatus $status, DateTimeImmutable $now): void
    {
        if ($this->status !== DuplicatePuzzleSignalStatus::Open) {
            throw new DuplicatePuzzleSignalAlreadyResolved();
        }

        $this->status = $status;
        $this->resolvedAt = $now;
    }
}
