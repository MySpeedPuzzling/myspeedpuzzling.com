<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\RoundResult;
use SpeedPuzzling\Web\Results\RoundResultPlayer;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\RoundResultStatus;
use SpeedPuzzling\Web\Value\SkillTier;

/**
 * Times puzzlers added for one competition round, per round puzzle, in result order. Deliberately without
 * positions: only puzzlers who added their time are here, so "1." would read as an official placing it is not.
 *
 * - The solving times linked to the round (puzzle_solving_time.competition_round_id, kept current by
 *   SolvingTimeRoundResolver + RoundResultsReconciler).
 * - One result per player (solo) or per group of puzzlers (duo/team): their EARLIEST time. There is no date
 *   check on the round link, so a later practice run on the same puzzle is linked too - and it is faster.
 * - Finished within the limit (by time), then unfinished (by pieces placed), then over the limit without
 *   pieces reported (by time).
 * - Private players are left out exactly like on the puzzle page: a solo result unless it is the viewer's
 *   own, a group only when every member is private and the viewer is not one of them.
 * - Players the viewer blocked are left out too: their solo result, and any group they took part in unless
 *   the viewer took part as well (docs/features/player-blocklist.md).
 */
readonly final class GetRoundResults
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    /**
     * @return array<string, list<RoundResult>> keyed by puzzle id
     */
    public function forRound(EditionRoundDetail $round, null|string $viewerPlayerId): array
    {
        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    pst.id AS time_id,
    pst.puzzle_id,
    pst.team,
    pst.seconds_to_solve,
    pst.pieces_placed,
    pst.finished_later_seconds,
    pst.finished_at,
    pst.tracked_at,
    p.pieces_count,
    owner.id AS owner_id,
    owner.name AS owner_name,
    owner.code AS owner_code,
    owner.country AS owner_country,
    owner.is_private AS owner_is_private,
    owner.ranking_opted_out AS owner_ranking_opted_out,
    ps.skill_tier AS owner_skill_tier
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON p.id = pst.puzzle_id
INNER JOIN player owner ON owner.id = pst.player_id
LEFT JOIN player_skill ps ON ps.player_id = owner.id AND ps.pieces_count = p.pieces_count
WHERE pst.competition_round_id = :roundId
    AND pst.suspicious = false
    AND (pst.seconds_to_solve IS NOT NULL OR pst.pieces_placed IS NOT NULL)
ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at, pst.id
SQL,
            ['roundId' => $round->id],
        )->fetchAllAssociative();

        /** @var list<array{time_id: string, puzzle_id: string, team: null|string, seconds_to_solve: null|int|string, pieces_placed: null|int|string, finished_later_seconds: null|int|string, finished_at: null|string, tracked_at: string, pieces_count: int|string, owner_id: string, owner_name: null|string, owner_code: string, owner_country: null|string, owner_is_private: bool, owner_ranking_opted_out: bool, owner_skill_tier: null|int|string}> $rows */

        $members = $this->loadGroupMembers($rows);
        $limitSeconds = $round->minutesLimit * 60;

        /** @var array<string, array<string, true>> $seenPerPuzzle */
        $seenPerPuzzle = [];
        /** @var array<string, list<array{players: non-empty-list<RoundResultPlayer>, row: array{time_id: string, puzzle_id: string, seconds_to_solve: null|int|string, pieces_placed: null|int|string, finished_later_seconds: null|int|string, finished_at: null|string, ...}, status: RoundResultStatus}>> $candidates */
        $candidates = [];

        foreach ($rows as $row) {
            $piecesCount = (int) $row['pieces_count'];
            $players = $this->players($row, $members, $piecesCount);
            $identification = $this->identification($row, $players);

            // Rows come earliest first, so the first one per player/group is their result
            if (isset($seenPerPuzzle[$row['puzzle_id']][$identification])) {
                continue;
            }
            $seenPerPuzzle[$row['puzzle_id']][$identification] = true;

            if ($this->isHiddenFrom($players, $viewerPlayerId)) {
                continue;
            }

            $seconds = $row['seconds_to_solve'] !== null ? (int) $row['seconds_to_solve'] : null;

            $status = match (true) {
                $row['pieces_placed'] !== null => RoundResultStatus::Unfinished,
                $seconds !== null && $seconds <= $limitSeconds => RoundResultStatus::Finished,
                default => RoundResultStatus::OverLimit,
            };

            $candidates[$row['puzzle_id']][] = ['players' => $players, 'row' => $row, 'status' => $status];
        }

        $results = [];

        foreach ($candidates as $puzzleId => $puzzleCandidates) {
            $results[$puzzleId] = $this->order($puzzleCandidates);
        }

        return $results;
    }

    /**
     * @param list<array{players: non-empty-list<RoundResultPlayer>, row: array{time_id: string, puzzle_id: string, seconds_to_solve: null|int|string, pieces_placed: null|int|string, finished_later_seconds: null|int|string, finished_at: null|string, ...}, status: RoundResultStatus}> $candidates
     * @return list<RoundResult>
     */
    private function order(array $candidates): array
    {
        $statusOrder = [
            RoundResultStatus::Finished->value => 0,
            RoundResultStatus::Unfinished->value => 1,
            RoundResultStatus::OverLimit->value => 2,
        ];

        // usort is stable, so equal results keep the earliest-first order
        usort($candidates, static function (array $a, array $b) use ($statusOrder): int {
            $byStatus = $statusOrder[$a['status']->value] <=> $statusOrder[$b['status']->value];

            if ($byStatus !== 0) {
                return $byStatus;
            }

            if ($a['status'] === RoundResultStatus::Unfinished) {
                return (int) $b['row']['pieces_placed'] <=> (int) $a['row']['pieces_placed'];
            }

            return (int) $a['row']['seconds_to_solve'] <=> (int) $b['row']['seconds_to_solve'];
        });

        $ordered = [];

        foreach ($candidates as $candidate) {
            $row = $candidate['row'];

            $ordered[] = new RoundResult(
                timeId: $row['time_id'],
                puzzleId: $row['puzzle_id'],
                players: $candidate['players'],
                status: $candidate['status'],
                seconds: $row['seconds_to_solve'] !== null ? (int) $row['seconds_to_solve'] : null,
                piecesPlaced: $row['pieces_placed'] !== null ? (int) $row['pieces_placed'] : null,
                finishedLaterSeconds: $row['finished_later_seconds'] !== null ? (int) $row['finished_later_seconds'] : null,
                finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at']) : null,
            );
        }

        return $ordered;
    }

    /**
     * @param list<array{team: null|string, ...}> $rows
     * @return array<string, array{name: null|string, code: string, country: null|string, is_private: bool, ranking_opted_out: bool, skill_tiers: array<int, int>}>
     */
    private function loadGroupMembers(array $rows): array
    {
        $memberIds = [];

        foreach ($rows as $row) {
            foreach ($this->decodeTeam($row['team']) as $puzzler) {
                if ($puzzler['player_id'] !== null) {
                    $memberIds[$puzzler['player_id']] = true;
                }
            }
        }

        if ($memberIds === []) {
            return [];
        }

        $memberRows = $this->database->executeQuery(
            <<<SQL
SELECT player.id, player.name, player.code, player.country, player.is_private, player.ranking_opted_out, ps.pieces_count, ps.skill_tier
FROM player
LEFT JOIN player_skill ps ON ps.player_id = player.id
WHERE player.id IN (:ids)
SQL,
            ['ids' => array_keys($memberIds)],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $members = [];

        foreach ($memberRows as $memberRow) {
            /** @var array{id: string, name: null|string, code: string, country: null|string, is_private: bool, ranking_opted_out: bool, pieces_count: null|int|string, skill_tier: null|int|string} $memberRow */
            $members[$memberRow['id']] ??= [
                'name' => $memberRow['name'],
                'code' => $memberRow['code'],
                'country' => $memberRow['country'],
                'is_private' => $memberRow['is_private'],
                'ranking_opted_out' => $memberRow['ranking_opted_out'],
                'skill_tiers' => [],
            ];

            if ($memberRow['pieces_count'] !== null && $memberRow['skill_tier'] !== null) {
                $members[$memberRow['id']]['skill_tiers'][(int) $memberRow['pieces_count']] = (int) $memberRow['skill_tier'];
            }
        }

        return $members;
    }

    /**
     * @param array{team: null|string, owner_id: string, owner_name: null|string, owner_code: string, owner_country: null|string, owner_is_private: bool, owner_ranking_opted_out: bool, owner_skill_tier: null|int|string, ...} $row
     * @param array<string, array{name: null|string, code: string, country: null|string, is_private: bool, ranking_opted_out: bool, skill_tiers: array<int, int>}> $members
     * @return non-empty-list<RoundResultPlayer>
     */
    private function players(array $row, array $members, int $piecesCount): array
    {
        $team = $this->decodeTeam($row['team']);

        if ($team === []) {
            return [new RoundResultPlayer(
                playerId: $row['owner_id'],
                playerName: $row['owner_name'],
                playerCode: strtoupper($row['owner_code']),
                playerCountry: CountryCode::fromCode($row['owner_country']),
                isPrivate: $row['owner_is_private'],
                skillTierName: $this->skillTierName($row['owner_skill_tier']),
                rankingOptedOut: $row['owner_ranking_opted_out'],
            )];
        }

        $players = [];

        foreach ($team as $puzzler) {
            $member = $puzzler['player_id'] !== null ? ($members[$puzzler['player_id']] ?? null) : null;

            if ($member === null) {
                $players[] = new RoundResultPlayer(
                    playerId: null,
                    playerName: $puzzler['player_name'],
                    playerCode: null,
                    playerCountry: null,
                    isPrivate: false,
                    skillTierName: null,
                    rankingOptedOut: false,
                );

                continue;
            }

            $players[] = new RoundResultPlayer(
                playerId: $puzzler['player_id'],
                playerName: $member['name'] ?? $puzzler['player_name'],
                playerCode: strtoupper($member['code']),
                playerCountry: CountryCode::fromCode($member['country']),
                isPrivate: $member['is_private'],
                skillTierName: $this->skillTierName($member['skill_tiers'][$piecesCount] ?? null),
                rankingOptedOut: $member['ranking_opted_out'],
            );
        }

        return $players;
    }

    /**
     * Same identity the puzzle page groups by: the player, or the sorted set of puzzlers of a group.
     *
     * @param array{team: null|string, owner_id: string, ...} $row
     * @param non-empty-list<RoundResultPlayer> $players
     */
    private function identification(array $row, array $players): string
    {
        if ($row['team'] === null) {
            return $row['owner_id'];
        }

        $identifiers = array_map(
            static fn (RoundResultPlayer $player): string => $player->playerId ?? $player->playerCode ?? $player->playerName ?? '',
            $players,
        );
        sort($identifiers);

        return implode('.', $identifiers);
    }

    /**
     * @param non-empty-list<RoundResultPlayer> $players
     */
    private function isHiddenFrom(array $players, null|string $viewerPlayerId): bool
    {
        if ($this->hasBlockedPlayer($players, $viewerPlayerId)) {
            return true;
        }

        foreach ($players as $player) {
            if ($player->isPrivate === false || ($viewerPlayerId !== null && $player->playerId === $viewerPlayerId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param non-empty-list<RoundResultPlayer> $players
     */
    private function hasBlockedPlayer(array $players, null|string $viewerPlayerId): bool
    {
        $hasBlockedPlayer = false;

        foreach ($players as $player) {
            if ($viewerPlayerId !== null && $player->playerId === $viewerPlayerId) {
                return false;
            }

            if ($this->hiddenPlayers->isHidden($player->playerId)) {
                $hasBlockedPlayer = true;
            }
        }

        return $hasBlockedPlayer;
    }

    private function skillTierName(null|int|string $tier): null|string
    {
        if ($tier === null) {
            return null;
        }

        return strtolower(SkillTier::from((int) $tier)->name);
    }

    /**
     * @return list<array{player_id: null|string, player_name: null|string}>
     */
    private function decodeTeam(null|string $team): array
    {
        if ($team === null) {
            return [];
        }

        /** @var null|array{puzzlers?: list<array{player_id: null|string, player_name: null|string}>} $decoded */
        $decoded = json_decode($team, true);

        return $decoded['puzzlers'] ?? [];
    }
}
