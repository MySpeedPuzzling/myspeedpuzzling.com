<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\TeamComposition;

/**
 * Changes who a member of existing pairs/teams is, keeping every team's composition key truthful.
 *
 * A team is the exact set of its people, so a changed member can turn a team into one that already
 * exists - the two are then merged: times move to the surviving team, which inherits the name when it
 * has none of its own.
 *
 * Plain DBAL on purpose: statements run immediately inside the handler's transaction, so nothing here
 * depends on the order the unit of work flushes in.
 */
readonly final class PuzzlingTeamMemberConversion
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * The player is about to disappear (account deletion): wherever they were a member they stay as a
     * guest of that name, exactly like in the solving times' own group snapshot.
     */
    public function playerToGuest(string $playerId, string $guestName): void
    {
        /** @var list<string> $teamIds */
        $teamIds = $this->connection->fetchFirstColumn(
            'SELECT team_id FROM puzzling_team_member WHERE player_id = :playerId',
            ['playerId' => $playerId],
        );

        foreach ($teamIds as $teamId) {
            $this->convertMember($teamId, $playerId, $guestName);
        }
    }

    /**
     * A guest's name is fixed ("Granma" -> "Grandma") in every pair/team the player shares with them, and
     * in the group snapshots of those results. Teams that only differed by the spelling become one.
     *
     * @return int how many pairs/teams were changed
     */
    public function renameGuest(string $memberPlayerId, string $guestKey, string $newName): int
    {
        $newName = trim((string) preg_replace('/\s+/u', ' ', $newName));

        if ($newName === '') {
            return 0;
        }

        $changed = 0;

        foreach ($this->teamsSharedWithGuest($memberPlayerId, $guestKey) as $teamId) {
            $changed += (int) $this->replaceGuest($teamId, $guestKey, TeamComposition::guestMemberKey($newName), null, $newName);
        }

        return $changed;
    }

    /**
     * The guest turns out to be a registered player (who agreed - see GuestLinkRequest): in every pair/team
     * the requesting player shares with that guest, the guest becomes the player, and those results become
     * part of the player's own history.
     *
     * @return int how many pairs/teams were changed
     */
    public function guestToPlayer(string $memberPlayerId, string $guestKey, string $targetPlayerId): int
    {
        $changed = 0;

        foreach ($this->teamsSharedWithGuest($memberPlayerId, $guestKey) as $teamId) {
            $changed += (int) $this->replaceGuest($teamId, $guestKey, strtolower($targetPlayerId), $targetPlayerId, null);
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    private function teamsSharedWithGuest(string $memberPlayerId, string $guestKey): array
    {
        /** @var list<string> $teamIds */
        $teamIds = $this->connection->fetchFirstColumn(
            <<<SQL
SELECT guest.team_id
FROM puzzling_team_member guest
INNER JOIN puzzling_team_member me ON me.team_id = guest.team_id AND me.player_id = :playerId
WHERE guest.member_key = :guestKey AND guest.player_id IS NULL
SQL,
            ['playerId' => $memberPlayerId, 'guestKey' => $guestKey],
        );

        return $teamIds;
    }

    /**
     * @return bool false when nothing was done: the new identity is already somebody else in this team
     */
    private function replaceGuest(string $teamId, string $oldKey, string $newKey, null|string $newPlayerId, null|string $newGuestName): bool
    {
        /** @var list<string> $otherMemberKeys */
        $otherMemberKeys = $this->connection->fetchFirstColumn(
            'SELECT member_key FROM puzzling_team_member WHERE team_id = :teamId AND member_key <> :oldKey',
            ['teamId' => $teamId, 'oldKey' => $oldKey],
        );

        // One person cannot be in a team twice - whoever that is, they are another person here
        if ($newKey !== $oldKey && in_array($newKey, $otherMemberKeys, true)) {
            return false;
        }

        // The results first: after a merge they belong to another team
        $this->rewriteSnapshots($teamId, $oldKey, $newPlayerId, $newGuestName);

        if ($newKey === $oldKey) {
            // Only the spelling changed ("grandma" -> "Grandma")
            $this->connection->executeStatement(
                'UPDATE puzzling_team_member SET guest_name = :guestName WHERE team_id = :teamId AND member_key = :oldKey',
                ['guestName' => $newGuestName, 'teamId' => $teamId, 'oldKey' => $oldKey],
            );

            return true;
        }

        $newCompositionKey = TeamComposition::keyFromMemberKeys([...$otherMemberKeys, $newKey]);

        $survivingTeamId = $this->connection->fetchOne(
            'SELECT id FROM puzzling_team WHERE composition_key = :key AND id <> :teamId',
            ['key' => $newCompositionKey, 'teamId' => $teamId],
        );

        if (is_string($survivingTeamId)) {
            $this->mergeInto($teamId, $survivingTeamId);

            return true;
        }

        $this->connection->executeStatement(
            'UPDATE puzzling_team_member SET player_id = :playerId, guest_name = :guestName, member_key = :newKey WHERE team_id = :teamId AND member_key = :oldKey',
            ['playerId' => $newPlayerId, 'guestName' => $newGuestName, 'newKey' => $newKey, 'teamId' => $teamId, 'oldKey' => $oldKey],
        );

        $this->connection->executeStatement(
            'UPDATE puzzling_team SET composition_key = :key WHERE id = :teamId',
            ['key' => $newCompositionKey, 'teamId' => $teamId],
        );

        return true;
    }

    /**
     * The group snapshot of every result of the team says the same as the team does.
     */
    private function rewriteSnapshots(string $teamId, string $guestKey, null|string $newPlayerId, null|string $newGuestName): void
    {
        // "g:jana#2" is the second guest of that name in the group
        $baseKey = (string) preg_replace('/#\d+$/', '', $guestKey);
        $occurrence = preg_match('/#(\d+)$/', $guestKey, $matches) === 1 ? (int) $matches[1] : 1;

        /** @var list<array{id: string, team: string}> $times */
        $times = $this->connection->fetchAllAssociative(
            'SELECT id, team FROM puzzle_solving_time WHERE puzzling_team_id = :teamId AND team IS NOT NULL',
            ['teamId' => $teamId],
        );

        foreach ($times as $time) {
            /** @var array{team_id?: null|string, puzzlers?: list<array{player_id?: null|string, player_name?: null|string}>} $snapshot */
            $snapshot = json_decode($time['team'], true, flags: JSON_THROW_ON_ERROR);
            $seen = 0;
            $changed = false;

            foreach ($snapshot['puzzlers'] ?? [] as $index => $puzzler) {
                if (($puzzler['player_id'] ?? null) !== null || TeamComposition::guestMemberKey((string) ($puzzler['player_name'] ?? '')) !== $baseKey) {
                    continue;
                }

                if (++$seen !== $occurrence) {
                    continue;
                }

                $snapshot['puzzlers'][$index] = ['player_id' => $newPlayerId, 'player_name' => $newGuestName];
                $changed = true;
            }

            if ($changed) {
                $this->connection->executeStatement(
                    'UPDATE puzzle_solving_time SET team = :team WHERE id = :id',
                    ['team' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'id' => $time['id']],
                );
            }
        }
    }

    private function convertMember(string $teamId, string $playerId, string $guestName): void
    {
        /** @var list<string> $otherMemberKeys */
        $otherMemberKeys = $this->connection->fetchFirstColumn(
            'SELECT member_key FROM puzzling_team_member WHERE team_id = :teamId AND (player_id IS NULL OR player_id <> :playerId)',
            ['teamId' => $teamId, 'playerId' => $playerId],
        );

        $baseKey = TeamComposition::guestMemberKey($guestName);
        $guestKey = $baseKey;

        for ($i = 2; in_array($guestKey, $otherMemberKeys, true); $i++) {
            $guestKey = $baseKey . '#' . $i;
        }

        $newCompositionKey = TeamComposition::keyFromMemberKeys([...$otherMemberKeys, $guestKey]);

        $survivingTeamId = $this->connection->fetchOne(
            'SELECT id FROM puzzling_team WHERE composition_key = :key AND id <> :teamId',
            ['key' => $newCompositionKey, 'teamId' => $teamId],
        );

        if (is_string($survivingTeamId)) {
            $this->mergeInto($teamId, $survivingTeamId);

            return;
        }

        $this->connection->executeStatement(
            'UPDATE puzzling_team_member SET player_id = NULL, guest_name = :guestName, member_key = :memberKey WHERE team_id = :teamId AND player_id = :playerId',
            ['guestName' => $guestName, 'memberKey' => $guestKey, 'teamId' => $teamId, 'playerId' => $playerId],
        );

        $this->connection->executeStatement(
            'UPDATE puzzling_team SET composition_key = :key WHERE id = :teamId',
            ['key' => $newCompositionKey, 'teamId' => $teamId],
        );
    }

    private function mergeInto(string $teamId, string $survivingTeamId): void
    {
        $this->connection->executeStatement(
            'UPDATE puzzle_solving_time SET puzzling_team_id = :survivor WHERE puzzling_team_id = :teamId',
            ['survivor' => $survivingTeamId, 'teamId' => $teamId],
        );

        $this->connection->executeStatement(
            <<<SQL
UPDATE puzzling_team AS survivor
SET name = merged.name, named_by_id = merged.named_by_id, named_at = merged.named_at
FROM puzzling_team AS merged
WHERE survivor.id = :survivor AND merged.id = :teamId AND survivor.name IS NULL AND merged.name IS NOT NULL
SQL,
            ['survivor' => $survivingTeamId, 'teamId' => $teamId],
        );

        $this->connection->executeStatement('DELETE FROM puzzling_team WHERE id = :teamId', ['teamId' => $teamId]);
    }
}
