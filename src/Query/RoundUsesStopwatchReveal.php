<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

/**
 * Whether a round has a secret puzzle revealed at its stopwatch start (RoundPuzzleReveal::Stopwatch) - the cheap check
 * before a stopwatch action takes the reveal's locks (RoundStopwatchReveal): a round without one keeps today's
 * statements and locks.
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
            'SELECT 1 FROM competition_round_puzzle WHERE round_id = :roundId AND reveal_mode = :mode AND hide_until_round_starts LIMIT 1',
            ['roundId' => $roundId, 'mode' => RoundPuzzleReveal::Stopwatch->value],
        );

        return $exists !== false;
    }
}
