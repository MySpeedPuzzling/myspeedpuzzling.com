<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * A result about to be saved, as the first-try rules (when it carries the tag) and the "same time already saved"
 * check look at it (docs/features/first-try-integrity.md, docs/features/duplicate-results.md).
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
        // The time entered - null = no time (relax) or none complete yet, nothing to compare
        public null|int $secondsToSolve = null,
        // Edit only: the time and the day before the edit
        public null|int $previousSecondsToSolve = null,
        public null|DateTimeImmutable $previousSolvedAt = null,
        // Add only: the id the form saves the result under. A result with it = the form sent again after it was
        // saved - nothing to check, the handler answers the resend (docs/features/duplicate-results.md, Layer 1)
        public null|string $newTimeId = null,
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
