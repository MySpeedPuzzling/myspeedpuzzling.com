<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\RoundEntryRef;

/**
 * A person in a round. In a solo round it is the round entry itself and carries the official result
 * (HasOfficialResult); in a pair/team round the team is the entry and these columns stay empty.
 */
#[Entity]
#[UniqueConstraint(name: 'competition_participant_round_unique', columns: ['participant_id', 'round_id'])]
class CompetitionParticipantRound
{
    use HasOfficialResult;

    // The entry's division (shared-puzzles-and-divisions.md §3, D1) - only on a solo entry; a pair/team member's row keeps
    // null (the team carries it). Lazy, never fetch-joined (RecordRoundResultsStatementPinTest)
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|CompetitionDivision $division = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public CompetitionParticipant $participant,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public CompetitionRound $round,
        #[ManyToOne]
        public null|CompetitionTeam $team = null,
    ) {
    }

    public function entryRef(): RoundEntryRef
    {
        return RoundEntryRef::participantRound($this->id->toString());
    }

    /**
     * A moved entry leaves its division behind - the new round's subset of divisions is its own (no callers today).
     */
    public function changeRound(CompetitionRound $newRound): void
    {
        $this->round = $newRound;
        $this->division = null;
    }

    public function changeDivision(null|CompetitionDivision $division): void
    {
        $this->division = $division;
    }

    public function assignToTeam(CompetitionTeam $team): void
    {
        $this->team = $team;
    }

    public function removeFromTeam(): void
    {
        $this->team = null;
    }
}
