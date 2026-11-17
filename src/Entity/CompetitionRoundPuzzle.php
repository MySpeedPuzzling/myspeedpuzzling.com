<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use DateTimeZone;
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
use SpeedPuzzling\Web\Value\RoundPuzzleLabel;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

#[Entity]
class CompetitionRoundPuzzle implements EntityWithEvents
{
    use HasEvents;

    /**
     * What the puzzle's site-wide hide dates say while a manual reveal waits for "Reveal now" - never reached.
     */
    public const string HIDDEN_UNTIL_REVEALED = '9999-12-31 00:00:00';

    // The organiser's order of the round's puzzles, 1..N (competition toolkit A2). Null = never arranged (every row saved
    // before positions existed, and a puzzle added after an arrangement): such rows follow the arranged ones in the
    // order they were added - RoundPuzzleLabel::sqlOrder() / sort(). Changed only through placeAt().
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::SMALLINT, nullable: true)]
    public null|int $position = null;

    // The neutral name judges and competitors see before the reveal ("Blue box") - null = the letter of its place
    // (RoundPuzzleLabel::default()). Changed only through placeAt().
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: RoundPuzzleLabel::MAX_LENGTH, nullable: true)]
    public null|string $label = null;

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
        // The puzzle was not public when this row made it secret (created for the round, or still secret from another
        // round), so this row keeps it secret on the whole site (puzzle.hide_until / hide_image_until - SecretPuzzleHides
        // combines every such row of the puzzle), not only on the event pages. A public catalogue puzzle never is.
        #[Column(options: ['default' => false])]
        public bool $hidesEverywhere = false,
    ) {
        $this->recordThat(new CompetitionRoundsChanged($this->round->competition->id));
    }

    /**
     * Null = no moment: a manual reveal the organiser has not made yet (or the puzzle is not secret at all).
     */
    public function revealsAt(): null|DateTimeImmutable
    {
        if ($this->hideUntilRoundStarts === false) {
            return null;
        }

        return $this->revealMode->revealAt($this->round->startsAt, $this->round->revealDelayMinutes, $this->revealAt);
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
     * The organiser's choice on the round's puzzles page. A scheduled reveal needs its moment. Re-sync the puzzle's
     * site-wide hide dates afterwards (SecretPuzzleHides).
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
        // Stored as UTC like every instant - never a wall clock of some zone
        $this->revealAt = $revealMode === RoundPuzzleReveal::Scheduled
            ? $scheduledAt->setTimezone(new DateTimeZone('UTC'))
            : null;

        // A revealed round puzzle matches series picks by puzzle, a hidden one does not
        // (docs/features/events-page/high-frequency-series.md, "Secret puzzles")
        $this->recordThat(new CompetitionRoundsChanged($this->round->competition->id));
    }

    public function revealNow(DateTimeImmutable $now): void
    {
        $this->revealMode = RoundPuzzleReveal::Scheduled;
        $this->revealAt = $now->setTimezone(new DateTimeZone('UTC'));

        // Revealed: series picks on the puzzle are matched to the edition by puzzle now
        $this->recordThat(new CompetitionRoundsChanged($this->round->competition->id));
    }

    /**
     * The round's stopwatch starts (now, at a scheduled moment, or resumes) with "Reveal the puzzles" ticked: a row
     * waiting for it (RoundPuzzleReveal::Stopwatch, no moment yet) is revealed at that moment - a moment ahead (a
     * scheduled start) keeps it hidden until then. The mode stays `stopwatch`: reveal_at says when it came out. Re-sync
     * the puzzle's site-wide hide dates afterwards (SecretPuzzleHides).
     *
     * @return bool whether the row was waiting for the start (and now has its moment)
     */
    public function revealAtStopwatchStart(DateTimeImmutable $startMoment): bool
    {
        if (
            $this->hideUntilRoundStarts === false
            || $this->revealMode !== RoundPuzzleReveal::Stopwatch
            || $this->revealAt !== null
        ) {
            return false;
        }

        $this->revealAt = $startMoment->setTimezone(new DateTimeZone('UTC'));
        $this->recordThat(new CompetitionRoundsChanged($this->round->competition->id));

        return true;
    }

    /**
     * A start with the tick whose moment is still ahead is called off or moved (cancel, stop or reset of a countdown, a
     * new schedule, a new start): the row waits for the next start again. A moment already passed is never taken back -
     * once out, never hidden again.
     *
     * @return bool whether a moment was taken back
     */
    public function withdrawStopwatchReveal(DateTimeImmutable $now): bool
    {
        if (
            $this->revealMode !== RoundPuzzleReveal::Stopwatch
            || $this->revealAt === null
            || $this->revealAt <= $now
        ) {
            return false;
        }

        $this->revealAt = null;
        $this->recordThat(new CompetitionRoundsChanged($this->round->competition->id));

        return true;
    }

    /**
     * Waits for the round's stopwatch start with the tick - secret, `stopwatch` mode and no moment (or a moment ahead).
     */
    public function waitsForStopwatchStart(DateTimeImmutable $now): bool
    {
        return $this->hideUntilRoundStarts
            && $this->revealMode === RoundPuzzleReveal::Stopwatch
            && $this->isHiddenAt($now);
    }

    /**
     * The round's start moves after this automatic reveal already happened: the reveal stays where it was (the puzzle is
     * public), it does not follow the round into the future.
     */
    public function pinRevealAt(DateTimeImmutable $revealedAt): void
    {
        $this->revealMode = RoundPuzzleReveal::Scheduled;
        $this->revealAt = $revealedAt->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The organiser arranged the round's puzzles (ArrangeRoundPuzzles): its place 1..N and its own label.
     */
    public function placeAt(int $position, null|string $label): void
    {
        if ($position < 1) {
            throw new \InvalidArgumentException('A round puzzle\'s position starts at 1.');
        }

        $this->position = $position;
        $this->label = RoundPuzzleLabel::normalise($label);
    }

    /**
     * The puzzle was not public when this row made it secret - see $hidesEverywhere.
     */
    public function keepHiddenEverywhere(): void
    {
        $this->hidesEverywhere = true;
    }

    /**
     * A puzzle merge moved this round's puzzle onto another record. Merges of secret puzzles are refused, so only rows
     * of public puzzles move - and on the survivor (a public catalogue puzzle) the row never hides anything again.
     */
    public function moveToPuzzle(Puzzle $puzzle): void
    {
        $this->puzzle = $puzzle;
        $this->hidesEverywhere = false;
    }

    /**
     * Call right before removing it - times that belonged to the round through this puzzle must be unlinked.
     */
    public function recordRemoval(): void
    {
        $this->recordThat(new CompetitionRoundsChanged($this->round->competition->id));
    }
}
