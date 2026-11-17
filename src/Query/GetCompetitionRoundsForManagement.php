<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionRoundForManagement;
use SpeedPuzzling\Web\Value\RoundBadgeColor;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\RoundTimezone;

readonly final class GetCompetitionRoundsForManagement
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<CompetitionRoundForManagement>
     */
    public function ofCompetition(string $competitionId): array
    {
        $query = <<<SQL
SELECT
    cr.id,
    cr.name,
    cr.minutes_limit,
    cr.starts_at,
    cr.badge_background_color,
    cr.badge_text_color,
    cr.category,
    cr.timezone,
    c.location_country_code,
    tz_cs.location_country_code AS series_country_code,
    cr.puzzle_format,
    cr.puzzles_to_solve,
    cr.reveal_delay_minutes,
    cr.slug,
    COUNT(crp.id) AS puzzle_count,
    STRING_AGG(DISTINCT crp.reveal_mode, ',' ORDER BY crp.reveal_mode) FILTER (WHERE crp.hide_until_round_starts) AS secret_reveal_modes
FROM competition_round cr
INNER JOIN competition c ON c.id = cr.competition_id
LEFT JOIN competition_series tz_cs ON tz_cs.id = c.series_id
LEFT JOIN competition_round_puzzle crp ON crp.round_id = cr.id
WHERE cr.competition_id = :competitionId
GROUP BY cr.id, c.id, tz_cs.id
-- The position decides a round's automatic colour (RoundBadgeColor) - the same order as the event pages
ORDER BY cr.starts_at, cr.id
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row, int $schedulePosition): CompetitionRoundForManagement {
            /**
             * @var array{
             *     id: string,
             *     name: string,
             *     minutes_limit: null|int|string,
             *     starts_at: string,
             *     badge_background_color: null|string,
             *     badge_text_color: null|string,
             *     category: string,
             *     timezone: null|string,
             *     location_country_code: null|string,
             *     series_country_code: null|string,
             *     puzzle_count: int|string,
             *     puzzle_format: string,
             *     puzzles_to_solve: null|int|string,
             *     reveal_delay_minutes: int|string,
             *     secret_reveal_modes: null|string,
             *     slug: null|string,
             * } $row
             */

            $color = RoundBadgeColor::background($row['badge_background_color'], $schedulePosition);

            return new CompetitionRoundForManagement(
                id: $row['id'],
                name: $row['name'],
                minutesLimit: $row['minutes_limit'] !== null ? (int) $row['minutes_limit'] : null,
                startsAt: new DateTimeImmutable($row['starts_at']),
                badgeBackgroundColor: $row['badge_background_color'],
                badgeTextColor: $row['badge_text_color'],
                color: $color,
                textColor: RoundBadgeColor::text($color),
                schedulePosition: $schedulePosition,
                puzzleCount: (int) $row['puzzle_count'],
                category: RoundCategory::from($row['category']),
                timezone: RoundTimezone::resolve($row['timezone'], $row['location_country_code'], $row['series_country_code']),
                timezoneAssumed: RoundTimezone::isAssumed($row['timezone'], $row['location_country_code'], $row['series_country_code']),
                puzzleFormat: RoundPuzzleFormat::from($row['puzzle_format']),
                puzzlesToSolve: $row['puzzles_to_solve'] !== null ? (int) $row['puzzles_to_solve'] : null,
                revealDelayMinutes: (int) $row['reveal_delay_minutes'],
                secretRevealModes: $row['secret_reveal_modes'] !== null ? explode(',', $row['secret_reveal_modes']) : [],
                slug: $row['slug'],
            );
        }, $data, array_keys($data));
    }
}
