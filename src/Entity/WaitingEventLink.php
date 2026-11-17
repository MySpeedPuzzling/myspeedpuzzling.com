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
use LogicException;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * A solving time a player linked to an event that is not public yet (docs/features/competitions-management/
 * creation-wizard.md "Backfill"): typically the past event they just added, still waiting for an admin's approval. A
 * time is never linked to an event nobody may see - every reader of puzzle_solving_time would show its name - so the
 * link waits here and LinkSolvingTimesToEventHandler's rule applies it once the event (or series) is publicly visible
 * (WaitingEventLinksApplier, at the approval or publish itself; ApplyWaitingEventLinks in the 15-minute reconcile cron
 * as the safety net). Exactly one target is set. One row per time: linking it again
 * replaces the row. Rows cascade with the time, the event, the series and the player who linked it.
 */
#[Entity]
#[UniqueConstraint(columns: ['puzzle_solving_time_id'])]
class WaitingEventLink
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(name: 'puzzle_solving_time_id', nullable: false, onDelete: 'CASCADE')]
        public PuzzleSolvingTime $solvingTime,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|Competition $competition,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(name: 'series_id', nullable: true, onDelete: 'CASCADE')]
        public null|CompetitionSeries $series,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(name: 'linked_by_player_id', nullable: false, onDelete: 'CASCADE')]
        public Player $linkedBy,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        // The link the player saw when they linked it (the time's event, or its series pick) - the waiting link replaces
        // exactly that one; a time linked otherwise meanwhile keeps its new link (seenLinkIsStillOn()). Plain ids, no
        // foreign keys: an event deleted meanwhile changed the time's link too
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $seenCompetitionId = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $seenSeriesId = null,
    ) {
        if (($competition === null) === ($series === null)) {
            throw new LogicException('A waiting link names exactly one event or one series.');
        }
    }

    /**
     * The time is linked again while it waits - to another event, or by another member of its pair/team: one row per
     * time, the latest choice waits
     */
    public function retarget(null|Competition $competition, null|CompetitionSeries $series, Player $linkedBy, DateTimeImmutable $at, null|UuidInterface $seenCompetitionId, null|UuidInterface $seenSeriesId): void
    {
        if (($competition === null) === ($series === null)) {
            throw new LogicException('A waiting link names exactly one event or one series.');
        }

        $this->competition = $competition;
        $this->series = $series;
        $this->linkedBy = $linkedBy;
        $this->createdAt = $at;
        $this->seenCompetitionId = $seenCompetitionId;
        $this->seenSeriesId = $seenSeriesId;
    }

    /**
     * Whether the time still has the link the player saw: the same series pick (its edition is derived and may move),
     * else the same explicit event, or still none
     */
    public function seenLinkIsStillOn(): bool
    {
        $series = $this->solvingTime->competitionSeries;

        if ($this->seenSeriesId !== null || $series !== null) {
            return $series !== null && $this->seenSeriesId?->equals($series->id) === true;
        }

        $competition = $this->solvingTime->competition;

        return $competition === null
            ? $this->seenCompetitionId === null
            : $this->seenCompetitionId?->equals($competition->id) === true;
    }
}
