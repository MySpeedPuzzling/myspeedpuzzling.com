<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * A result about to be saved as a first try, as the rules look at it.
 */
readonly final class FirstTryEntry
{
    /**
     * @param list<string> $memberPlayerIds every registered person of the result, whoever tracked it included
     * @param null|list<string> $previousMemberPlayerIds edit only: the registered people before the edit
     */
    public function __construct(
        public string $actorPlayerId,
        public string $puzzleId,
        public array $memberPlayerIds,
        // Null = no date given, the result is from today
        public null|DateTimeImmutable $solvedAt,
        public null|string $editedTimeId = null,
        public bool $previouslyFirstAttempt = false,
        public null|array $previousMemberPlayerIds = null,
    ) {
    }

    /**
     * @return list<string> whoever tracked the result plus every registered puzzler of its group
     */
    public static function memberIdsOf(string $trackerPlayerId, null|PuzzlersGroup $group): array
    {
        $ids = [strtolower($trackerPlayerId)];

        foreach ($group->puzzlers ?? [] as $puzzler) {
            if ($puzzler->playerId !== null) {
                $ids[] = strtolower($puzzler->playerId);
            }
        }

        return array_values(array_unique($ids));
    }
}
