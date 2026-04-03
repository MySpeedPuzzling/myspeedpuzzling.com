<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\CompetitionEdition;

readonly final class GetCompetitionEditions
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<CompetitionEdition>
     */
    public function upcomingForCompetition(string $competitionId): array
    {
        $query = <<<SQL
SELECT
    cr.id,
    cr.name,
    cr.starts_at,
    cr.minutes_limit,
    cr.badge_background_color,
    cr.badge_text_color,
    COUNT(crp.id) AS puzzle_count
FROM competition_round cr
LEFT JOIN competition_round_puzzle crp ON crp.round_id = cr.id
WHERE cr.competition_id = :competitionId
    AND cr.starts_at >= :now
GROUP BY cr.id
ORDER BY cr.starts_at
SQL;

        return $this->fetchEditions($query, $competitionId);
    }

    /**
     * @return array<CompetitionEdition>
     */
    public function pastForCompetition(string $competitionId): array
    {
        $query = <<<SQL
SELECT
    cr.id,
    cr.name,
    cr.starts_at,
    cr.minutes_limit,
    cr.badge_background_color,
    cr.badge_text_color,
    COUNT(crp.id) AS puzzle_count
FROM competition_round cr
LEFT JOIN competition_round_puzzle crp ON crp.round_id = cr.id
WHERE cr.competition_id = :competitionId
    AND cr.starts_at < :now
GROUP BY cr.id
ORDER BY cr.starts_at DESC
SQL;

        return $this->fetchEditions($query, $competitionId);
    }

    /**
     * @return array<CompetitionEdition>
     */
    private function fetchEditions(string $query, string $competitionId): array
    {
        $now = $this->clock->now();

        $data = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
                'now' => $now->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEdition {
            /**
             * @var array{
             *     id: string,
             *     name: string,
             *     starts_at: string,
             *     minutes_limit: int|string,
             *     badge_background_color: null|string,
             *     badge_text_color: null|string,
             *     puzzle_count: int|string,
             * } $row
             */
            return new CompetitionEdition(
                id: $row['id'],
                name: $row['name'],
                startsAt: new DateTimeImmutable($row['starts_at']),
                minutesLimit: (int) $row['minutes_limit'],
                badgeBackgroundColor: $row['badge_background_color'],
                badgeTextColor: $row['badge_text_color'],
                puzzleCount: (int) $row['puzzle_count'],
            );
        }, $data);
    }
}
