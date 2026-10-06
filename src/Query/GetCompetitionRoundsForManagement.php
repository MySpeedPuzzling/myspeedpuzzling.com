<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionRoundForManagement;
use SpeedPuzzling\Web\Value\RoundCategory;
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
    COUNT(crp.id) AS puzzle_count
FROM competition_round cr
INNER JOIN competition c ON c.id = cr.competition_id
LEFT JOIN competition_round_puzzle crp ON crp.round_id = cr.id
WHERE cr.competition_id = :competitionId
GROUP BY cr.id, c.id
ORDER BY cr.starts_at
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionRoundForManagement {
            /**
             * @var array{
             *     id: string,
             *     name: string,
             *     minutes_limit: int|string,
             *     starts_at: string,
             *     badge_background_color: null|string,
             *     badge_text_color: null|string,
             *     category: string,
             *     timezone: null|string,
             *     location_country_code: null|string,
             *     puzzle_count: int|string,
             * } $row
             */

            return new CompetitionRoundForManagement(
                id: $row['id'],
                name: $row['name'],
                minutesLimit: (int) $row['minutes_limit'],
                startsAt: new DateTimeImmutable($row['starts_at']),
                badgeBackgroundColor: $row['badge_background_color'],
                badgeTextColor: $row['badge_text_color'],
                puzzleCount: (int) $row['puzzle_count'],
                category: RoundCategory::from($row['category']),
                timezone: RoundTimezone::resolve($row['timezone'], $row['location_country_code']),
            );
        }, $data);
    }
}
