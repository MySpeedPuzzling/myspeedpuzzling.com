<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

/**
 * One result the simulation will log through AddPuzzleSolvingTime, like a player would (SandboxResultPlanner).
 */
final readonly class SandboxPlannedResult
{
    public function __construct(
        public UuidInterface $timeId,
        public SandboxPlayer $player,
        public SandboxPuzzle $puzzle,
        public int $seconds,
        public DateTimeImmutable $finishedAt,
        // When the player saves it - the moment the seed sets the clock to, at or after $finishedAt
        public DateTimeImmutable $trackedAt,
        public bool $firstAttempt,
        public bool $unboxed,
        /** @var list<string> co-puzzlers as the add-time form sends them: "#CODE" */
        public array $groupPlayers = [],
    ) {
    }

    /**
     * The time as the add-time form sends it: "H:MM:SS"
     */
    public function timeInput(): string
    {
        return sprintf('%d:%02d:%02d', intdiv($this->seconds, 3600), intdiv($this->seconds % 3600, 60), $this->seconds % 60);
    }
}
