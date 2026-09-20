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
use SpeedPuzzling\Web\Attribute\HasDeleteDomainEvent;
use SpeedPuzzling\Web\Doctrine\PuzzlersGroupDoctrineType;
use SpeedPuzzling\Web\Events\GroupSolvingTimeEdited;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeDeleted;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeModified;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\PuzzlingType;

#[Entity]
#[Index(columns: ['tracked_at'])]
#[Index(columns: ['puzzlers_count'])]
#[Index(columns: ['puzzling_type'])]
#[HasDeleteDomainEvent(PuzzleSolvingTimeDeleted::class)]
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
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $finishedAt,
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $comment,
        #[Column(nullable: true)]
        public null|string $finishedPuzzlePhoto,
        #[Column(options: ['default' => 0])]
        public bool $firstAttempt,
        #[Column(options: ['default' => 0])]
        public bool $unboxed,
        #[ManyToOne]
        public null|CompetitionRound $competitionRound = null,
        #[ManyToOne]
        public null|Competition $competition = null,
        // Unfinished competition result: pieces placed when the round's time ran out. Such a result never has
        // secondsToSolve, so every leaderboard and statistic (which read only secondsToSolve) leaves it out
        #[Column(nullable: true)]
        public null|int $piecesPlaced = null,
        #[Column(nullable: true)]
        public null|bool $qualified = null,
        #[Column(options: ['default' => false])]
        public bool $suspicious = false,
        // Total time of an unfinished result whose solver kept going after the limit - display only
        #[Column(nullable: true)]
        public null|int $finishedLaterSeconds = null,
        // The pair/team this group is (PuzzlingTeamResolver) - always set together with $team, null for solo
        #[ManyToOne]
        #[JoinColumn(onDelete: 'RESTRICT')]
        public null|PuzzlingTeam $puzzlingTeam = null,
    ) {
        $this->puzzlersCount = $this->calculatePuzzlersCount();
        $this->puzzlingType = PuzzlingType::fromPuzzlersCount($this->puzzlersCount);

        $this->recordThat(
            new PuzzleSolved($this->id, $this->puzzle->id),
        );
    }

    /**
     * The round is never chosen by hand - it follows from competition + puzzle + solo/duo/team
     * (see SolvingTimeRoundResolver), so callers set it after the time's other data is final.
     */
    /**
     * Whoever tracked the time, plus every registered member of its group. Puzzlers stored
     * by name only have no account, so they can never match.
     */
    public function canBeModifiedBy(Player $player): bool
    {
        if ($this->player->id->equals($player->id)) {
            return true;
        }

        foreach ($this->team->puzzlers ?? [] as $puzzler) {
            if ($puzzler->playerId === $player->id->toString()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whoever tracked the time plus every registered puzzler of its group.
     *
     * @return list<string>
     */
    public function memberPlayerIds(): array
    {
        $ids = [$this->player->id->toString()];

        foreach ($this->team->puzzlers ?? [] as $puzzler) {
            if ($puzzler->playerId !== null) {
                $ids[] = $puzzler->playerId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<string> $memberPlayerIdsBeforeEdit
     */
    public function recordGroupEdit(Player $editedBy, array $memberPlayerIdsBeforeEdit): void
    {
        $this->recordThat(
            new GroupSolvingTimeEdited($this->id, $editedBy->id, $memberPlayerIdsBeforeEdit),
        );
    }

    public function changeCompetitionRound(null|CompetitionRound $competitionRound): void
    {
        $this->competitionRound = $competitionRound;
    }

    public function modify(
        null|int $seconds,
        null|string $comment,
        null|PuzzlersGroup $puzzlersGroup,
        null|DateTimeImmutable $finishedAt,
        null|string $finishedPuzzlePhoto,
        bool $firstAttempt,
        bool $unboxed,
        null|Competition $competition,
        null|PuzzlingTeam $puzzlingTeam,
    ): void {
        $this->secondsToSolve = $seconds;
        $this->comment = $comment;
        $this->team = $puzzlersGroup;
        $this->puzzlingTeam = $puzzlersGroup === null ? null : $puzzlingTeam;
        $this->finishedAt = $finishedAt;
        $this->finishedPuzzlePhoto = $finishedPuzzlePhoto;
        $this->firstAttempt = $firstAttempt;
        $this->unboxed = $unboxed;
        $this->competition = $competition;

        $this->puzzlersCount = $this->calculatePuzzlersCount();
        $this->puzzlingType = PuzzlingType::fromPuzzlersCount($this->puzzlersCount);

        $this->recordThat(
            new PuzzleSolvingTimeModified($this->id, $this->puzzle->id),
        );
    }

    public function migrateToPuzzle(Puzzle $newPuzzle): void
    {
        $this->puzzle = $newPuzzle;

        $this->recordThat(
            new PuzzleSolvingTimeModified($this->id, $newPuzzle->id),
        );
    }

    public function transferOwnership(Player $newOwner, null|PuzzlersGroup $newTeam): void
    {
        $this->player = $newOwner;
        $this->team = $newTeam;
        $this->puzzlersCount = $this->calculatePuzzlersCount();
        $this->puzzlingType = PuzzlingType::fromPuzzlersCount($this->puzzlersCount);
    }

    public function replaceTeam(null|PuzzlersGroup $newTeam): void
    {
        $this->team = $newTeam;
        $this->puzzlersCount = $this->calculatePuzzlersCount();
        $this->puzzlingType = PuzzlingType::fromPuzzlersCount($this->puzzlersCount);
    }

    private function calculatePuzzlersCount(): int
    {
        if ($this->team === null) {
            return 1;
        }

        return count($this->team->puzzlers);
    }
}
