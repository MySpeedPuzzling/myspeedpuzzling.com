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
use SpeedPuzzling\Web\Attribute\DeleteDomainEvent;
use SpeedPuzzling\Web\Doctrine\PuzzlersGroupDoctrineType;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeDeleted;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeModified;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\PuzzlingType;

#[Entity]
#[Index(columns: ['tracked_at'])]
#[Index(columns: ['puzzlers_count'])]
#[Index(columns: ['puzzling_type'])]
#[DeleteDomainEvent(PuzzleSolvingTimeDeleted::class)]
class PuzzleSolvingTime implements EntityWithEvents
{
    use HasEvents;

    #[Column(type: Types::SMALLINT, options: ['default' => 1])]
    public int $puzzlersCount;

    #[Column(options: ['default' => PuzzlingType::Solo->value])]
    public PuzzlingType $puzzlingType;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Column(type: Types::INTEGER, nullable: true)]
        public null|int $secondsToSolve,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $player,
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Puzzle $puzzle,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $trackedAt,
        #[Column(type: Types::BOOLEAN)]
        public bool $verified,
        #[Column(type: PuzzlersGroupDoctrineType::NAME, nullable: true)]
        public null|PuzzlersGroup $team,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $finishedAt,
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $comment,
        #[Column(nullable: true)]
        public null|string $finishedPuzzlePhoto,
        #[Column(options: ['default' => 0])]
        public bool $firstAttempt,
        #[ManyToOne]
        public null|CompetitionRound $competitionRound = null,
        #[ManyToOne]
        public null|Competition $competition = null,
        #[Column(nullable: true)]
        public null|int $missingPieces = null,
        #[Column(nullable: true)]
        public null|bool $qualified = null,
        #[Column(options: ['default' => false])]
        public bool $suspicious = false,
    ) {
        $this->puzzlersCount = $this->calculatePuzzlersCount();
        $this->puzzlingType = PuzzlingType::fromPuzzlersCount($this->puzzlersCount);

        $this->recordThat(
            new PuzzleSolved($this->id, $this->puzzle->id),
        );
    }

    public function modify(
        null|int $seconds,
        null|string $comment,
        null|PuzzlersGroup $puzzlersGroup,
        DateTimeImmutable $finishedAt,
        null|string $finishedPuzzlePhoto,
        bool $firstAttempt,
        null|Competition $competition,
    ): void {
        $this->secondsToSolve = $seconds;
        $this->comment = $comment;
        $this->team = $puzzlersGroup;
        $this->finishedAt = $finishedAt;
        $this->finishedPuzzlePhoto = $finishedPuzzlePhoto;
        $this->firstAttempt = $firstAttempt;
        $this->competition = $competition;

        $this->puzzlersCount = $this->calculatePuzzlersCount();
        $this->puzzlingType = PuzzlingType::fromPuzzlersCount($this->puzzlersCount);

        $this->recordThat(
            new PuzzleSolvingTimeModified($this->id, $this->puzzle->id),
        );
    }

    private function calculatePuzzlersCount(): int
    {
        if ($this->team === null) {
            return 1;
        }

        return count($this->team->puzzlers);
    }
}
