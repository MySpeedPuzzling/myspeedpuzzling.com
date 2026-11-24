<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RoundTimezone;

readonly final class AdminCompetitionRound
{
    /**
     * @param list<AdminRoundPuzzle> $puzzles
     */
    public function __construct(
        public string $roundId,
        public string $competitionId,
        public null|string $slug,
        public string $name,
        public string $category,
        // In UTC, like it is stored (`2026-10-06T08:00:00+00:00`)
        public string $startsAt,
        // The zone its times are typed and shown in (IANA)
        public string $timezone,
        // null = no limit given (CompetitionRound::$minutesLimit)
        public null|int $minutesLimit,
        // Minutes after the start when its secret puzzles with an automatic reveal come out (RoundPuzzleReveal)
        public int $revealDelayMinutes,
        // Team rounds: how many people a team is expected to have (null = not set) - a hint, never a limit
        public null|int $teamSize,
        public null|string $badgeBackgroundColor,
        public null|string $badgeTextColor,
        public null|string $resultsLink,
        // Solving times linked to the round (puzzle_solving_time.competition_round_id)
        public int $resultsCount,
        public array $puzzles,
        // How its puzzles are solved (RoundPuzzleFormat value: total | pick) and K for `pick` - competition toolkit A2,
        // read-only here (the round form sets it)
        public string $puzzleFormat = 'total',
        public null|int $puzzlesToSolve = null,
        // null = the round does not use online timing (docs/features/internal-api.md "Online timing")
        public null|AdminOnlineTiming $onlineTiming = null,
        // "Unboxed" for every puzzle of the round (world-series-rules.md §7) - a puzzle may be unboxed on its own too
        public bool $unboxed = false,
        // The names of its tables (table-labels-and-plates.md): how many (0 = plain numbers), the first and the last
        public int $tableLabelsCount = 0,
        public null|string $firstTableLabel = null,
        public null|string $lastTableLabel = null,
    ) {
    }

    /**
     * @param array{
     *     id: string,
     *     competition_id: string,
     *     slug: null|string,
     *     name: string,
     *     category: string,
     *     starts_at: string,
     *     minutes_limit: null|int,
     *     reveal_delay_minutes: int,
     *     team_size: null|int,
     *     badge_background_color: null|string,
     *     badge_text_color: null|string,
     *     results_link: null|string,
     *     timezone: null|string,
     *     location_country_code: null|string,
     *     series_country_code: null|string,
     *     results_count: int,
     *     puzzle_format?: string,
     *     puzzles_to_solve?: null|int,
     *     unboxed?: bool,
     *     table_labels_count?: int,
     *     first_table_label?: null|string,
     *     last_table_label?: null|string,
     *     online_enabled_at?: null|string,
     *     online_detection_mode?: null|string,
     *     online_unfinished_measure?: null|string,
     *     online_missing_piece_rule?: null|string,
     *     online_video_enabled?: null|bool,
     *     online_participants_see_each_other?: null|bool,
     *     online_studio_enabled?: null|bool,
     *     online_recording_enabled?: null|bool,
     *     ...
     * } $row
     * @param list<AdminRoundPuzzle> $puzzles
     */
    public static function fromDatabaseRow(array $row, array $puzzles): self
    {
        return new self(
            roundId: $row['id'],
            competitionId: $row['competition_id'],
            slug: $row['slug'],
            name: $row['name'],
            category: $row['category'],
            startsAt: AdminCompetition::isoDateTime($row['starts_at']) ?? $row['starts_at'],
            timezone: RoundTimezone::resolve($row['timezone'], $row['location_country_code'], $row['series_country_code']),
            minutesLimit: $row['minutes_limit'],
            revealDelayMinutes: $row['reveal_delay_minutes'],
            teamSize: $row['team_size'],
            badgeBackgroundColor: $row['badge_background_color'],
            badgeTextColor: $row['badge_text_color'],
            resultsLink: $row['results_link'],
            resultsCount: $row['results_count'],
            puzzles: $puzzles,
            puzzleFormat: $row['puzzle_format'] ?? 'total',
            puzzlesToSolve: $row['puzzles_to_solve'] ?? null,
            onlineTiming: AdminOnlineTiming::fromDatabaseRow($row),
            unboxed: $row['unboxed'] ?? false,
            tableLabelsCount: $row['table_labels_count'] ?? 0,
            firstTableLabel: $row['first_table_label'] ?? null,
            lastTableLabel: $row['last_table_label'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'roundId' => $this->roundId,
            'competitionId' => $this->competitionId,
            'slug' => $this->slug,
            'name' => $this->name,
            'category' => $this->category,
            'startsAt' => $this->startsAt,
            'timezone' => $this->timezone,
            'minutesLimit' => $this->minutesLimit,
            'revealDelayMinutes' => $this->revealDelayMinutes,
            'teamSize' => $this->teamSize,
            'badgeBackgroundColor' => $this->badgeBackgroundColor,
            'badgeTextColor' => $this->badgeTextColor,
            'resultsLink' => $this->resultsLink,
            'resultsCount' => $this->resultsCount,
            'puzzleFormat' => $this->puzzleFormat,
            'puzzlesToSolve' => $this->puzzlesToSolve,
            'onlineTiming' => $this->onlineTiming?->toArray(),
            'unboxed' => $this->unboxed,
            // Only on a round whose tables have names (table-labels-and-plates.md) - the list itself is
            // GET /internal-api/rounds/{id}/table-labels
            ...($this->tableLabelsCount > 0 ? ['tableLabels' => ['count' => $this->tableLabelsCount, 'first' => $this->firstTableLabel, 'last' => $this->lastTableLabel]] : []),
            'puzzles' => array_map(static fn (AdminRoundPuzzle $puzzle): array => $puzzle->toArray(), $this->puzzles),
        ];
    }
}
