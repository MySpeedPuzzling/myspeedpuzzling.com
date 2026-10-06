<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;

/**
 * Whether a competition keeps the puzzle secret right now (PuzzleSecrecy) - approvals, edits and merges wait for the
 * reveal.
 */
readonly final class IsPuzzleKeptSecret
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function byId(string $puzzleId): bool
    {
        if (Uuid::isValid($puzzleId) === false) {
            return false;
        }

        $secret = PuzzleSecrecy::sqlSecret('p');

        return $this->database->fetchOne(
            "SELECT 1 FROM puzzle p WHERE p.id = :puzzleId AND {$secret}",
            [
                'puzzleId' => $puzzleId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        ) !== false;
    }
}
