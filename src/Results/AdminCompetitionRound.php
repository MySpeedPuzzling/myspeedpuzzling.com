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
        public int $minutesLimit,
        // Minutes after the start when its secret puzzles with an automatic reveal come out (RoundPuzzleReveal)
        public int $revealDelayMinutes,
        public null|string $badgeBackgroundColor,
        public null|string $badgeTextColor,
        public null|string $resultsLink,
        // Solving times linked to the round (puzzle_solving_time.competition_round_id)
        public int $resultsCount,
        public array $puzzles,
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
     *     minutes_limit: int,
     *     reveal_delay_minutes: int,
     *     badge_background_color: null|string,
     *     badge_text_color: null|string,
     *     results_link: null|string,
     *     timezone: null|string,
     *     location_country_code: null|string,
     *     series_country_code: null|string,
     *     results_count: int,
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
            badgeBackgroundColor: $row['badge_background_color'],
            badgeTextColor: $row['badge_text_color'],
            resultsLink: $row['results_link'],
            resultsCount: $row['results_count'],
            puzzles: $puzzles,
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
            'badgeBackgroundColor' => $this->badgeBackgroundColor,
            'badgeTextColor' => $this->badgeTextColor,
            'resultsLink' => $this->resultsLink,
            'resultsCount' => $this->resultsCount,
            'puzzles' => array_map(static fn (AdminRoundPuzzle $puzzle): array => $puzzle->toArray(), $this->puzzles),
        ];
    }
}
