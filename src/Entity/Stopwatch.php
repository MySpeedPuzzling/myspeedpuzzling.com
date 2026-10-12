<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Doctrine\LapsArrayDoctrineType;
use SpeedPuzzling\Web\Exceptions\StopwatchAlreadyFinished;
use SpeedPuzzling\Web\Exceptions\StopwatchAlreadyStarted;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeFinished;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBePaused;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeResumed;
use SpeedPuzzling\Web\Value\Lap;
use SpeedPuzzling\Web\Value\StopwatchStatus;

#[Entity]
class Stopwatch
{
    public const int SHOWN_TIME_TOLERANCE_MILLISECONDS = 10_000;

    /**
     * @var array<Lap>
     */
    #[Column(type: LapsArrayDoctrineType::NAME)]
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    public array $laps = [];

    #[Column(enumType: StopwatchStatus::class)]
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    public StopwatchStatus $status = StopwatchStatus::NotStarted;

    #[Column(nullable: true)]
    public null|string $name = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        public Player $player,
        #[ManyToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        public null|Puzzle $puzzle,
    ) {
    }

    public function start(DateTimeImmutable $now): void
    {

        if ($this->status !== StopwatchStatus::NotStarted) {
            throw new StopwatchAlreadyStarted();
        }

        $this->laps[] = Lap::start($now);
        $this->status = StopwatchStatus::Running;
    }

    /**
     * @throws StopwatchCouldNotBeResumed
     */
    public function resume(DateTimeImmutable $now): void
    {
        if ($this->status !== StopwatchStatus::Paused) {
            throw new StopwatchCouldNotBeResumed();
        }

        // A lap never starts before the previous one ended (a resume tapped "ago" by the phone cannot overlap it)
        $previousEnd = $this->getLastLap()?->end;
        if ($previousEnd !== null && $now < $previousEnd) {
            $now = $previousEnd;
        }

        $this->laps[] = Lap::start($now);
        $this->status = StopwatchStatus::Running;
    }

    /**
     * The phone counts the time the player sees, from tap to tap; the server only learns about a tap when its request
     * arrives (later, or much later from the offline queue). So the shown total decides where the lap ends - the saved
     * time is then exactly what the stopwatch showed. Without it (an older page) the lap ends now.
     */
    public function pause(DateTimeImmutable $now, null|int $shownTotalMilliseconds = null): void
    {
        $lap = $this->getLastLap();
        $lastKey = array_key_last($this->laps);

        if ($this->status !== StopwatchStatus::Running || $lap === null || $lastKey === null) {
            throw new StopwatchCouldNotBePaused();
        }

        $end = $now;

        if ($shownTotalMilliseconds !== null) {
            $lapMilliseconds = max(0, $shownTotalMilliseconds - $this->finishedLapsMilliseconds());
            $end = $lap->start->modify(sprintf('+%d milliseconds', $lapMilliseconds));

            // The phone may run a little ahead of the server (request delays), never more
            $latestEnd = $now->modify(sprintf('+%d milliseconds', self::SHOWN_TIME_TOLERANCE_MILLISECONDS));
            if ($end > $latestEnd) {
                $end = $latestEnd;
            }
        }

        if ($end < $lap->start) {
            $end = $lap->start;
        }

        $this->laps[$lastKey] = $lap->finish($end);
        $this->status = StopwatchStatus::Paused;
    }

    /**
     * @throws StopwatchCouldNotBeFinished
     * @throws StopwatchAlreadyFinished
     */
    public function finish(Puzzle $puzzle): void
    {
        if ($this->status === StopwatchStatus::Finished) {
            throw new StopwatchAlreadyFinished();
        }

        if ($this->status !== StopwatchStatus::Paused) {
            throw new StopwatchCouldNotBeFinished();
        }

        $this->status = StopwatchStatus::Finished;
        $this->puzzle = $puzzle;
    }

    public function rename(null|string $name): void
    {
        $this->name = $name;
    }

    private function finishedLapsMilliseconds(): int
    {
        $milliseconds = 0;

        foreach ($this->laps as $lap) {
            $milliseconds += $lap->durationMilliseconds() ?? 0;
        }

        return $milliseconds;
    }

    private function getLastLap(): null|Lap
    {
        if (count($this->laps) === 0) {
            return null;
        }

        return $this->laps[array_key_last($this->laps)];
    }
}
