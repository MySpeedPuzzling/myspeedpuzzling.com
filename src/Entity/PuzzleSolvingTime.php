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
