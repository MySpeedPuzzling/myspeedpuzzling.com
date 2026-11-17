<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionRoundEntryTag;
use SpeedPuzzling\Web\Results\CompetitionTagMember;

/**
 * Printed round entry tags (competition toolkit B-qr-competitor-page.md §3.4): one tag per entry of a round - a solo
 * round's person going (CompetitionParticipantRound; not on the waitlist), a pair/team round's pair/team
 * (CompetitionTeam) with its people not removed - with the round's table number. The id is the one the entry tag route
 * resolves (`/live/{cid}/e/{entryId}`, GetCompetitionTagEntries::entry()).
 *
 * Organiser tooling behind COMPETITION_EDIT: names as the organiser recorded them, no blocklist; a linked player's
 * #CODE only for a public profile, whoever prints (the name tags' rule, GetCompetitionNameTags). One statement.
 */
readonly final class GetRoundEntryTags
{
    public const string SORT_NAME = GetCompetitionNameTags::SORT_NAME;
    public const string SORT_TABLE = GetCompetitionNameTags::SORT_TABLE;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<CompetitionRoundEntryTag>
     */
    public function ofRound(string $competitionId, string $roundId, string $sort): array
    {
        $going = CompetitionParticipantGoing::sql('cp');
        $member = <<<SQL
json_build_object(
        'id', cp.id,
        'name', cp.name,
        'country', cp.country,
        'code', CASE WHEN p.is_private THEN NULL ELSE p.code END
    )
SQL;

        /** @var list<array{id: string, team_name: null|string, table_number: null|int, members: string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cpr.id,
    NULL AS team_name,
    CASE WHEN cr.table_numbers_off THEN NULL ELSE cpr.table_number END AS table_number,
    json_build_array({$member})::text AS members
FROM competition_participant_round cpr
INNER JOIN competition_round cr ON cr.id = cpr.round_id AND cr.category = 'solo'
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND {$going}
LEFT JOIN player p ON p.id = cp.player_id
WHERE cr.id = :roundId
    AND cr.competition_id = :competitionId
UNION ALL
SELECT
    ct.id,
    ct.name AS team_name,
    CASE WHEN cr.table_numbers_off THEN NULL ELSE ct.table_number END AS table_number,
    (COALESCE(json_agg({$member} ORDER BY LOWER(cp.name), cp.id) FILTER (WHERE cp.id IS NOT NULL), '[]'::json))::text AS members
FROM competition_team ct
INNER JOIN competition_round cr ON cr.id = ct.round_id AND cr.category <> 'solo'
LEFT JOIN competition_participant_round cpr ON cpr.team_id = ct.id
LEFT JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
LEFT JOIN player p ON p.id = cp.player_id
WHERE cr.id = :roundId
    AND cr.competition_id = :competitionId
GROUP BY ct.id, cr.id
SQL,
            ['competitionId' => $competitionId, 'roundId' => $roundId],
        );

        $tags = array_map(static function (array $row): CompetitionRoundEntryTag {
            /** @var list<array{id: string, name: string, country: null|string, code: null|string}> $members */
            $members = json_decode($row['members'], true, flags: JSON_THROW_ON_ERROR);

            return new CompetitionRoundEntryTag(
                entryId: $row['id'],
                teamName: $row['team_name'],
                members: array_map(CompetitionTagMember::fromRow(...), $members),
                tableNumber: $row['table_number'],
            );
        }, $rows);

        usort($tags, static function (CompetitionRoundEntryTag $a, CompetitionRoundEntryTag $b) use ($sort): int {
            if ($sort === self::SORT_TABLE && $a->tableNumber !== $b->tableNumber) {
                // Without a table last
                return ($a->tableNumber ?? PHP_INT_MAX) <=> ($b->tableNumber ?? PHP_INT_MAX);
            }

            return [mb_strtolower($a->label()), $a->entryId] <=> [mb_strtolower($b->label()), $b->entryId];
        });

        return $tags;
    }
}
