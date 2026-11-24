<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitorPageNotFound;
use SpeedPuzzling\Web\Results\CompetitionReference;
use SpeedPuzzling\Web\Results\CompetitorListLineup;
use SpeedPuzzling\Web\Results\CompetitorListPerson;
use SpeedPuzzling\Web\Results\CompetitorListTable;
use SpeedPuzzling\Web\Results\CompetitorListView;
use SpeedPuzzling\Web\Results\PublishedRoundEntrant;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\SearchText;
use SpeedPuzzling\Web\Value\TableLabels;

/**
 * The public competitor list of an event (docs/features/competitions-management/competitor-pages.md "Competitor list",
 * design-v2 competitors-splits.md §2.1) - who a phone at the venue can open the competitor page of:
 *
 * - **Only while the event has competitor pages on** - else CompetitorPageNotFound, for organisers too (as the pages).
 *   An event that is not publicly visible (IsCompetitionPubliclyVisible: a draft, waiting for approval, rejected) comes
 *   back marked (isHidden): CompetitorPageLoader answers 404 to anybody but its team - a public listing of people
 *   exists only for a public event.
 * - People: everybody going (CompetitionParticipantGoing - not removed, not on the waitlist), in no round too.
 * - Pairs/teams as line-ups: the exact member set of a pair/team (members = people not removed from the event), the same
 *   rule GetCompetitorPage resolves a `g` tag by - so every listed line-up has its page. Named by its latest round's team
 *   name, else by its members.
 * - Masked like the public round page (OfficialEntrantsView::entrant()), without avatars; the blocklist's group rule
 *   (OfficialEntrantsView::isHiddenFromViewer()): a person hidden from the viewer, and a line-up with one, is not listed -
 *   the viewer's own pair/team stays.
 * - Each row's tables (competitor-pages.md "Competitor list - search by table"): a person's in their solo rounds (and
 *   a pair/team round they wait in without a pair/team - as their page), a pair's/team's in its rounds - what their competitor page shows already (no round with table numbers off), searched
 *   in the browser. Nothing about results or puzzles.
 *
 * Statements: the competition (1), its people and pairs/teams with their members and tables (1) - whatever the event's
 * size.
 */
readonly final class GetCompetitorList
{
    public function __construct(
        private Connection $database,
        private OfficialEntrantsView $officialEntrantsView,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * @throws CompetitorPageNotFound
     */
    public function of(string $competitionId, null|string $viewerPlayerId): CompetitorListView
    {
        if (Uuid::isValid($competitionId) === false) {
            throw new CompetitorPageNotFound();
        }

        $competitionId = strtolower($competitionId);
        $viewerPlayerId = $viewerPlayerId !== null ? strtolower($viewerPlayerId) : null;

        $publiclyVisible = IsCompetitionPubliclyVisible::SQL_CONDITION;

        /** @var false|array{name: string, slug: null|string, competitor_pages: bool, is_hidden: bool, series_name: null|string, series_slug: null|string} $competition */
        $competition = $this->database->fetchAssociative(
            <<<SQL
SELECT
    c.name,
    c.slug,
    c.competitor_pages,
    (NOT COALESCE({$publiclyVisible}, false)) AS is_hidden,
    cs.name AS series_name,
    cs.slug AS series_slug
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE c.id = :competitionId
SQL,
            ['competitionId' => $competitionId],
        );

        if ($competition === false || $competition['competitor_pages'] === false) {
            throw new CompetitorPageNotFound();
        }

        $people = [];
        /** @var array<string, array{team_name: null|string, team_names: list<string>, is_team: bool, members: list<PublishedRoundEntrant>, tables: list<array{number: int, label: null|string, round: string}>}> $lineups */
        $lineups = [];

        foreach ($this->entrants($competitionId) as $row) {
            $members = array_map(
                static fn (array $member): PublishedRoundEntrant => OfficialEntrantsView::entrant(
                    $member['name'],
                    $member['country'],
                    $member['player_id'],
                    $member['player_code'],
                    $member['player_country'],
                    null,
                    $member['player_is_private'] === true,
                    $viewerPlayerId,
                ),
                $row['members'],
            );

            if ($row['kind'] === 'person') {
                if ($members !== []) {
                    $people[] = [strtolower($row['id']), $members[0], $row['tables']];
                }

                continue;
            }

            // A line-up is its exact member set - hashed here, never stored (CompetitionLineupId); an empty pair/team is nobody
            if ($members === []) {
                continue;
            }

            $lineupId = CompetitionLineupId::of($competitionId, array_column($row['members'], 'participant_id'));
            $teamName = $row['team_name'] !== null && trim($row['team_name']) !== '' ? $row['team_name'] : null;

            // Rows come in schedule order: the latest round's team name wins (the line-up page's header)
            // Every team name it played under is searchable, the tables of all its rounds in schedule order
            $teamNames = $lineups[$lineupId]['team_names'] ?? [];
            if ($teamName !== null && !in_array($teamName, $teamNames, true)) {
                $teamNames[] = $teamName;
            }

            $lineups[$lineupId] = [
                'team_name' => $teamName ?? ($lineups[$lineupId]['team_name'] ?? null),
                'team_names' => $teamNames,
                'is_team' => ($lineups[$lineupId]['is_team'] ?? false) || $row['category'] === RoundCategory::Team->value,
                'members' => $members,
                'tables' => [...($lineups[$lineupId]['tables'] ?? []), ...$row['tables']],
            ];
        }

        $listedPeople = [];
        foreach ($people as [$participantId, $entrant, $tables]) {
            if ($this->officialEntrantsView->isHiddenFromViewer([$entrant], $viewerPlayerId)) {
                continue;
            }

            $code = $entrant->playerId !== null ? $entrant->linkedPlayerCode : null;
            $listedPeople[] = new CompetitorListPerson(
                participantId: $participantId,
                entrant: $entrant,
                isViewer: OfficialEntrantsView::isViewers([$entrant], $viewerPlayerId),
                search: trim(SearchText::fold($entrant->playerName) . ($code !== null ? ' #' . SearchText::fold($code) : '')),
                tables: self::tables($tables),
            );
        }

        $listedLineups = [];
        foreach ($lineups as $lineupId => $lineup) {
            if ($this->officialEntrantsView->isHiddenFromViewer($lineup['members'], $viewerPlayerId)) {
                continue;
            }

            $names = array_map(static fn (PublishedRoundEntrant $member): string => $member->playerName, $lineup['members']);
            $listedLineups[] = new CompetitorListLineup(
                lineupId: $lineupId,
                teamName: $lineup['team_name'],
                members: $lineup['members'],
                isTeam: $lineup['is_team'] || count($lineup['members']) > 2,
                isViewers: OfficialEntrantsView::isViewers($lineup['members'], $viewerPlayerId),
                search: SearchText::fold(implode(' ', [...$lineup['team_names'], ...$names])),
                tables: self::tables($lineup['tables']),
            );
        }

        usort($listedPeople, static fn (CompetitorListPerson $a, CompetitorListPerson $b): int => [SearchText::fold($a->entrant->playerName), $a->participantId] <=> [SearchText::fold($b->entrant->playerName), $b->participantId]);
        usort($listedLineups, static fn (CompetitorListLineup $a, CompetitorListLineup $b): int => [SearchText::fold($a->displayName()), $a->lineupId] <=> [SearchText::fold($b->displayName()), $b->lineupId]);

        return new CompetitorListView(
            competitionId: $competitionId,
            competition: new CompetitionReference(
                name: $competition['name'],
                slug: $competition['slug'],
                seriesName: $competition['series_name'],
                seriesSlug: $competition['series_slug'],
            ),
            isHidden: $competition['is_hidden'],
            people: $listedPeople,
            lineups: $listedLineups,
        );
    }

    /**
     * A row's tables, one per table name in schedule order of its first round - a table kept for the whole championship
     * is one item with every round's name.
     *
     * @param list<array{number: int, label: null|string, round: string}> $rows schedule order
     * @return list<CompetitorListTable>
     */
    private static function tables(array $rows): array
    {
        /** @var array<string, array{label: string, rounds: non-empty-list<string>}> $tables */
        $tables = [];

        foreach ($rows as $row) {
            $label = $row['label'] !== null && trim($row['label']) !== '' ? $row['label'] : (string) $row['number'];
            $key = TableLabels::key($label);

            if (isset($tables[$key])) {
                $tables[$key]['rounds'][] = $row['round'];
                continue;
            }

            $tables[$key] = ['label' => $label, 'rounds' => [$row['round']]];
        }

        $list = [];
        foreach ($tables as $key => $table) {
            $list[] = new CompetitorListTable((string) $key, $table['label'], $table['rounds']);
        }

        return $list;
    }

    /**
     * Everybody going and every pair/team with its members - one statement; pairs/teams in schedule order of their rounds.
     *
     * @return list<array{kind: string, id: string, category: null|string, team_name: null|string, members: list<array{participant_id: string, name: string, country: null|string, player_id: null|string, player_code: null|string, player_country: null|string, player_is_private: null|bool}>, tables: list<array{number: int, label: null|string, round: string}>}>
     */
    private function entrants(string $competitionId): array
    {
        $private = $this->privateProfileAccess->sqlIsPrivate('player');
        // No avatar: hundreds of pictures would be most of what a phone at the venue loads
        $member = <<<SQL
json_build_object(
    'participant_id', cp.id,
    'name', cp.name,
    'country', cp.country,
    'player_id', player.id,
    'player_code', player.code,
    'player_country', player.country,
    'player_is_private', {$private}
)
SQL;
        $going = CompetitionParticipantGoing::sql('cp');
        // A table as its competitor page shows it (GetCompetitorPage): never of a round with table numbers off
        $soloLabel = TableLabelSql::label('tcr', 'tcpr.table_number');
        $teamLabel = TableLabelSql::label('cr', 'ct.table_number');

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    'person' AS kind,
    cp.id,
    NULL::varchar AS category,
    NULL::varchar AS team_name,
    NULL::timestamp AS starts_at,
    NULL::varchar AS round_name,
    NULL::uuid AS round_id,
    json_build_array({$member})::text AS members,
    (
        SELECT json_agg(json_build_object('number', tcpr.table_number, 'label', {$soloLabel}, 'round', tcr.name) ORDER BY tcr.starts_at, tcr.name, tcr.id)
        FROM competition_participant_round tcpr
        INNER JOIN competition_round tcr ON tcr.id = tcpr.round_id AND tcr.table_numbers_off = false
        WHERE tcpr.participant_id = cp.id
            AND (tcr.category = 'solo' OR tcpr.team_id IS NULL)
            AND tcpr.table_number IS NOT NULL
    )::text AS tables
FROM competition_participant cp
LEFT JOIN player ON player.id = cp.player_id
WHERE cp.competition_id = :competitionId
    AND {$going}
UNION ALL
SELECT
    'team' AS kind,
    ct.id,
    cr.category,
    ct.name AS team_name,
    cr.starts_at,
    cr.name AS round_name,
    cr.id AS round_id,
    (
        SELECT json_agg({$member} ORDER BY cp.name, cp.id)
        FROM competition_participant_round m
        INNER JOIN competition_participant cp ON cp.id = m.participant_id AND cp.deleted_at IS NULL
        LEFT JOIN player ON player.id = cp.player_id
        WHERE m.team_id = ct.id
    )::text AS members,
    CASE WHEN ct.table_number IS NOT NULL AND cr.table_numbers_off = false
        THEN json_build_array(json_build_object('number', ct.table_number, 'label', {$teamLabel}, 'round', cr.name))::text
    END AS tables
FROM competition_team ct
INNER JOIN competition_round cr ON cr.id = ct.round_id AND cr.category <> 'solo'
WHERE cr.competition_id = :competitionId
ORDER BY kind, starts_at, round_name, round_id, id
SQL,
            ['competitionId' => $competitionId],
        );

        $entrants = [];

        foreach ($rows as $row) {
            /** @var array{kind: string, id: string, category: null|string, team_name: null|string, members: null|string, tables: null|string} $row */
            /** @var list<array{participant_id: string, name: string, country: null|string, player_id: null|string, player_code: null|string, player_country: null|string, player_is_private: null|bool}> $members */
            $members = $row['members'] !== null ? json_decode($row['members'], true, flags: JSON_THROW_ON_ERROR) : [];
            /** @var list<array{number: int, label: null|string, round: string}> $tables */
            $tables = $row['tables'] !== null ? json_decode($row['tables'], true, flags: JSON_THROW_ON_ERROR) : [];

            $entrants[] = [
                'kind' => $row['kind'],
                'id' => $row['id'],
                'category' => $row['category'],
                'team_name' => $row['team_name'],
                'members' => $members,
                'tables' => $tables,
            ];
        }

        return $entrants;
    }
}
