<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ModerationActionView;
use SpeedPuzzling\Web\Value\ModerationActionType;

readonly final class GetModerationActions
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<ModerationActionView>
     */
    public function forPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT
    ma.id AS action_id,
    ma.action_type,
    tp.name AS target_player_name,
    ma.target_player_id,
    ap.name AS admin_name,
    ma.reason,
    ma.performed_at,
    ma.expires_at
FROM moderation_action ma
JOIN player tp ON ma.target_player_id = tp.id
JOIN player ap ON ma.admin_id = ap.id
WHERE ma.target_player_id = :playerId
ORDER BY ma.performed_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return array_map(static fn(array $row): ModerationActionView => self::mapToView($row), $data);
    }

    public function activeMute(string $playerId): null|ModerationActionView
    {
        $query = <<<SQL
SELECT
    ma.id AS action_id,
    ma.action_type,
    tp.name AS target_player_name,
    ma.target_player_id,
    ap.name AS admin_name,
    ma.reason,
    ma.performed_at,
    ma.expires_at
FROM moderation_action ma
JOIN player tp ON ma.target_player_id = tp.id
JOIN player ap ON ma.admin_id = ap.id
WHERE ma.target_player_id = :playerId
    AND ma.action_type = :actionType
    AND (ma.expires_at IS NULL OR ma.expires_at > NOW())
ORDER BY ma.performed_at DESC
LIMIT 1
SQL;

        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'actionType' => ModerationActionType::TemporaryMute->value,
            ])
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return self::mapToView($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function mapToView(array $row): ModerationActionView
    {
        /** @var array{
         *     action_id: string,
         *     action_type: string,
         *     target_player_name: null|string,
         *     target_player_id: string,
         *     admin_name: null|string,
         *     reason: null|string,
         *     performed_at: string,
         *     expires_at: null|string,
         * } $row
         */

        return new ModerationActionView(
            actionId: $row['action_id'],
            actionType: ModerationActionType::from($row['action_type']),
            targetPlayerName: $row['target_player_name'] ?? 'Unknown',
            targetPlayerId: $row['target_player_id'],
            adminName: $row['admin_name'] ?? 'Unknown',
            reason: $row['reason'],
            performedAt: new DateTimeImmutable($row['performed_at']),
            expiresAt: $row['expires_at'] !== null ? new DateTimeImmutable($row['expires_at']) : null,
        );
    }
}
