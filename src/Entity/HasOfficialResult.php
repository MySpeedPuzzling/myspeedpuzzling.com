<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * The organiser's official record of one round entry - a person in a solo round (CompetitionParticipantRound) or a
 * pair/team (CompetitionTeam): the result, who entered it and when, the qualified mark and the table number for this
 * round (docs/features/competitions-management/official-results.md). Written only through RecordRoundResults,
 * AssignTableNumbers and AdvanceQualified - the methods below never check anything, the handlers do.
 */
trait HasOfficialResult
{
    // Finished: total seconds
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $resultSeconds = null;

    // Did not finish: pieces placed when the time ran out
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $resultPiecesPlaced = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => false])]
    public bool $resultDidNotStart = false;

    // When the result was last changed - also when it was cleared
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $resultEnteredAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(onDelete: 'SET NULL')]
    public null|Player $resultEnteredBy = null;

    // The organiser's qualified mark - always an explicit decision, never derived from the ranking
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $qualifiedAt = null;

    // The entry's table number in this round (1..9999), unique within the round - checked by the write path under the
    // participants lock, not by an index, so swaps and renumbering in one change set work
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::SMALLINT, nullable: true)]
    public null|int $tableNumber = null;

    public function officialResult(): RoundEntryResult
    {
        return RoundEntryResult::fromColumns($this->resultSeconds, $this->resultPiecesPlaced, $this->resultDidNotStart);
    }

    /**
     * A finished result recorded or corrected while the round's results are published may be news for somebody not
     * told yet - RecordRoundResultsHandler runs the notification again once per change set (OfficialRoundResultsPublished).
     */
    public function recordResult(RoundEntryResult $result, null|Player $enteredBy, DateTimeImmutable $enteredAt): void
    {
        $this->resultSeconds = $result->seconds;
        $this->resultPiecesPlaced = $result->piecesPlaced;
        $this->resultDidNotStart = $result->didNotStart;
        $this->resultEnteredBy = $enteredBy;
        $this->resultEnteredAt = $enteredAt;
    }

    public function isQualified(): bool
    {
        return $this->qualifiedAt !== null;
    }

    public function markQualified(DateTimeImmutable $at): void
    {
        $this->qualifiedAt ??= $at;
    }

    public function unmarkQualified(): void
    {
        $this->qualifiedAt = null;
    }

    public function assignTableNumber(null|int $tableNumber): void
    {
        $this->tableNumber = $tableNumber;
    }

    /**
     * A result or a qualified mark - official data that must never disappear as a side effect of another change
     * (removing the entry, deleting the team, removing the person from the event).
     */
    public function hasOfficialData(): bool
    {
        return $this->officialResult()->isNone() === false || $this->isQualified();
    }
}
