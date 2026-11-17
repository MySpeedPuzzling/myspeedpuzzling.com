<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\AnalyticsStudioEntrant;
use SpeedPuzzling\Web\Results\AnalyticsStudioMember;
use SpeedPuzzling\Web\Results\AnalyticsStudioRound;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * Every entry of one studio round with its people (analytics-studio.md §2.4) - one statement, shaped like
 * GetCompetitorPage::entries(): solo entries of going participants (CompetitionParticipantGoing), or the round's
 * pairs/teams with their going members. People of a pair/team round without a pair/team are no entry.
 *
 * No viewer (the studio's JSON is on the stateless firewall): names are the organiser's, masked by
 * OfficialEntrantsView::entrant() with viewer null, and a private player (PrivateProfileAccess::sqlIsPrivate() - no
 * allow list unmasks without a viewer) is never analysable. The blocklist is a documented exemption (analytics-studio.md
 * D3): one broadcast shows the same entrants to everybody. Ordered by name (team name or the people's names).
 */
readonly final class GetAnalyticsStudioEntrants
{
    public function __construct(
        private Connection $database,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * @return list<AnalyticsStudioEntrant>
     */
    public function forRound(AnalyticsStudioRound $round): array
    {
        return $this->fetch($round, null);
    }

    /**
     * Only these entries of the round (the entrant detail, the compare set) - the same rules, filtered in the statement;
     * a key that is no entry of the round is simply not there.
     *
     * @param list<string> $entryIds
     * @return list<AnalyticsStudioEntrant>
     */
    public function forEntries(AnalyticsStudioRound $round, array $entryIds): array
    {
        $entryIds = array_values(array_filter(array_map('strtolower', $entryIds), Uuid::isValid(...)));

        return $entryIds === [] ? [] : $this->fetch($round, $entryIds);
    }

    /**
     * @param null|list<string> $entryIds null = every entry
     * @return list<AnalyticsStudioEntrant>
     */
    private function fetch(AnalyticsStudioRound $round, null|array $entryIds): array
    {
        $private = $this->privateProfileAccess->sqlIsPrivate('player');
        $member = <<<SQL
json_build_object(
    'participant_id', cp.id,
    'name', cp.name,
    'country', cp.country,
    'player_id', player.id,
    'player_code', player.code,
    'player_country', player.country,
    'player_avatar', player.avatar,
    'player_is_private', {$private},
    'time_predictions_opted_out', COALESCE(player.time_predictions_opted_out, false),
    'ranking_opted_out', COALESCE(player.ranking_opted_out, false)
)
SQL;
        $going = CompetitionParticipantGoing::sql('cp');

        if ($round->category === RoundCategory::Solo) {
            $sql = <<<SQL
SELECT
    'participant_round' AS kind,
    cpr.id,
    cpr.table_number,
    cpr.result_seconds,
    cpr.result_pieces_placed,
    cpr.result_did_not_start,
    cpr.result_puzzles::text AS result_puzzles,
    NULL::varchar AS team_name,
    json_build_array({$member})::text AS members
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND {$going}
LEFT JOIN player ON player.id = cp.player_id
WHERE cpr.round_id = :roundId
    {$this->only('cpr', $entryIds)}
SQL;
        } else {
            $sql = <<<SQL
SELECT
    'team' AS kind,
    ct.id,
    ct.table_number,
    ct.result_seconds,
    ct.result_pieces_placed,
    ct.result_did_not_start,
    ct.result_puzzles::text AS result_puzzles,
    ct.name AS team_name,
    (
        SELECT json_agg({$member} ORDER BY cp.name, cp.id)
        FROM competition_participant_round m
        INNER JOIN competition_participant cp ON cp.id = m.participant_id AND {$going}
        LEFT JOIN player ON player.id = cp.player_id
        WHERE m.team_id = ct.id
    )::text AS members
FROM competition_team ct
WHERE ct.round_id = :roundId
    {$this->only('ct', $entryIds)}
SQL;
        }

        $rows = $this->database->fetchAllAssociative(
            $sql,
            ['roundId' => $round->id, 'entryIds' => $entryIds ?? []],
            ['entryIds' => ArrayParameterType::STRING],
        );
        $entrants = [];

        foreach ($rows as $row) {
            /** @var array{kind: string, id: string, table_number: null|int, result_seconds: null|int, result_pieces_placed: null|int, result_did_not_start: bool, result_puzzles: null|string, team_name: null|string, members: null|string} $row */
            /** @var list<array{participant_id: string, name: string, country: null|string, player_id: null|string, player_code: null|string, player_country: null|string, player_avatar: null|string, player_is_private: null|bool, time_predictions_opted_out: bool, ranking_opted_out: bool}> $members */
            $members = $row['members'] !== null ? json_decode($row['members'], true, flags: JSON_THROW_ON_ERROR) : [];

            $entrants[] = new AnalyticsStudioEntrant(
                ref: $row['kind'] === 'team' ? RoundEntryRef::team($row['id']) : RoundEntryRef::participantRound($row['id']),
                teamName: $row['team_name'],
                tableNumber: $round->tableNumbersOff ? null : $row['table_number'],
                result: RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], $row['result_did_not_start']),
                puzzles: RoundEntryPuzzles::fromStored($row['result_puzzles']),
                members: array_map(
                    static function (array $member): AnalyticsStudioMember {
                        $isPrivate = $member['player_is_private'] === true;
                        $playerId = $member['player_id'] !== null && $isPrivate === false ? strtolower($member['player_id']) : null;

                        return new AnalyticsStudioMember(
                            participantId: strtolower($member['participant_id']),
                            entrant: OfficialEntrantsView::entrant(
                                $member['name'],
                                $member['country'],
                                $member['player_id'],
                                $member['player_code'],
                                $member['player_country'],
                                $member['player_avatar'],
                                $isPrivate,
                                null,
                            ),
                            publicPlayerId: $playerId,
                            playerCode: $playerId !== null && $member['player_code'] !== null ? strtoupper($member['player_code']) : null,
                            timePredictionsOptedOut: $member['time_predictions_opted_out'],
                            rankingOptedOut: $member['ranking_opted_out'],
                        );
                    },
                    $members,
                ),
            );
        }

        usort($entrants, static fn (AnalyticsStudioEntrant $a, AnalyticsStudioEntrant $b): int => strcasecmp($a->sortName(), $b->sortName())
            ?: strcmp($a->key(), $b->key()));

        return $entrants;
    }

    /**
     * @param null|list<string> $entryIds
     */
    private function only(string $alias, null|array $entryIds): string
    {
        return $entryIds === null ? '' : "AND {$alias}.id IN (:entryIds)";
    }
}
