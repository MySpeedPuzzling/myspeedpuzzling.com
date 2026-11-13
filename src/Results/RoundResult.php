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
        public string $timeId,
        public string $puzzleId,
        public array $players,
        public RoundResultStatus $status,
        public null|int $seconds,
        public null|int $piecesPlaced,
        public null|int $finishedLaterSeconds,
        public null|DateTimeImmutable $finishedAt,
        // Name of the pair/team, when it has one - an imported event result nobody has claimed yet is shown by it
        public null|string $teamName = null,
        // From the organizer's results sheet - the event's official results (docs/features/event-results-import/)
        public bool $fromOrganizer = false,
        // Its official place among the organizer's results (finished ones only); null for every other result
        public null|int $rank = null,
        // Seconds behind the fastest / the result above among the organizer's ranked results (_time_gaps.html.twig)
        public null|int $gapToLeader = null,
        public null|int $gapToFaster = null,
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
