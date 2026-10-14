<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Events\CompetitionRoundsChanged;
use SpeedPuzzling\Web\Events\OfficialRoundResultsPublished;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundStopwatchRefusal;
use SpeedPuzzling\Web\Value\RoundStopwatchStatus;
use SpeedPuzzling\Web\Value\RoundTimezone;

#[Entity]
#[UniqueConstraint(name: 'competition_round_slug_unique', columns: ['competition_id', 'slug'])]
class CompetitionRound implements EntityWithEvents
{
    use HasEvents;

    public const int TEAM_SIZE_MIN = 2;
    public const int TEAM_SIZE_MAX = 20;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Competition $competition,
        #[Column]
        public string $name,
        #[Column]
        public int $minutesLimit,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $startsAt,
        /**
         * @var Collection<int, CompetitionRoundPuzzle>
         */
        #[OneToMany(targetEntity: CompetitionRoundPuzzle::class, mappedBy: 'round')]
        public Collection $roundPuzzles = new ArrayCollection(),
        #[Column(nullable: true)]
        public null|string $badgeBackgroundColor = null,
        #[Column(nullable: true)]
        public null|string $badgeTextColor = null,
        #[Column(enumType: RoundCategory::class, options: ['default' => 'solo'])]
        public RoundCategory $category = RoundCategory::Solo,
        // The round stopwatch - changed only through its methods below; read its status through stopwatchStatusAt()
        // (RoundStopwatchStatus): a scheduled start counts as running once its moment has passed. Whole seconds.
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $stopwatchStartedAt = null,
        // null | scheduled | running | stopped (RoundStopwatchStatus)
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $stopwatchStatus = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $stopwatchStoppedAt = null,
        // Unique per competition, generated once on create and kept on rename, so shared result links survive
        #[Column(nullable: true)]
        public null|string $slug = null,
        // The organiser's own results page for this round, when they publish results per round
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $resultsLink = null,
        // The zone the organiser typed the start in, so it is edited and shown in that zone - see RoundTimezone
        #[Column(length: 64, nullable: true)]
        public null|string $timezone = null,
        // Official results (docs/features/competitions-management/official-results.md): public on the round page
        // while set - publishResults() / unpublishResults()
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $resultsPublishedAt = null,
        // The first publish - kept through unpublish/publish (the desk says whether players were told before)
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $resultsFirstPublishedAt = null,
        // The organiser said this round does not use table numbers - the seating step and its reminders are hidden
        #[Column(options: ['default' => false])]
        public bool $tableNumbersOff = false,
        // Minutes after the start when the round's secret puzzles with an automatic reveal come out (RoundPuzzleReveal),
        // 0..RoundPuzzleReveal::MAX_DELAY_MINUTES - changed only through changeRevealDelay(). The column default keeps a
        // round inserted by an older release (blue-green deploy) at the old fixed 10 minutes.
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(options: ['default' => RoundPuzzleReveal::DEFAULT_DELAY_MINUTES])]
        public int $revealDelayMinutes = RoundPuzzleReveal::DEFAULT_DELAY_MINUTES,
        // How many people a team of this round is expected to have (team rounds only - a pair always has 2, see
        // expectedTeamSize()). Only a hint for the organiser's tools: a team of another size is a warning, never refused
        // (docs/features/competitions-management/participants-spreadsheet.md D5). Changed only through changeTeamSize().
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, nullable: true)]
        public null|int $teamSize = null,
    ) {
        RoundPuzzleReveal::assertValidDelay($revealDelayMinutes);
        self::assertValidTeamSize($teamSize);

        // A new round changes the round days and categories of its competition - series picks of an edition may move
        // (docs/features/events-page/high-frequency-series.md)
        $this->recordThat(new CompetitionRoundsChanged($this->competition->id));
    }

    /**
     * Call right before removing it (DeleteCompetitionRoundHandler) - the times of its competition (and the series picks
     * of an edition's series) are reconciled after the flush.
     */
    public function recordRemoval(): void
    {
        $this->recordThat(new CompetitionRoundsChanged($this->competition->id));
    }

    /**
     * The number of people a pair/team of this round is expected to have: 2 for a pair round, the organiser's
     * setting for a team round (null = not set), null for a solo round.
     */
    public function expectedTeamSize(): null|int
    {
        return match ($this->category) {
            RoundCategory::Solo => null,
            RoundCategory::Duo => 2,
            RoundCategory::Team => $this->teamSize,
        };
    }

    /**
     * Callers validate first (TEAM_SIZE_MIN..TEAM_SIZE_MAX or null) - this only guards the invariant.
     */
    public function changeTeamSize(null|int $teamSize): void
    {
        self::assertValidTeamSize($teamSize);
        $this->teamSize = $teamSize;
    }

    private static function assertValidTeamSize(null|int $teamSize): void
    {
        if ($teamSize !== null && ($teamSize < self::TEAM_SIZE_MIN || $teamSize > self::TEAM_SIZE_MAX)) {
            throw new \InvalidArgumentException(sprintf('A team size is %d to %d people.', self::TEAM_SIZE_MIN, self::TEAM_SIZE_MAX));
        }
    }

    /**
     * When a secret puzzle of this round with an automatic reveal is revealed - see RoundPuzzleReveal.
     */
    public function automaticRevealAt(): DateTimeImmutable
    {
        return RoundPuzzleReveal::automaticRevealAt($this->startsAt, $this->revealDelayMinutes);
    }

    /**
     * The automatic reveal of the round's secret puzzles moves with it. Only EditCompetitionRoundHandler calls it (after
     * its confirmation check and its pins of reveals that already happened) - re-sync the puzzles afterwards
     * (SecretPuzzleHides).
     */
    public function changeRevealDelay(int $minutes): void
    {
        RoundPuzzleReveal::assertValidDelay($minutes);
        $this->revealDelayMinutes = $minutes;
    }

    public function displayTimezone(): string
    {
        return RoundTimezone::resolve($this->timezone, $this->competition->locationCountryCode, $this->competition->series?->locationCountryCode);
    }

    /**
     * displayTimezone() names no place the event is known to be in (RoundTimezone::isAssumed()) - shown without a place
     * ("Central European Time"), ZonedDateTimeFormatter::timezoneName()
     */
    public function isTimezoneAssumed(): bool
    {
        return RoundTimezone::isAssumed($this->timezone, $this->competition->locationCountryCode, $this->competition->series?->locationCountryCode);
    }

    /**
     * No zone saved and no country to take one from - displayTimezone() is only the fallback (RoundTimezone), the round
     * form asks the organiser to check the start and pick the zone
     */
    public function isTimezoneNeverSaved(): bool
    {
        return ($this->timezone === null || RoundTimezone::isValid($this->timezone) === false)
            && RoundTimezone::isAssumed($this->timezone, $this->competition->locationCountryCode, $this->competition->series?->locationCountryCode);
    }

    /**
     * A round saved before rounds kept their zone keeps the zone it has always been read in (RoundTimezone::resolve(),
     * the zone the round form pre-selected when it was typed) - nothing it shows changes, the zone is only saved now.
     * An assumed zone stays named without a place (RoundTimezone::isAssumed() treats a saved fallback the same way).
     */
    public function saveDisplayedTimezone(): void
    {
        if ($this->timezone !== null && RoundTimezone::isValid($this->timezone)) {
            return;
        }

        $this->timezone = $this->displayTimezone();
    }

    public function assignSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    /**
     * Moves the round to another one-time event or edition (MoveRoundToCompetition - the caller checks every refusal
     * and that the slug is free in the target). The round keeps the wall-clock zone it is shown in now (P22), and both
     * competitions' round results are reconciled after the flush (CompetitionRoundsChanged).
     */
    public function moveToCompetition(Competition $target, string $slug): void
    {
        $this->saveDisplayedTimezone();
        $this->recordThat(new CompetitionRoundsChanged($this->competition->id));

        $this->competition = $target;
        $this->slug = $slug;

        $this->recordThat(new CompetitionRoundsChanged($target->id));
    }

    /**
     * Shows the official results on the round page. Every publish records OfficialRoundResultsPublished: the players with
     * a finished result who were not told yet get a notification (each player once per round, ever - the notification
     * handler's marker decides, so a publish → unpublish → publish before the worker ran still tells everybody).
     * Publishing published results changes nothing.
     */
    public function publishResults(DateTimeImmutable $publishedAt): void
    {
        if ($this->resultsPublishedAt !== null) {
            return;
        }

        $this->resultsPublishedAt = $publishedAt;
        $this->resultsFirstPublishedAt ??= $publishedAt;
        $this->recordThat(new OfficialRoundResultsPublished($this->id));
    }

    public function unpublishResults(): void
    {
        $this->resultsPublishedAt = null;
    }

    public function areResultsPublished(): bool
    {
        return $this->resultsPublishedAt !== null;
    }

    public function changeTableNumbersUsage(bool $off): void
    {
        $this->tableNumbersOff = $off;
    }

    /**
     * The piece count of the round's puzzle when the round has exactly one - the most pieces an unfinished official
     * result can have placed is one less. Null for a round with no puzzle or several.
     */
    public function singlePuzzlePiecesCount(): null|int
    {
        if ($this->roundPuzzles->count() !== 1) {
            return null;
        }

        $roundPuzzle = $this->roundPuzzles->first();

        return $roundPuzzle === false ? null : $roundPuzzle->puzzle->piecesCount;
    }

    public function edit(
        string $name,
        int $minutesLimit,
        DateTimeImmutable $startsAt,
        string $timezone,
        null|string $badgeBackgroundColor,
        null|string $badgeTextColor,
        RoundCategory $category = RoundCategory::Solo,
        null|string $resultsLink = null,
    ): void {
        // The category decides which times belong to the round; its start and zone its local day, which the date
        // matching of series picks reads (docs/features/events-page/high-frequency-series.md)
        if (
            $category !== $this->category
            || $startsAt->getTimestamp() !== $this->startsAt->getTimestamp()
            || $timezone !== $this->timezone
        ) {
            $this->recordThat(new CompetitionRoundsChanged($this->competition->id));
        }

        $this->name = $name;
        $this->minutesLimit = $minutesLimit;
        $this->startsAt = $startsAt;
        $this->timezone = $timezone;
        $this->badgeBackgroundColor = $badgeBackgroundColor;
        $this->badgeTextColor = $badgeTextColor;
        $this->category = $category;
        $this->resultsLink = $resultsLink;
    }

    /**
     * What the stopwatch is doing at $now - a scheduled start counts as running once its moment has passed
     * (RoundStopwatchStatus, the one rule every reader follows).
     */
    public function stopwatchStatusAt(DateTimeImmutable $now): null|string
    {
        return RoundStopwatchStatus::at($this->stopwatchStatus, $this->stopwatchStartedAt, $now);
    }

    /**
     * "Start" - now, or resume a stopped stopwatch (in-person rounds: stopped = paused, startedAt moves forward by the
     * pause). A running stopwatch - a scheduled start whose moment passed included - is left as it is: a second "Start"
     * from another device or a stale page never restarts the clock. While a start is scheduled it is refused: starting
     * now would move the moment every device counts down to - the countdown is cancelled first.
     *
     * A round with online timing (`$onlineTiming` - the caller knows: OnlineRound) that ended is never resumed: its end is
     * the end every participant's taps were counted against, and they kept solving on the other side of the world
     * (docs/features/online-competitions/README.md §5.1) - refused, a Reset runs it again (a fresh run).
     *
     * @throws RoundStopwatchRefused
     */
    public function startStopwatch(DateTimeImmutable $startedAt, bool $onlineTiming = false): void
    {
        $startedAt = self::wholeSecond($startedAt);
        $status = $this->stopwatchStatusAt($startedAt);

        if ($status === RoundStopwatchStatus::RUNNING) {
            return;
        }

        if ($status === RoundStopwatchStatus::SCHEDULED) {
            throw new RoundStopwatchRefused(RoundStopwatchRefusal::StartScheduled);
        }

        if ($onlineTiming && $status === RoundStopwatchStatus::STOPPED) {
            throw new RoundStopwatchRefused(RoundStopwatchRefusal::EndedUseReset);
        }

        if (
            $this->stopwatchStatus === RoundStopwatchStatus::STOPPED
            && $this->stopwatchStartedAt !== null
            && $this->stopwatchStoppedAt !== null
        ) {
            // Resume: shift startedAt forward by the pause duration so that
            // elapsed = now - startedAt continues from the paused value.
            $pauseSeconds = $startedAt->getTimestamp() - $this->stopwatchStoppedAt->getTimestamp();
            $this->stopwatchStartedAt = $this->stopwatchStartedAt->modify(
                sprintf('+%d seconds', $pauseSeconds),
            );
        } else {
            $this->stopwatchStartedAt = $startedAt;
        }
        $this->stopwatchStatus = RoundStopwatchStatus::RUNNING;
        $this->stopwatchStoppedAt = null;
    }

    /**
     * A start at a moment ahead (online rounds: "Start in 10 s", "Start at 19:00:00") - a start in the future IS the
     * countdown: every device shows `startsAt - now` and runs from that moment, nothing is written then. On a whole
     * second, like the TIMESTAMP(0) column keeps it (callers align: ScheduleRoundStart). Only for a clock that never
     * ran, or to move a countdown - a running or stopped one is reset first (an ended round with online timing is told
     * so - EndedUseReset, as its Start is).
     *
     * @throws RoundStopwatchRefused
     */
    public function scheduleStopwatchStart(DateTimeImmutable $startsAt, DateTimeImmutable $now, bool $onlineTiming = false): void
    {
        if ((int) $startsAt->format('u') !== 0) {
            throw new \InvalidArgumentException('A scheduled start is on a whole second.');
        }

        // Stored as its UTC moment - Doctrine writes an object's own wall time, whatever zone it carries
        $startsAt = $startsAt->setTimezone(new \DateTimeZone('UTC'));

        if ($startsAt < $now) {
            throw new RoundStopwatchRefused(RoundStopwatchRefusal::StartInThePast);
        }

        $status = $this->stopwatchStatusAt($now);

        if ($onlineTiming && $status === RoundStopwatchStatus::STOPPED) {
            throw new RoundStopwatchRefused(RoundStopwatchRefusal::EndedUseReset);
        }

        if ($status !== null && $status !== RoundStopwatchStatus::SCHEDULED) {
            throw new RoundStopwatchRefused(RoundStopwatchRefusal::AlreadyStarted);
        }

        $this->stopwatchStartedAt = $startsAt;
        $this->stopwatchStatus = RoundStopwatchStatus::SCHEDULED;
        $this->stopwatchStoppedAt = null;
    }

    /**
     * Calls a countdown off - only before its moment: once the start has passed the round runs (stop or reset it then).
     * Nothing scheduled = nothing to do (a second tap, a stale page).
     *
     * @throws RoundStopwatchRefused
     */
    public function cancelScheduledStart(DateTimeImmutable $now): void
    {
        if ($this->stopwatchStatus === null) {
            return;
        }

        if ($this->stopwatchStatusAt($now) !== RoundStopwatchStatus::SCHEDULED) {
            throw new RoundStopwatchRefused(RoundStopwatchRefusal::TooLateToCancel);
        }

        $this->resetStopwatch();
    }

    /**
     * "Stop" - ends the round for online rounds, pauses it in person (Start resumes). A stopwatch that is not running
     * stays as it is: a second "Stop" never moves the end, a clock that never ran gets no end. A countdown stopped before
     * its moment never ran - it is called off.
     */
    public function stopStopwatch(DateTimeImmutable $stoppedAt): void
    {
        $this->stop($stoppedAt, self::wholeSecond($stoppedAt));
    }

    /**
     * "End now" of an online round (StopRoundStopwatchHandler) - stopStopwatch(), but the end is stored rounded UP to the
     * next whole second: the column keeps whole seconds, and an end stored before the click (39.926 → 39) would turn a
     * Finished tap in that last fraction of a second into a tap after the end. The end is never before the click and at
     * most a second after it. In person a Stop is a pause and keeps rounding down - rounded up, every pause would add up
     * to a second to the clock.
     */
    public function endStopwatch(DateTimeImmutable $endedAt): void
    {
        $this->stop($endedAt, self::wholeSecondUp($endedAt));
    }

    private function stop(DateTimeImmutable $moment, DateTimeImmutable $storedEnd): void
    {
        $status = $this->stopwatchStatusAt($moment);

        if ($status === RoundStopwatchStatus::SCHEDULED) {
            $this->resetStopwatch();

            return;
        }

        if ($status !== RoundStopwatchStatus::RUNNING) {
            return;
        }

        $this->stopwatchStatus = RoundStopwatchStatus::STOPPED;
        $this->stopwatchStoppedAt = $storedEnd;
    }

    public function resetStopwatch(): void
    {
        $this->stopwatchStartedAt = null;
        $this->stopwatchStatus = null;
        $this->stopwatchStoppedAt = null;
    }

    /**
     * The stopwatch columns are TIMESTAMP(0): the moment as stored, so the entity reads the same before and after a
     * flush (the live updates and the state endpoint read it back from the database).
     */
    private static function wholeSecond(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTimestamp($moment->getTimestamp());
    }

    /**
     * The moment on the next whole second - itself when it is one already
     */
    private static function wholeSecondUp(DateTimeImmutable $moment): DateTimeImmutable
    {
        $seconds = $moment->getTimestamp();

        return $moment->setTimestamp((int) $moment->format('u') > 0 ? $seconds + 1 : $seconds);
    }
}
