<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Exceptions\StopwatchAlreadyFinished;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeResumed;
use SpeedPuzzling\Web\Value\Lap;
use SpeedPuzzling\Web\Value\StopwatchStatus;

#[Entity]
class Stopwatch
{
    /**
     * @var list<Lap>
     */
    #[Column]
    private array $laps;

    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        #[ManyToOne]
        public Player $player,

        public DateTimeImmutable $now,

        #[Column(enumType: StopwatchStatus::class)]
        public StopwatchStatus $status = StopwatchStatus::Running,

    ) {
        $this->laps[] = Lap::start($this->now);
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
        if ($this->status !== StopwatchStatus::Running) {
            throw new StopwatchCouldNotBeResumed();
        }

        $this->laps[array_key_last($this->laps)] = $this->getLastLap()->finish($now);
        $this->status = StopwatchStatus::Paused;
    }

    public function finish(DateTimeImmutable $now): void
    {
        if ($this->status === StopwatchStatus::Finished) {
            throw new StopwatchAlreadyFinished();
        }

        if ($this->status === StopwatchStatus::Running) {
            $this->laps[array_key_last($this->laps)] = $this->getLastLap()->finish($now);
        }

        $this->status = StopwatchStatus::Finished;
    }

    private function getLastLap(): Lap
    {
        return $this->laps[array_key_last($this->laps)];
    }
}
