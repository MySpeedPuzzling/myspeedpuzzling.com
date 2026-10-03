<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

/**
 * May this player put that subject into their comparison line-up (docs/features/player-comparison.md, Visibility)?
 *
 * For the write side: everything is decided for an explicit owner id in one statement, never through the ambient
 * HiddenPlayers / PrivateProfileAccess - those follow the security token and see nobody under async or console.
 *
 * Blocks count one way only, like everywhere else (docs/features/player-blocklist.md): a `user_block` row means the
 * blocker must not see the blocked player. Being blocked BY somebody changes nothing here - the blocked side must never
 * be able to tell.
 *
 * - Player: one the owner blocked → no; private → only when revealed to the owner (allow list, and - as in
 *   PrivateProfileAccess::sqlRevealedIdsOf() - no block between the two in either direction). The owner always.
 * - Pair/team: a registered member the owner blocked → no, even when the owner is in it (GetCoPuzzlers' hidden_member);
 *   a member of it → yes; otherwise at least one registered member must be visible to the owner (public, or revealed).
 *   A team of guests only is nobody's subject.
 */
readonly final class ComparisonSubjectVisibility
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Which line-up the subject belongs to - null when it does not exist for the owner.
     */
    public function availableKind(string $ownerId, ComparisonSubjectRef $ref): null|ComparisonKind
    {
        if (Uuid::isValid($ownerId) === false || Uuid::isValid($ref->id) === false) {
            return null;
        }

        if ($ref->isPlayer()) {
            return $this->isPlayerAvailable(strtolower($ownerId), $ref->id) ? ComparisonKind::Solo : null;
        }

        return $this->availableTeamKind(strtolower($ownerId), $ref->id);
    }

    private function isPlayerAvailable(string $ownerId, string $playerId): bool
    {
        /** @var false|array{is_private: bool, blocked: bool, revealed: bool} $row */
        $row = $this->connection->fetchAssociative(
            <<<SQL
SELECT
    subject.is_private,
    EXISTS (
        SELECT 1 FROM user_block
        WHERE user_block.blocker_id = :ownerId AND user_block.blocked_id = subject.id
    ) AS blocked,
    EXISTS (
        SELECT 1 FROM private_profile_viewer
        WHERE private_profile_viewer.owner_id = subject.id AND private_profile_viewer.viewer_id = :ownerId
            AND NOT EXISTS (
                SELECT 1 FROM user_block
                WHERE (user_block.blocker_id = subject.id AND user_block.blocked_id = :ownerId)
                    OR (user_block.blocker_id = :ownerId AND user_block.blocked_id = subject.id)
            )
    ) AS revealed
FROM player subject
WHERE subject.id = :playerId
SQL,
            ['ownerId' => $ownerId, 'playerId' => $playerId],
        );

        if ($row === false) {
            return false;
        }

        if ($playerId === $ownerId) {
            return true;
        }

        return $row['blocked'] === false && ($row['is_private'] === false || $row['revealed'] === true);
    }

    private function availableTeamKind(string $ownerId, string $teamId): null|ComparisonKind
    {
        /** @var list<array{size: int, player_id: null|string, is_private: null|bool, blocked: null|bool, revealed: null|bool}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
SELECT
    team.size,
    member.player_id,
    member_player.is_private,
    EXISTS (
        SELECT 1 FROM user_block
        WHERE user_block.blocker_id = :ownerId AND user_block.blocked_id = member.player_id
    ) AS blocked,
    EXISTS (
        SELECT 1 FROM private_profile_viewer
        WHERE private_profile_viewer.owner_id = member.player_id AND private_profile_viewer.viewer_id = :ownerId
            AND NOT EXISTS (
                SELECT 1 FROM user_block
                WHERE (user_block.blocker_id = member.player_id AND user_block.blocked_id = :ownerId)
                    OR (user_block.blocker_id = :ownerId AND user_block.blocked_id = member.player_id)
            )
    ) AS revealed
FROM puzzling_team team
LEFT JOIN puzzling_team_member member ON member.team_id = team.id AND member.player_id IS NOT NULL
LEFT JOIN player member_player ON member_player.id = member.player_id
WHERE team.id = :teamId
SQL,
            ['ownerId' => $ownerId, 'teamId' => $teamId],
        );

        if ($rows === []) {
            return null;
        }

        $ownerIsMember = false;
        $somebodyVisible = false;

        foreach ($rows as $row) {
            if ($row['player_id'] === null) {
                continue;
            }

            if ($row['blocked'] === true) {
                return null;
            }

            if ($row['player_id'] === $ownerId) {
                $ownerIsMember = true;
            }

            if ($row['is_private'] === false || $row['revealed'] === true) {
                $somebodyVisible = true;
            }
        }

        if ($ownerIsMember === false && $somebodyVisible === false) {
            return null;
        }

        return ComparisonKind::forTeamSize((int) $rows[0]['size']);
    }
}
