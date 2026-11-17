<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionDisplaysLock;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DisplayPreset;

/**
 * A new display of an event, starting from one of the presets (D-displays-overlays.md §3.3, §6) - the preset must fit
 * the kind (Blank fits both).
 * Adds and duplicates of one event take turns, so the cap is counted exactly.
 */
readonly final class AddCompetitionDisplay implements SerializedByLock
{
    public function __construct(
        public UuidInterface $displayId,
        public string $competitionId,
        public DisplayKind $kind,
        public string $name,
        public string $locale,
        public null|string $actorPlayerId,
        public DisplayPreset $presetKey = DisplayPreset::Blank,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionDisplaysLock::key($this->competitionId);
    }
}
