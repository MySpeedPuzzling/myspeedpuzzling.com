<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\EventClaimBlock;
use SpeedPuzzling\Web\Results\EventClaimBlockRemoval;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultClaimAction;

/**
 * The admin page "Claim blocks" (docs/features/event-results-import/README.md "Gates"): every player blocked from
 * claiming spots of imported event results and the removals that got them there. Admin only - everyone is shown, like
 * the rest of /admin. Reads the log's copied ids only, never the results themselves.
 */
readonly final class GetEventClaimBlocks
{
    /**
     * Removals listed per player - the newest ones.
     */
    public const int REMOVALS_SHOWN = 10;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<EventClaimBlock> the most recently blocked first
     */
    public function all(): array
    {
        /** @var list<array{id: string, name: null|string, code: string, event_claims_blocked_at: string}> $players */
        $players = $this->database->fetchAllAssociative(<<<SQL
SELECT player.id, player.name, player.code, player.event_claims_blocked_at
FROM player
WHERE player.event_claims_blocked_at IS NOT NULL
ORDER BY player.event_claims_blocked_at DESC, player.id
SQL);

        if ($players === []) {
            return [];
        }

        /** @var list<array{player_id: string, solving_time_id: string, at: string, by_player_id: null|string, by_player_name: null|string, by_player_code: null|string, reason: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT removal.player_id, removal.solving_time_id, removal.at, removal.by_player_id, removal.by_player_name, removal.by_player_code, removal.reason
FROM (
    SELECT
        log.player_id,
        log.solving_time_id,
        log.at,
        log.by_player_id,
        by_player.name AS by_player_name,
        by_player.code AS by_player_code,
        log.reason,
        ROW_NUMBER() OVER (PARTITION BY log.player_id ORDER BY log.at DESC, log.id DESC) AS position
    FROM result_claim_log log
    LEFT JOIN player by_player ON by_player.id = log.by_player_id
    WHERE log.player_id IN (:playerIds)
        AND log.action = :removed
) removal
WHERE removal.position <= :shown
ORDER BY removal.player_id, removal.at DESC
SQL, [
            'playerIds' => array_column($players, 'id'),
            'removed' => ResultClaimAction::Removed->value,
            'shown' => self::REMOVALS_SHOWN,
        ], [
            'playerIds' => ArrayParameterType::STRING,
        ]);

        $removals = [];

        foreach ($rows as $row) {
            $removals[$row['player_id']][] = new EventClaimBlockRemoval(
                solvingTimeId: $row['solving_time_id'],
                at: new DateTimeImmutable($row['at']),
                byPlayerId: $row['by_player_id'],
                byPlayerName: $row['by_player_name'] ?? ($row['by_player_code'] !== null ? '#' . strtoupper($row['by_player_code']) : null),
                reason: $row['reason'],
            );
        }

        return array_map(static fn (array $player): EventClaimBlock => new EventClaimBlock(
            playerId: $player['id'],
            playerName: $player['name'],
            playerCode: strtoupper($player['code']),
            blockedAt: new DateTimeImmutable($player['event_claims_blocked_at']),
            removals: $removals[$player['id']] ?? [],
        ), $players);
    }
}
