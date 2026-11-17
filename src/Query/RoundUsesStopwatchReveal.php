<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Whether a stopwatch action on a round takes the reveal's locks (RoundStopwatchReveal::lockIfNeeded()): the round has a
 * secret puzzle - any reveal mode, since this runs before the lock and a secret row may turn to RoundPuzzleReveal::
 * Stopwatch meanwhile. A round without one keeps today's statements and locks.
 */
readonly final class RoundUsesStopwatchReveal
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function __invoke(string $roundId): bool
    {
        if (Uuid::isValid($roundId) === false) {
            return false;
        }

        $exists = $this->database->fetchOne(
            'SELECT 1 FROM competition_round_puzzle WHERE round_id = :roundId AND hide_until_round_starts LIMIT 1',
            ['roundId' => $roundId],
        );

        return $exists !== false;
    }
}
