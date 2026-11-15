<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\MyData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzle;
use SpeedPuzzling\Web\Value\SolvingTime;
use SpeedPuzzling\Web\Value\SolvingTimeSource;

/**
 * One result of the signed-in player in "My data" (docs/features/my-data.md) - a result they tracked or took part in.
 */
readonly final class MyDataResultRow
{
    public function __construct(
        public string $timeId,
        public ExportablePuzzle $puzzle,
        // The stored path of the puzzle's picture (null while it is hidden) - for the image helpers
        public null|string $imagePath,
        public null|int $seconds,
        public null|DateTimeImmutable $finishedAt,
        public null|string $datePrecision,
        public DateTimeImmutable $trackedAt,
        public bool $firstAttempt,
        public bool $hasPhoto,
        public null|string $comment,
        public string $solvingType,
        public int $puzzlersCount,
        // The other people of a pair/team result, "Anna, Ben"
        public null|string $withWhom,
        public null|string $teamName,
        public null|SolvingTimeSource $source,
        public bool $suspicious,
    ) {
    }

    public function ppm(): null|float
    {
        if ($this->seconds === null || $this->seconds === 0) {
            return null;
        }

        return new SolvingTime($this->seconds)->calculatePpm($this->puzzle->piecesCount, $this->puzzlersCount);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row, ExportablePuzzle $puzzle): self
    {
        /**
         * @var array{
         *     time_id: string,
         *     puzzle_image: null|string,
         *     seconds_to_solve: null|int,
         *     finished_at: null|string,
         *     finished_at_precision: null|string,
         *     tracked_at: string,
         *     first_attempt: bool,
         *     has_photo: bool,
         *     comment: null|string,
         *     puzzling_type: string,
         *     puzzlers_count: int,
         *     with_whom: null|string,
         *     team_name: null|string,
         *     created_via: null|string,
         *     suspicious: bool,
         * } $row
         */
        return new self(
            timeId: $row['time_id'],
            puzzle: $puzzle,
            imagePath: $row['puzzle_image'],
            seconds: $row['seconds_to_solve'],
            finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at']) : null,
            datePrecision: $row['finished_at_precision'],
            trackedAt: new DateTimeImmutable($row['tracked_at']),
            firstAttempt: $row['first_attempt'],
            hasPhoto: $row['has_photo'],
            comment: $row['comment'] !== null && trim($row['comment']) !== '' ? $row['comment'] : null,
            solvingType: $row['puzzling_type'],
            puzzlersCount: $row['puzzlers_count'],
            withWhom: $row['with_whom'],
            teamName: $row['team_name'],
            source: SolvingTimeSource::tryFrom($row['created_via'] ?? ''),
            suspicious: $row['suspicious'],
        );
    }
}
