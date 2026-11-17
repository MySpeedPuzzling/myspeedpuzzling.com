<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionLineupChange;
use SpeedPuzzling\Web\Results\CompetitionLineupTag;
use SpeedPuzzling\Web\Results\CompetitionLineupTags;
use SpeedPuzzling\Web\Results\CompetitionTagMember;
use SpeedPuzzling\Web\Value\CompetitionLineupId;

/**
 * Printed line-up tags of an event (competition toolkit B-qr-competitor-page.md §3.2, §3.4): every pair/team of the
 * pair/team rounds, grouped by its exact member set (CompetitionLineupId - hashed here, never stored, over the members
 * the tag route resolves by: participants not removed, GetCompetitionTagEntries::ofLineup()), one tag per line-up. With
 * a round given, only the line-ups of that round with its table numbers; else every line-up with the table of its
 * first round by schedule.
 *
 * Also the line-ups whose people play in another line-up of a later round of the same category - their old tags show
 * only the old line-up's rounds (R-review-and-plan.md §2.11), the print page says so.
 *
 * Organiser tooling behind COMPETITION_EDIT: names as the organiser recorded them, no blocklist; a linked player's
 * #CODE only for a public profile, whoever prints (the name tags' rule, GetCompetitionNameTags). One statement.
 */
readonly final class GetCompetitionLineupTags
{
    public const string SORT_NAME = GetCompetitionNameTags::SORT_NAME;
    public const string SORT_TABLE = GetCompetitionNameTags::SORT_TABLE;

    public function __construct(
        private Connection $database,
    ) {
    }

    public function ofCompetition(string $competitionId, null|string $roundId, string $sort): CompetitionLineupTags
    {
        /** @var list<array{team_id: string, team_name: null|string, round_id: string, round_name: string, category: string, starts_at: string, table_number: null|int, members: string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    ct.id AS team_id,
    ct.name AS team_name,
    cr.id AS round_id,
    cr.name AS round_name,
    cr.category,
    cr.starts_at,
    CASE WHEN cr.table_numbers_off THEN NULL ELSE ct.table_number END AS table_number,
    json_agg(json_build_object(
        'id', cp.id,
        'name', cp.name,
        'country', cp.country,
        'code', CASE WHEN p.is_private THEN NULL ELSE p.code END
    ) ORDER BY LOWER(cp.name), cp.id) AS members
FROM competition_team ct
INNER JOIN competition_round cr ON cr.id = ct.round_id AND cr.category <> 'solo'
INNER JOIN competition_participant_round cpr ON cpr.team_id = ct.id
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
LEFT JOIN player p ON p.id = cp.player_id
WHERE cr.competition_id = :competitionId
GROUP BY ct.id, cr.id
ORDER BY cr.starts_at, cr.name, cr.id, ct.id
SQL,
            ['competitionId' => $competitionId],
        );

        /** @var array<string, array{teamName: null|string, members: list<CompetitionTagMember>, rounds: list<array{id: string, name: string, category: string, startsAt: string, tableNumber: null|int}>}> $lineups */
        $lineups = [];

        foreach ($rows as $row) {
            /** @var list<array{id: string, name: string, country: null|string, code: null|string}> $memberRows */
            $memberRows = json_decode($row['members'], true, flags: JSON_THROW_ON_ERROR);
            $members = array_map(CompetitionTagMember::fromRow(...), $memberRows);
            $lineupId = CompetitionLineupId::of($competitionId, array_map(static fn (CompetitionTagMember $member): string => $member->participantId, $members));

            $lineups[$lineupId] ??= ['teamName' => null, 'members' => $members, 'rounds' => []];
            // The first name the organiser gave the line-up, by schedule
            $lineups[$lineupId]['teamName'] ??= $row['team_name'];
            $lineups[$lineupId]['rounds'][] = [
                'id' => $row['round_id'],
                'name' => $row['round_name'],
                'category' => $row['category'],
                'startsAt' => $row['starts_at'],
                'tableNumber' => $row['table_number'],
            ];
        }

        $tags = [];

        foreach ($lineups as $lineupId => $lineup) {
            $printedRound = $lineup['rounds'][0];

            if ($roundId !== null) {
                $printedRound = null;

                foreach ($lineup['rounds'] as $round) {
                    if ($round['id'] === strtolower($roundId)) {
                        $printedRound = $round;
                    }
                }

                if ($printedRound === null) {
                    continue;
                }
            }

            $tags[] = new CompetitionLineupTag(
                lineupId: $lineupId,
                teamName: $lineup['teamName'],
                members: $lineup['members'],
                tableNumber: $printedRound['tableNumber'],
                roundName: $printedRound['name'],
                roundNames: array_map(static fn (array $round): string => $round['name'], $lineup['rounds']),
            );
        }

        usort($tags, static function (CompetitionLineupTag $a, CompetitionLineupTag $b) use ($sort): int {
            if ($sort === self::SORT_TABLE && $a->tableNumber !== $b->tableNumber) {
                // Without a table last
                return ($a->tableNumber ?? PHP_INT_MAX) <=> ($b->tableNumber ?? PHP_INT_MAX);
            }

            return [mb_strtolower($a->label()), $a->lineupId] <=> [mb_strtolower($b->label()), $b->lineupId];
        });

        return new CompetitionLineupTags($tags, self::changes($lineups));
    }

    /**
     * A line-up changed when one of its people plays in another line-up of the same category in a round that starts
     * after one of the line-up's rounds - reported once per old and new line-up, at the first such round.
     *
     * @param array<string, array{teamName: null|string, members: list<CompetitionTagMember>, rounds: list<array{id: string, name: string, category: string, startsAt: string, tableNumber: null|int}>}> $lineups
     * @return list<CompetitionLineupChange>
     */
    private static function changes(array $lineups): array
    {
        $label = static fn (array $lineup): string => $lineup['teamName'] ?? implode(' & ', array_map(static fn (CompetitionTagMember $member): string => $member->name, $lineup['members']));
        $memberIds = static fn (array $lineup): array => array_map(static fn (CompetitionTagMember $member): string => $member->participantId, $lineup['members']);

        $changes = [];

        foreach ($lineups as $oldId => $old) {
            foreach ($lineups as $newId => $new) {
                if ($oldId === $newId || array_intersect($memberIds($old), $memberIds($new)) === []) {
                    continue;
                }

                foreach ($new['rounds'] as $laterRound) {
                    $isLater = array_any(
                        $old['rounds'],
                        static fn (array $round): bool => $round['category'] === $laterRound['category'] && $round['startsAt'] < $laterRound['startsAt'],
                    );

                    if ($isLater) {
                        $changes[] = new CompetitionLineupChange($label($old), $laterRound['name'], $label($new));

                        break;
                    }
                }
            }
        }

        return $changes;
    }
}
