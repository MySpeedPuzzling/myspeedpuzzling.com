<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\CoPuzzlerSuggestions;
use SpeedPuzzling\Web\Results\PersonSuggestion;
use SpeedPuzzling\Web\Results\TeamSuggestion;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class GetCoPuzzlers
{
    /**
     * A shared time weighs 1 today, a half after this many days, a third after twice as many… -
     * last month's partner outranks the one from thirty times three years ago, who still never
     * drops out of sight.
     */
    private const int SCORE_HALF_LIFE_DAYS = 60;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
        private GetFavoritePlayers $getFavoritePlayers,
    ) {
    }

    public function forPlayer(string $playerId): CoPuzzlerSuggestions
    {
        // A pair/team a hidden player is part of is not offered at all - the times themselves stay
        // in the viewer's history (HiddenPlayers::sqlExcludeTeam), being reminded of them is another thing
        $notHidden = $this->hiddenPlayers->sqlExclude('hidden_member.player_id');
        $noHiddenMember = $notHidden === ''
            ? ''
            : " AND NOT EXISTS (SELECT 1 FROM puzzling_team_member hidden_member WHERE hidden_member.team_id = team.id AND NOT (TRUE{$notHidden}))";

        // A private co-puzzler is listed by the player code the viewer once entered, nothing more
        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('player');
        $halfLife = self::SCORE_HALF_LIFE_DAYS;

        $query = <<<SQL
SELECT
    team.id AS team_id,
    team.name AS team_name,
    team.size AS team_size,
    stats.times_count,
    stats.last_together_at,
    stats.score,
    member.member_key,
    member.player_id,
    member.guest_name,
    player.code AS player_code,
    CASE WHEN {$isPrivate} THEN NULL ELSE player.name END AS player_name,
    CASE WHEN {$isPrivate} THEN NULL ELSE player.country END AS player_country,
    CASE WHEN {$isPrivate} THEN NULL ELSE player.avatar END AS player_avatar
FROM puzzling_team_member me
INNER JOIN puzzling_team team ON team.id = me.team_id
INNER JOIN LATERAL (
    SELECT
        COUNT(*) AS times_count,
        MAX(COALESCE(finished_at, tracked_at)) AS last_together_at,
        COALESCE(SUM(1.0 / (1 + GREATEST(0, EXTRACT(EPOCH FROM (CAST(:now AS TIMESTAMP) - COALESCE(finished_at, tracked_at))) / 86400.0) / {$halfLife})), 0) AS score
    FROM puzzle_solving_time
    WHERE puzzling_team_id = team.id
) stats ON TRUE
INNER JOIN puzzling_team_member member ON member.team_id = team.id AND member.id <> me.id
LEFT JOIN player ON player.id = member.player_id
WHERE me.player_id = :playerId
    AND (stats.times_count > 0 OR team.name IS NOT NULL OR team.prepared_by_id IS NOT NULL)
    {$noHiddenMember}
ORDER BY stats.score DESC, team.id, member.position
SQL;

        /**
         * @var list<array{
         *     team_id: string,
         *     team_name: null|string,
         *     team_size: int,
         *     times_count: int,
         *     last_together_at: null|string,
         *     score: float|string,
         *     member_key: string,
         *     player_id: null|string,
         *     guest_name: null|string,
         *     player_code: null|string,
         *     player_name: null|string,
         *     player_country: null|string,
         *     player_avatar: null|string,
         * }> $rows
         */
        $rows = $this->database->fetchAllAssociative($query, [
            'playerId' => $playerId,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        /** @var array<string, array{name: null|string, size: int, count: int, last: null|string, score: float, members: list<string>}> $teams */
        $teams = [];
        /** @var array<string, array{value: string, label: string, playerId: null|string, code: null|string, country: null|string, avatar: null|string, count: int, pairCount: int, last: null|string, score: float, pairScore: float}> $people */
        $people = [];

        foreach ($rows as $row) {
            $teamId = $row['team_id'];
            $score = (float) $row['score'];
            $key = $row['member_key'];

            $teams[$teamId] ??= [
                'name' => $row['team_name'],
                'size' => $row['team_size'],
                'count' => $row['times_count'],
                'last' => $row['last_together_at'],
                'score' => $score,
                'members' => [],
            ];
            $teams[$teamId]['members'][] = $key;

            $people[$key] ??= [
                'value' => $row['player_code'] !== null ? '#' . strtoupper($row['player_code']) : (string) $row['guest_name'],
                'label' => $row['player_name'] ?? ($row['player_code'] !== null ? '#' . strtoupper($row['player_code']) : (string) $row['guest_name']),
                'playerId' => $row['player_id'],
                'code' => $row['player_code'] !== null ? strtoupper($row['player_code']) : null,
                'country' => $row['player_country'],
                'avatar' => $row['player_avatar'],
                'count' => 0,
                'pairCount' => 0,
                'last' => null,
                'score' => 0.0,
                'pairScore' => 0.0,
            ];

            $people[$key]['count'] += $row['times_count'];
            $people[$key]['score'] += $score;
            $people[$key]['last'] = max($people[$key]['last'], $row['last_together_at']);

            if ($row['team_size'] === 2) {
                $people[$key]['pairCount'] += $row['times_count'];
                $people[$key]['pairScore'] += $score;
            }
        }

        $favoriteIds = [];

        foreach ($this->getFavoritePlayers->forPlayerId($playerId) as $favorite) {
            $favoriteIds[$favorite->playerId] = true;

            // The member key of a player is their id
            $people[$favorite->playerId] ??= [
                'value' => '#' . strtoupper($favorite->playerCode),
                'label' => $favorite->playerName ?? '#' . strtoupper($favorite->playerCode),
                'playerId' => $favorite->playerId,
                'code' => strtoupper($favorite->playerCode),
                'country' => $favorite->playerCountry?->name,
                'avatar' => $favorite->playerAvatar,
                'count' => 0,
                'pairCount' => 0,
                'last' => null,
                'score' => 0.0,
                'pairScore' => 0.0,
            ];
        }

        uasort($people, static fn(array $a, array $b): int => [$b['score'], $b['count']] <=> [$a['score'], $a['count']]);

        $teamSuggestions = [];

        foreach ($teams as $teamId => $team) {
            $teamSuggestions[] = new TeamSuggestion(
                teamId: $teamId,
                name: $team['name'],
                size: $team['size'],
                timesCount: $team['count'],
                lastTogetherAt: $team['last'] !== null ? new DateTimeImmutable($team['last']) : null,
                score: $team['score'],
                memberKeys: $team['members'],
            );
        }

        $personSuggestions = [];

        foreach ($people as $key => $person) {
            $personSuggestions[] = new PersonSuggestion(
                key: $key,
                value: $person['value'],
                label: $person['label'],
                playerId: $person['playerId'],
                playerCode: $person['code'],
                country: CountryCode::fromCode($person['country']),
                avatar: $person['avatar'],
                timesCount: $person['count'],
                pairTimesCount: $person['pairCount'],
                lastTogetherAt: $person['last'] !== null ? new DateTimeImmutable($person['last']) : null,
                score: $person['score'],
                pairScore: $person['pairScore'],
                isFavorite: $person['playerId'] !== null && isset($favoriteIds[$person['playerId']]),
            );
        }

        return new CoPuzzlerSuggestions($teamSuggestions, $personSuggestions);
    }
}
