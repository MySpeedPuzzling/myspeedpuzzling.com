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
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Attribute\HasDeleteDomainEvent;
use SpeedPuzzling\Web\Doctrine\PuzzlersGroupDoctrineType;
use SpeedPuzzling\Web\Events\GroupSolvingTimeEdited;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeDeleted;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeModified;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeMovedToOtherPuzzle;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\RemovedResultSnapshot;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use SpeedPuzzling\Web\Value\TimePredictionMethod;
use SpeedPuzzling\Web\Value\TimePredictionSource;

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

    // What we predicted for this solve at that moment (docs/features/puzzle-intelligence/prediction-history.md).
    // true = predicted, false = the data was not there, null = not evaluated: duo/team and times without seconds
    // stay null for good, a solo time with seconds is pending until the backfill or a live add fills it.
    // Changed only through recordPrediction() / forgetPrediction()
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|bool $predictable = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: TimePredictionMethod::class)]
    public null|TimePredictionMethod $predictionMethod = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $predictedSeconds = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $predictedRangeLowSeconds = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $predictedRangeHighSeconds = null;

    // Personal predictions only: which attempt was predicted, and the time of the attempt before it
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::SMALLINT, nullable: true)]
    public null|int $predictedAttemptNumber = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $predictionLastTimeSeconds = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: TimePredictionSource::class)]
    public null|TimePredictionSource $predictionSource = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $predictionComputedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::SMALLINT, nullable: true)]
    public null|int $predictionModelVersion = null;

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
        // Where it was saved from (docs/features/duplicate-results.md) - null for results older than the column
        #[Immutable]
        #[Column(type: Types::STRING, nullable: true, enumType: SolvingTimeSource::class)]
        public null|SolvingTimeSource $createdVia = null,
    ) {
        $this->puzzlersCount = $this->calculatePuzzlersCount();
        $this->puzzlingType = PuzzlingType::fromPuzzlersCount($this->puzzlersCount);

        $this->recordThat(
            new PuzzleSolved($this->id, $this->puzzle->id),
        );
    }

    /**
     * Brings back a result removed automatically as a copy, with its own id and everything it had
     * (docs/features/duplicate-results.md, "Undo"). Statistics and insights follow PuzzleSolvingTimeModified -
     * not PuzzleSolved: nobody is notified again and no wishlist changes for a result that existed before.
     */
    public static function restore(
        RemovedResultSnapshot $snapshot,
        Player $player,
        Puzzle $puzzle,
        null|Competition $competition,
        null|PuzzlingTeam $puzzlingTeam,
    ): self {
        $group = $snapshot->group();

        $time = new self(
            id: Uuid::fromString($snapshot->id),
            secondsToSolve: $snapshot->secondsToSolve,
            player: $player,
            puzzle: $puzzle,
            trackedAt: $snapshot->trackedAt,
            verified: $snapshot->verified,
            team: $group,
            finishedAt: $snapshot->finishedAt,
            comment: $snapshot->comment,
            finishedPuzzlePhoto: $snapshot->finishedPuzzlePhoto,
            firstAttempt: $snapshot->firstAttempt,
            unboxed: $snapshot->unboxed,
            competition: $competition,
            piecesPlaced: $snapshot->piecesPlaced,
            qualified: $snapshot->qualified,
            suspicious: $snapshot->suspicious,
            finishedLaterSeconds: $snapshot->finishedLaterSeconds,
            puzzlingTeam: $group === null ? null : $puzzlingTeam,
            createdVia: $snapshot->createdVia,
        );

        $time->predictable = $snapshot->predictable;
        $time->predictionMethod = $snapshot->predictionMethod;
        $time->predictedSeconds = $snapshot->predictedSeconds;
        $time->predictedRangeLowSeconds = $snapshot->predictedRangeLowSeconds;
        $time->predictedRangeHighSeconds = $snapshot->predictedRangeHighSeconds;
        $time->predictedAttemptNumber = $snapshot->predictedAttemptNumber;
        $time->predictionLastTimeSeconds = $snapshot->predictionLastTimeSeconds;
        $time->predictionSource = $snapshot->predictionSource;
        $time->predictionComputedAt = $snapshot->predictionComputedAt;
        $time->predictionModelVersion = $snapshot->predictionModelVersion;

        $time->popEvents();
        $time->recordThat(new PuzzleSolvingTimeModified($time->id, $puzzle->id));

        return $time;
    }

    /**
     * The player keeps this copy and deletes its twins: whatever only a twin had - photo, comment, first-try
     * tag, competition - is not lost with it (docs/features/duplicate-results.md, "Keep this one"); with several
     * twins the first one in the list that has it wins. The first-try tag only when the caller checked that it
     * may move here. The round follows from the competition; the caller resolves it once this is done.
     *
     * @param list<self> $copies
     * @return bool whether anything was taken over
     */
    public function takeOverFrom(array $copies, Player $by, bool $withFirstTry): bool
    {
        $changed = false;

        foreach ($copies as $copy) {
            if ($this->finishedPuzzlePhoto === null && $copy->finishedPuzzlePhoto !== null) {
                $this->finishedPuzzlePhoto = $copy->finishedPuzzlePhoto;
                $changed = true;
            }

            if (trim($this->comment ?? '') === '' && trim($copy->comment ?? '') !== '') {
                $this->comment = $copy->comment;
                $changed = true;
            }

            if ($withFirstTry && $this->firstAttempt === false && $copy->firstAttempt === true) {
                $this->firstAttempt = true;
                $changed = true;
            }

            if ($this->competition === null && $copy->competition !== null) {
                $this->competition = $copy->competition;
                $changed = true;
            }
        }

        if ($changed === false) {
            return false;
        }

        $this->recordThat(
            new PuzzleSolvingTimeModified($this->id, $this->puzzle->id),
        );

        if ($this->team !== null) {
            $this->recordGroupEdit($by, $this->memberPlayerIds());
        }

        return true;
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
     * Whether a new entry carrying this result's id is this result sent again (docs/features/duplicate-results.md,
     * Layer 1): the same puzzle, time and day. Anything else - the player went back and corrected the time - is a
     * different result that merely reuses the id. Without a date on either side the day is the day it was saved.
     */
    public function isSameEntryAs(string $puzzleId, null|int $secondsToSolve, null|DateTimeImmutable $finishedAt, DateTimeImmutable $now): bool
    {
        if ($this->puzzle->id->toString() !== strtolower($puzzleId) || $this->secondsToSolve !== $secondsToSolve) {
            return false;
        }

        if ($this->finishedAt === null && $finishedAt === null) {
            return true;
        }

        return ($this->finishedAt ?? $this->trackedAt)->format('Y-m-d') === ($finishedAt ?? $now)->format('Y-m-d');
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

    /**
     * Takes the first-try tag off (docs/features/first-try-integrity.md). Statistics and insights follow the
     * PuzzleSolvingTimeModified event; the stored prediction stays - it is what was predicted back then, and
     * the tag of this very result is not among its inputs. Everybody else of a pair/team is told who did it.
     */
    public function unmarkFirstAttempt(Player $by): void
    {
        if ($this->firstAttempt === false) {
            return;
        }

        $this->firstAttempt = false;

        $this->recordThat(
            new PuzzleSolvingTimeModified($this->id, $this->puzzle->id),
        );

        if ($this->team !== null) {
            $this->recordGroupEdit($by, $this->memberPlayerIds());
        }
    }

    /**
     * A moderator marked the time (docs/features/suspicious-time-review.md): from now on it is neither counted nor
     * timed anywhere (docs/features/suspicious-times.md). Statistics, insights and duplicate detection follow the
     * PuzzleSolvingTimeModified event like after any edit - none of its consumers notifies anybody.
     */
    public function markSuspicious(): void
    {
        if ($this->suspicious) {
            return;
        }

        $this->suspicious = true;

        $this->recordThat(
            new PuzzleSolvingTimeModified($this->id, $this->puzzle->id),
        );
    }

    /**
     * Unmarked - by a moderator, or automatically after the player fixed the time. Counts again everywhere.
     */
    public function clearSuspicion(): void
    {
        if ($this->suspicious === false) {
            return;
        }

        $this->suspicious = false;

        $this->recordThat(
            new PuzzleSolvingTimeModified($this->id, $this->puzzle->id),
        );
    }

    /**
     * The flag was changed by SQL - only the event, so statistics and insights follow the change already made. The
     * entity itself does not change, so Doctrine sees no update and DomainEventsSubscriber never picks this event
     * up: whoever calls it hands popEvents() to the bus (the suspicious time scan's flag reconciliation).
     */
    public function suspicionChangedOutsideTheApp(): void
    {
        $this->recordThat(
            new PuzzleSolvingTimeModified($this->id, $this->puzzle->id),
        );
    }

    public function changeCompetitionRound(null|CompetitionRound $competitionRound): void
    {
        $this->competitionRound = $competitionRound;
    }

    /**
     * Only a solo time with seconds is ever predicted. Records no domain event on purpose: the backfill
     * writes ~450k of these, and PuzzleSolvingTimeModified would recalculate the insights for each one.
     */
    public function recordPrediction(SolvingTimePrediction $prediction, DateTimeImmutable $computedAt): void
    {
        $result = $prediction->result;

        $this->predictable = $prediction->isPredictable();
        $this->predictionMethod = match (true) {
            $result === null => null,
            $result->isPersonalized => TimePredictionMethod::Personal,
            default => TimePredictionMethod::Statistical,
        };
        $this->predictedSeconds = $result?->predictedSeconds;
        $this->predictedRangeLowSeconds = $result?->rangeLowSeconds;
        $this->predictedRangeHighSeconds = $result?->rangeHighSeconds;
        $this->predictedAttemptNumber = $result?->isPersonalized === true ? $result->predictedAttemptNumber : null;
        $this->predictionLastTimeSeconds = $result?->isPersonalized === true ? $result->lastTimeSeconds : null;
        $this->predictionSource = $prediction->source;
        $this->predictionComputedAt = $computedAt;
        $this->predictionModelVersion = $prediction->modelVersion;
    }

    /**
     * Solo with seconds and not evaluated yet - what the live add, the edit and the backfill pick up.
     */
    public function isPredictionPending(): bool
    {
        return $this->predictable === null
            && $this->puzzlingType === PuzzlingType::Solo
            && $this->secondsToSolve !== null;
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
        $puzzlingTypeBefore = $this->puzzlingType;
        $finishedAtBefore = $this->finishedAt;
        $hadSecondsBefore = $this->secondsToSolve !== null;

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

        // The prediction stays when only the seconds changed - the outcome follows them. It is
        // forgotten when what it predicted changed: another day in the history, solo <-> group,
        // a time appearing or disappearing. Days, not seconds: the edit form is date-only, so
        // re-saving a time that came with a time of day (API) must not count as a move
        if (
            $puzzlingTypeBefore !== $this->puzzlingType
            || $finishedAtBefore?->format('Y-m-d') !== $finishedAt?->format('Y-m-d')
            || $hadSecondsBefore !== ($seconds !== null)
        ) {
            $this->forgetPrediction();
        }

        $this->recordThat(
            new PuzzleSolvingTimeModified($this->id, $this->puzzle->id),
        );
    }

    /**
     * The tracker picked the wrong puzzle and fixes it in the edit form (docs/features/duplicate-results.md,
     * Layer 4). The puzzle left behind is told like a deletion; the edit's modify() tells the new one. The stored
     * prediction was a prediction for another puzzle, so it goes - the round is re-derived by the caller.
     */
    public function moveToPuzzle(Puzzle $newPuzzle): void
    {
        if ($this->puzzle->id->equals($newPuzzle->id)) {
            return;
        }

        $this->recordThat(
            new PuzzleSolvingTimeMovedToOtherPuzzle($this->id, $this->puzzle->id, $this->player->id, $this->puzzle->piecesCount),
        );

        $this->puzzle = $newPuzzle;
        $this->forgetPrediction();
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

        // Someone else's time now - the daily backfill evaluates it again
        $this->forgetPrediction();
    }

    public function replaceTeam(null|PuzzlersGroup $newTeam): void
    {
        $puzzlingTypeBefore = $this->puzzlingType;

        $this->team = $newTeam;
        $this->puzzlersCount = $this->calculatePuzzlersCount();
        $this->puzzlingType = PuzzlingType::fromPuzzlersCount($this->puzzlersCount);

        if ($puzzlingTypeBefore !== $this->puzzlingType) {
            $this->forgetPrediction();
        }
    }

    /**
     * The same people written down properly - a guest typed as "Anna, Ben, Clara" is three guests
     * (SplitCombinedGuests). Nobody edited the result, so nothing is recorded.
     */
    public function correctGroup(PuzzlersGroup $group, PuzzlingTeam $puzzlingTeam): void
    {
        $this->replaceTeam($group);
        $this->puzzlingTeam = $puzzlingTeam;
    }

    private function forgetPrediction(): void
    {
        $this->predictable = null;
        $this->predictionMethod = null;
        $this->predictedSeconds = null;
        $this->predictedRangeLowSeconds = null;
        $this->predictedRangeHighSeconds = null;
        $this->predictedAttemptNumber = null;
        $this->predictionLastTimeSeconds = null;
        $this->predictionSource = null;
        $this->predictionComputedAt = null;
        $this->predictionModelVersion = null;
    }

    private function calculatePuzzlersCount(): int
    {
        if ($this->team === null) {
            return 1;
        }

        return count($this->team->puzzlers);
    }
}
