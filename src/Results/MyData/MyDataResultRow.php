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
        // The other people of a pair/team result, in their order; `name` null = hidden from this player (a private
        // profile they are not let into) - worded by the template like everywhere else
        /** @var list<array{name: null|string, hidden: bool}> */
        public array $coPuzzlers,
        public null|string $teamName,
        public null|SolvingTimeSource $source,
        public bool $suspicious,
        // The player tracked it and nobody else registered is on it (solo, or a group with guests only)
        public bool $onlyRegisteredMember = true,
        // An unfinished competition result (docs/features/competitions-management/unfinished-results-plan.md): pieces
        // placed when the round's time ran out - shown instead of "no time"; editing keeps to the cells that are not the time
        public null|int $piecesPlaced = null,
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
     * Whether the row's own fields (time, day, first try, comment) can be edited by the player: every result of the
     * table but an imported event result - that one is the event's (SolvedPuzzleDetail::isEditableBy()).
     */
    public function isEditable(): bool
    {
        return $this->source !== SolvingTimeSource::EventImport;
    }

    /**
     * What a cell holds for an edit in place (`data-value`, sent back as `from`) - the same text
     * MyDataResultEdit::cell() makes of the stored result: the time as `h:mm:ss`, the exact day as `Y-m-d` ('' while
     * only the month or year is known).
     */
    /**
     * Whether its cells are edited in place: only where the player is the only registered person of the result. Every
     * save of a pair's or team's result tells the other registered members (GroupSolvingTimeEdited) - one cell after
     * another would be one notification each, so such a result is edited through its row sheet: one save, one
     * notification (docs/features/my-data.md "Editing in place").
     */
    public function isEditableInPlace(): bool
    {
        return $this->isEditable() && $this->onlyRegisteredMember;
    }

    public function editValue(string $field): string
    {
        return match ($field) {
            'time' => $this->seconds !== null && $this->seconds > 0 ? sprintf('%d:%02d:%02d', intdiv($this->seconds, 3600), intdiv($this->seconds % 3600, 60), $this->seconds % 60) : '',
            'date' => $this->finishedAt !== null && ($this->datePrecision === null || $this->datePrecision === 'day') ? $this->finishedAt->format('Y-m-d') : '',
            'first_try' => $this->firstAttempt ? '1' : '0',
            default => $this->comment ?? '',
        };
    }

    /**
     * @return list<array{name: null|string, hidden: bool}>
     */
    private static function coPuzzlers(null|string $json): array
    {
        $decoded = $json !== null ? json_decode($json, true) : null;
        $people = [];

        foreach (is_array($decoded) ? $decoded : [] as $person) {
            if (is_array($person) === false) {
                continue;
            }

            $hidden = ($person['hidden'] ?? false) === true;
            $name = is_string($person['name'] ?? null) ? $person['name'] : null;

            if ($hidden || $name !== null) {
                $people[] = ['name' => $hidden ? null : $name, 'hidden' => $hidden];
            }
        }

        return $people;
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
         *     pieces_placed?: null|int,
         *     finished_at: null|string,
         *     finished_at_precision: null|string,
         *     tracked_at: string,
         *     first_attempt: bool,
         *     has_photo: bool,
         *     comment: null|string,
         *     puzzling_type: string,
         *     puzzlers_count: int,
         *     co_puzzlers: null|string,
         *     team_name: null|string,
         *     created_via: null|string,
         *     suspicious: bool,
         *     only_registered_member: bool,
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
            coPuzzlers: self::coPuzzlers($row['co_puzzlers']),
            teamName: $row['team_name'],
            source: SolvingTimeSource::tryFrom($row['created_via'] ?? ''),
            suspicious: $row['suspicious'],
            onlyRegisteredMember: $row['only_registered_member'],
            piecesPlaced: $row['pieces_placed'] ?? null,
        );
    }
}
