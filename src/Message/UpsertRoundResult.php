<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Idempotent upsert of an organizer-entered round result.
 *
 * $resultId is client-generated (UUID v7) so offline replays are safe.
 * Entrant is either an existing participant/team id, or a name that gets
 * resolved (and created when needed) based on the round category.
 */
readonly final class UpsertRoundResult
{
    public function __construct(
        public string $resultId,
        public string $roundId,
        public null|string $participantId,
        public null|string $teamId,
        public null|string $entrantName,
        public null|int $secondsToSolve,
        public null|int $missingPieces,
    ) {
    }
}
