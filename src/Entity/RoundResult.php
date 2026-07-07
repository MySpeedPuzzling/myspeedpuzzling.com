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
use InvalidArgumentException;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * Organizer-entered official result of a round entrant.
 *
 * Exactly one of participant (solo rounds) / team (duo+team rounds) is set.
 * secondsToSolve NULL = did not finish within the time limit (ranked by missingPieces).
 * Ranks are always computed on read, never stored.
 */
#[Entity]
#[Table]
#[UniqueConstraint(columns: ['round_id', 'participant_id'])]
#[UniqueConstraint(columns: ['round_id', 'team_id'])]
class RoundResult
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $updatedAt = null;

    /**
     * The PuzzleSolvingTime materialized when a player claimed this result.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(onDelete: 'SET NULL')]
    public null|PuzzleSolvingTime $solvingTime = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionRound $round,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public null|CompetitionParticipant $participant,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public null|CompetitionTeam $team,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|int $secondsToSolve,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|int $missingPieces,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
        if (($participant === null) === ($team === null)) {
            throw new InvalidArgumentException('Round result must have exactly one of participant or team.');
        }
    }

    public function updateResult(null|int $secondsToSolve, null|int $missingPieces, DateTimeImmutable $updatedAt): void
    {
        $this->secondsToSolve = $secondsToSolve;
        $this->missingPieces = $missingPieces;
        $this->updatedAt = $updatedAt;
    }

    public function linkSolvingTime(PuzzleSolvingTime $solvingTime): void
    {
        $this->solvingTime = $solvingTime;
    }

    public function unlinkSolvingTime(): void
    {
        $this->solvingTime = null;
    }

    public function isDnf(): bool
    {
        return $this->secondsToSolve === null;
    }
}
