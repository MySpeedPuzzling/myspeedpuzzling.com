<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One player's solves of one puzzle in one discipline (solo, duo or team):
 * how many, the best and the most recent time, when the first and the last
 * one happened. "When" is COALESCE(finished_at, tracked_at), as everywhere else.
 */
readonly final class PlayerPuzzleSolvesGroup
{
    public function __construct(
        public int $count,
        public null|int $bestTimeSeconds,
        public null|int $lastTimeSeconds,
        public null|DateTimeImmutable $firstSolvedAt,
        public null|DateTimeImmutable $lastSolvedAt,
    ) {
    }

    public static function empty(): self
    {
        return new self(
            count: 0,
            bestTimeSeconds: null,
            lastTimeSeconds: null,
            firstSolvedAt: null,
            lastSolvedAt: null,
        );
    }

    /**
     * @param array{
     *     solve_count: int|string,
     *     best_seconds: null|int|string,
     *     last_seconds: null|int|string,
     *     first_solved_at: null|string,
     *     last_solved_at: null|string,
     *     ...
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            count: (int) $row['solve_count'],
            bestTimeSeconds: $row['best_seconds'] !== null ? (int) $row['best_seconds'] : null,
            lastTimeSeconds: $row['last_seconds'] !== null ? (int) $row['last_seconds'] : null,
            firstSolvedAt: $row['first_solved_at'] !== null ? new DateTimeImmutable($row['first_solved_at']) : null,
            lastSolvedAt: $row['last_solved_at'] !== null ? new DateTimeImmutable($row['last_solved_at']) : null,
        );
    }
}
