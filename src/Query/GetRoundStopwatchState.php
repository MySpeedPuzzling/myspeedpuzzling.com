<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Results\RoundStopwatchState;

/**
 * A round's stopwatch as stored now (RoundStopwatchState) - one statement, read from the database rather than from the
 * entity manager: after a write it is what was committed, after a newer write by another device it is that one (its
 * `asOfMs` is taken right before the row is read, so the newest read wins on every client).
 */
readonly final class GetRoundStopwatchState
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound
     */
    public function forRound(string $roundId): RoundStopwatchState
    {
        if (Uuid::isValid($roundId) === false) {
            throw new CompetitionRoundNotFound();
        }

        $asOf = $this->clock->now();

        $row = $this->database->fetchAssociative(
            'SELECT id, stopwatch_status, stopwatch_started_at, stopwatch_stopped_at, minutes_limit FROM competition_round WHERE id = :roundId',
            ['roundId' => $roundId],
        );

        if ($row === false) {
            throw new CompetitionRoundNotFound();
        }

        /** @var array{id: string, stopwatch_status: null|string, stopwatch_started_at: null|string, stopwatch_stopped_at: null|string, minutes_limit: int} $row */

        return new RoundStopwatchState(
            roundId: strtolower($row['id']),
            storedStatus: $row['stopwatch_status'],
            startedAt: $row['stopwatch_started_at'] !== null ? new DateTimeImmutable($row['stopwatch_started_at']) : null,
            stoppedAt: $row['stopwatch_stopped_at'] !== null ? new DateTimeImmutable($row['stopwatch_stopped_at']) : null,
            minutesLimit: $row['minutes_limit'],
            asOf: $asOf,
        );
    }
}
