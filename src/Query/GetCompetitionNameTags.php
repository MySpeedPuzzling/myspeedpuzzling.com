<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionNameTag;

/**
 * Name tags of an event (docs/features/competitions-management/live-results.md): every active participant - not
 * removed, not on the waitlist - with the table number of their first round (by schedule) or, with a round given,
 * only the people of that round with its table number. A pair/team round's number is the pair's/team's.
 * Organiser tooling behind COMPETITION_EDIT: names as the organiser recorded them, no blocklist. One statement.
 */
readonly final class GetCompetitionNameTags
{
    public const string SORT_NAME = 'name';
    public const string SORT_TABLE = 'table';

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<CompetitionNameTag>
     */
    public function forCompetition(string $competitionId, null|string $roundId, string $sort): array
    {
        $roundCondition = $roundId !== null ? 'AND cr.id = :roundId' : '';
        $roundRequired = $roundId !== null ? 'AND entry.round_id IS NOT NULL' : '';
        $order = $sort === self::SORT_TABLE
            ? 'entry.table_number NULLS LAST, LOWER(cp.name), cp.id'
            : 'LOWER(cp.name), cp.id';

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cp.id,
    cp.name,
    cp.country,
    p.code AS player_code,
    entry.table_number,
    entry.round_name
FROM competition_participant cp
LEFT JOIN player p ON p.id = cp.player_id
LEFT JOIN LATERAL (
    SELECT
        cr.id AS round_id,
        cr.name AS round_name,
        CASE
            WHEN cr.table_numbers_off THEN NULL
            WHEN cr.category = 'solo' THEN cpr.table_number
            ELSE ct.table_number
        END AS table_number
    FROM competition_participant_round cpr
    INNER JOIN competition_round cr ON cr.id = cpr.round_id
    LEFT JOIN competition_team ct ON ct.id = cpr.team_id
    WHERE cpr.participant_id = cp.id
        {$roundCondition}
    ORDER BY cr.starts_at, cr.name, cr.id
    LIMIT 1
) entry ON true
WHERE cp.competition_id = :competitionId
    AND cp.deleted_at IS NULL
    AND (cp.registration_status IS NULL OR cp.registration_status <> 'waitlisted')
    {$roundRequired}
ORDER BY {$order}
SQL,
            array_filter(['competitionId' => $competitionId, 'roundId' => $roundId], static fn (null|string $value): bool => $value !== null),
        );

        return array_map(static function (array $row): CompetitionNameTag {
            /** @var array{id: string, name: string, country: null|string, player_code: null|string, table_number: null|int, round_name: null|string} $row */
            return new CompetitionNameTag(
                participantId: $row['id'],
                name: $row['name'],
                country: $row['country'],
                playerCode: $row['player_code'],
                tableNumber: $row['table_number'],
                roundName: $row['round_name'],
            );
        }, $rows);
    }
}
