<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ModeratorInvitationRow;

readonly final class GetModeratorInvitations
{
    public const int LIMIT = 50;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * For /admin/moderators: invitations not (yet) turned into a moderator - open ones first, then the declined and
     * withdrawn ones, newest first. Accepted ones are the moderators list (their "Agreed" column).
     *
     * @return list<ModeratorInvitationRow>
     */
    public function notAccepted(): array
    {
        $query = <<<SQL
SELECT
    moderator_invitation.id AS invitation_id,
    player.id AS player_id,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country,
    invited_by.name AS invited_by_name,
    moderator_invitation.invited_at,
    moderator_invitation.answered_at,
    moderator_invitation.withdrawn_at
FROM moderator_invitation
INNER JOIN player ON player.id = moderator_invitation.player_id
LEFT JOIN player invited_by ON invited_by.id = moderator_invitation.invited_by_id
WHERE moderator_invitation.accepted IS DISTINCT FROM true
ORDER BY
    (moderator_invitation.answered_at IS NULL AND moderator_invitation.withdrawn_at IS NULL) DESC,
    moderator_invitation.invited_at DESC
LIMIT :limit
SQL;

        $rows = $this->database->fetchAllAssociative($query, ['limit' => self::LIMIT]);

        return array_map(static function (array $row): ModeratorInvitationRow {
            /** @var array{invitation_id: string, player_id: string, player_name: null|string, player_code: string, player_country: null|string, invited_by_name: null|string, invited_at: string, answered_at: null|string, withdrawn_at: null|string} $row */
            return ModeratorInvitationRow::fromDatabaseRow($row);
        }, $rows);
    }

    /**
     * The guide page's question: may this player read it, and is there a pledge to take? (one indexed lookup)
     */
    public function hasPending(string $playerId): bool
    {
        $query = <<<SQL
SELECT 1
FROM moderator_invitation
WHERE player_id = :playerId AND answered_at IS NULL AND withdrawn_at IS NULL
LIMIT 1
SQL;

        return $this->database->fetchOne($query, ['playerId' => $playerId]) !== false;
    }
}
