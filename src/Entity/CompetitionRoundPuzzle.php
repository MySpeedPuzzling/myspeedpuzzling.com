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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Events\CompetitionRoundsChanged;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

#[Entity]
class CompetitionRoundPuzzle implements EntityWithEvents
{
    use HasEvents;

    /**
     * What the puzzle's site-wide hide dates say while a manual reveal waits for "Reveal now" - never reached.
     */
    public const string HIDDEN_UNTIL_REVEALED = '9999-12-31 00:00:00';

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne(inversedBy: 'roundPuzzles')]
        #[JoinColumn(nullable: false)]
        public CompetitionRound $round,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Puzzle $puzzle,
        #[Column(options: ['default' => false])]
        public bool $hideUntilRoundStarts = false,
        #[Column(nullable: true, enumType: PuzzleHideMode::class)]
        public null|PuzzleHideMode $hideMode = null,
        // When a secret puzzle is revealed - see RoundPuzzleReveal; change it through changeReveal() / revealNow()
        #[Column(enumType: RoundPuzzleReveal::class, options: ['default' => 'automatic'])]
        public RoundPuzzleReveal $revealMode = RoundPuzzleReveal::Automatic,
        // The organiser's own moment (RoundPuzzleReveal::Scheduled), null otherwise
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $revealAt = null,
        // The puzzle was created for this round, so the round keeps it secret on the whole site (puzzle.hide_until /
        // hide_image_until), not only on the event pages. A catalogue puzzle is public already - never hidden elsewhere.
        #[Column(options: ['default' => false])]
        public bool $hidesEverywhere = false,
    ) {
        $this->recordThat(new CompetitionRoundsChanged($this->round->competition->id));
        $this->syncPuzzleHide();
    }

    /**
     * Null = no moment: a manual reveal the organiser has not made yet (or the puzzle is not secret at all).
     */
    public function revealsAt(): null|DateTimeImmutable
    {
        if ($this->hideUntilRoundStarts === false) {
            return null;
        }

        return $this->revealMode->revealAt($this->round->startsAt, $this->revealAt);
    }

    public function isHiddenAt(DateTimeImmutable $now): bool
    {
        if ($this->hideUntilRoundStarts === false) {
            return false;
        }

        $revealsAt = $this->revealsAt();

        return $revealsAt === null || $revealsAt > $now;
    }

    /**
     * The organiser's choice on the round's puzzles page. A scheduled reveal needs its moment.
     */
    public function changeReveal(
        PuzzleHideMode $hideMode,
        RoundPuzzleReveal $revealMode,
        null|DateTimeImmutable $scheduledAt,
    ): void {
        if ($revealMode === RoundPuzzleReveal::Scheduled && $scheduledAt === null) {
            throw new \InvalidArgumentException('A scheduled reveal needs its moment.');
        }

        $this->hideUntilRoundStarts = true;
        $this->hideMode = $hideMode;
        $this->revealMode = $revealMode;
        $this->revealAt = $revealMode === RoundPuzzleReveal::Scheduled ? $scheduledAt : null;
        $this->syncPuzzleHide();
    }

    public function revealNow(DateTimeImmutable $now): void
    {
        $this->revealMode = RoundPuzzleReveal::Scheduled;
        $this->revealAt = $now;
        $this->syncPuzzleHide();
    }

    /**
     * Keeps the puzzle's site-wide hide dates equal to the reveal moment, so no page shows the puzzle before the
     * event pages do. Called by everything that moves the moment - also by a change of the round's start (an
     * automatic reveal follows it). Removing the puzzle from the round leaves the dates as they are: it stays hidden
     * until the moment the organiser was last shown, never revealed by accident.
     */
    public function syncPuzzleHide(): void
    {
        if ($this->hidesEverywhere === false || $this->hideUntilRoundStarts === false) {
            return;
        }

        $this->puzzle->hideUntilRevealed(
            $this->hideMode ?? PuzzleHideMode::Entirely,
            $this->revealsAt() ?? new DateTimeImmutable(self::HIDDEN_UNTIL_REVEALED),
        );
    }

    /**
     * A puzzle merge moved this round's puzzle onto another record. That record is a puzzle of its own (a catalogue
     * puzzle is public already), so the round keeps it secret on its event pages only.
     */
    public function moveToPuzzle(Puzzle $puzzle): void
    {
        $this->puzzle = $puzzle;
        $this->hidesEverywhere = false;
    }

    /**
     * The backfill (myspeedpuzzling:backfill-round-puzzle-reveals) found the round created this puzzle.
     */
    public function markHidesEverywhere(): void
    {
        $this->hidesEverywhere = true;
        $this->syncPuzzleHide();
    }

    /**
     * Call right before removing it - times that belonged to the round through this puzzle must be unlinked.
     */
    public function recordRemoval(): void
    {
        $this->recordThat(new CompetitionRoundsChanged($this->round->competition->id));
    }
}
