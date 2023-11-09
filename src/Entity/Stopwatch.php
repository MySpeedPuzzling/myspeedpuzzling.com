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
    /**
     * @var array<Lap>
     */
    #[Column(type: LapsArrayDoctrineType::NAME)]
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    public array $laps = [];

    #[Column(enumType: StopwatchStatus::class)]
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    public StopwatchStatus $status = StopwatchStatus::NotStarted;

    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        public Player $player,

        #[ManyToOne]
        #[JoinColumn(nullable: true)]
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

        $this->laps[] = Lap::start($now);
        $this->status = StopwatchStatus::Running;
    }

    public function pause(DateTimeImmutable $now): void
    {
        $lap = $this->getLastLap();

        if ($this->status !== StopwatchStatus::Running || $lap === null) {
            throw new StopwatchCouldNotBePaused();
        }

        $this->laps[array_key_last($this->laps)] = $lap->finish($now);
        $this->status = StopwatchStatus::Paused;
    }

    /**
     * @throws StopwatchCouldNotBeFinished
     * @throws StopwatchAlreadyFinished
     */
    public function finish(DateTimeImmutable $now): void
    {
        if ($this->status === StopwatchStatus::Finished) {
            throw new StopwatchAlreadyFinished();
        }

        $lap = $this->getLastLap();

        if ($lap === null) {
            throw new StopwatchCouldNotBeFinished();
        }

        if ($this->status === StopwatchStatus::Running) {
            $this->laps[array_key_last($this->laps)] = $lap->finish($now);
        }

        $this->status = StopwatchStatus::Finished;
    }

    public function changePuzzle(Puzzle $puzzle): void
    {
        $this->puzzle = $puzzle;
    }

    private function getLastLap(): null|Lap
    {
        if (count($this->laps) === 0) {
            return null;
        }

        return $this->laps[array_key_last($this->laps)];
    }
}
