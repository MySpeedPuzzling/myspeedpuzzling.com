<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PlayerIdentification;

/**
 * The allow list of a private profile, as its owner manages it - see
 * docs/features/private-profile-allow-list.md. What a *viewer* is shown is never decided here but
 * in PrivateProfileAccess.
 */
readonly final class GetPrivateProfileViewers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * For the owner's own settings, newest first. An admin-imposed block is invisible to the owner
     * everywhere else, so it does not thin out this list either.
     *
     * @return list<PlayerIdentification>
     */
    public function ofOwner(string $ownerId): array
    {
        if (Uuid::isValid($ownerId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT
    viewer.id AS player_id,
    viewer.code AS player_code,
    viewer.name AS player_name,
    viewer.country AS player_country,
    viewer.avatar AS player_avatar
FROM private_profile_viewer
INNER JOIN player viewer ON viewer.id = private_profile_viewer.viewer_id
WHERE private_profile_viewer.owner_id = :ownerId
ORDER BY private_profile_viewer.added_at DESC, viewer.code
SQL;

        $rows = $this->database->executeQuery($query, ['ownerId' => $ownerId])->fetchAllAssociative();

        return array_map(static function (array $row): PlayerIdentification {
            /**
             * @var array{
             *     player_id: string,
             *     player_code: string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     player_avatar: null|string,
             * } $row
             */

            return PlayerIdentification::fromDatabaseRow($row);
        }, $rows);
    }

    /**
     * Write-side fan-out (no viewer there): who follows this private player AND is on their allow
     * list. The owner's own blocks outrank the list; the follower's blocks are the caller's business.
     *
     * @return list<string>
     */
    public function followersAllowedBy(string $ownerId): array
    {
        if (Uuid::isValid($ownerId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT private_profile_viewer.viewer_id
FROM private_profile_viewer
INNER JOIN player viewer ON viewer.id = private_profile_viewer.viewer_id
WHERE private_profile_viewer.owner_id = :ownerId
    AND viewer.favorite_players::jsonb @> jsonb_build_array(:ownerId::text)
    AND NOT EXISTS (
        SELECT 1 FROM user_block
        WHERE user_block.blocker_id = private_profile_viewer.owner_id
            AND user_block.blocked_id = private_profile_viewer.viewer_id
    )
SQL;

        /** @var list<string> $viewerIds */
        $viewerIds = $this->database->executeQuery($query, ['ownerId' => $ownerId])->fetchFirstColumn();

        return $viewerIds;
    }
}
