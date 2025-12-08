<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class ExportableSolvingTime
{
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public string $puzzleName,
        public string $brandName,
        public int $piecesCount,
        public null|int $secondsToSolve,
        public string $timeFormatted,
        public DateTimeImmutable $finishedAt,
        public DateTimeImmutable $trackedAt,
        public string $type,
        public bool $firstAttempt,
        public string $teamMembers,
        public null|string $finishedPuzzlePhotoUrl,
        public null|string $comment,
    ) {
    }

    /**
     * @param array{
     *     time_id: string,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     brand_name: string,
     *     pieces_count: int,
     *     seconds_to_solve: null|int,
     *     finished_at: string,
     *     tracked_at: string,
     *     first_attempt: bool,
     *     finished_puzzle_photo: null|string,
     *     comment: null|string,
     *     solving_type: string,
     *     team_members: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row, string $baseUrl): self
    {
        $photoUrl = $row['finished_puzzle_photo'] !== null
            ? $baseUrl . '/' . $row['finished_puzzle_photo']
            : null;

        return new self(
            timeId: $row['time_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            brandName: $row['brand_name'],
            piecesCount: $row['pieces_count'],
            secondsToSolve: $row['seconds_to_solve'],
            timeFormatted: self::formatTime($row['seconds_to_solve']),
            finishedAt: new DateTimeImmutable($row['finished_at']),
            trackedAt: new DateTimeImmutable($row['tracked_at']),
            type: $row['solving_type'],
            firstAttempt: $row['first_attempt'],
            teamMembers: $row['team_members'] ?? '',
            finishedPuzzlePhotoUrl: $photoUrl,
            comment: $row['comment'],
        );
    }

    private static function formatTime(null|int $seconds): string
    {
        if ($seconds === null) {
            return '';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'result_id' => $this->timeId,
            'puzzle_id' => $this->puzzleId,
            'puzzle_name' => $this->puzzleName,
            'brand_name' => $this->brandName,
            'pieces_count' => $this->piecesCount,
            'seconds_to_solve' => $this->secondsToSolve,
            'time_formatted' => $this->timeFormatted,
            'finished_at' => $this->finishedAt->format('Y-m-d H:i:s'),
            'tracked_at' => $this->trackedAt->format('Y-m-d H:i:s'),
            'type' => $this->type,
            'first_attempt' => $this->firstAttempt,
            'team_members' => $this->teamMembers,
            'finished_puzzle_photo_url' => $this->finishedPuzzlePhotoUrl,
            'comment' => $this->comment,
        ];
    }
}
