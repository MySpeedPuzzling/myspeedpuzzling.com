<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundResultStatus;

readonly final class RoundResult
{
    /**
     * @param non-empty-list<RoundResultPlayer> $players
     */
    public function __construct(
        public int $rank,
        public string $timeId,
        public string $puzzleId,
        public array $players,
        public RoundResultStatus $status,
        public null|int $seconds,
        public null|int $piecesPlaced,
        public null|int $finishedLaterSeconds,
        public null|DateTimeImmutable $finishedAt,
    ) {
    }

    public function containsPlayer(null|string $playerId): bool
    {
        if ($playerId === null) {
            return false;
        }

        foreach ($this->players as $player) {
            if ($player->playerId === $playerId) {
                return true;
            }
        }

        return false;
    }
}
