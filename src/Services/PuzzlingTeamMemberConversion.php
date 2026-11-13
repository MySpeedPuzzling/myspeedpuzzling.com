<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Closure;
use Doctrine\DBAL\Connection;
use LogicException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
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
        private ClockInterface $clock,
    ) {
    }

    /**
     * A player claims a spot of an imported event result (docs/features/event-results-import/README.md "Claiming a
     * spot"): the anonymous slot becomes the player in the result's pair/team and in its group snapshot. Once every slot
     * is claimed the people may equal a team that already exists - the result then joins it.
     *
     * By result, not by team: a fully claimed result may share its team with the people's other results, and only
     * this one changes.
     */
    public function anonymousSlotToPlayer(string $timeId, string $slotKey, string $playerId): void
    {
        $this->replaceMemberOfTime(
            timeId: $timeId,
            oldKey: $slotKey,
            newKey: strtolower($playerId),
            newPlayerId: $playerId,
            isReplaced: static fn (array $puzzler): bool => ($puzzler['anonymous_slot'] ?? null) === $slotKey,
            // Exactly what PuzzlersGroupDoctrineType writes for a player
            replacement: ['player_id' => $playerId, 'player_name' => null],
        );
    }

    /**
     * The player's spot of an imported event result becomes anonymous again - released, removed by somebody else, or
     * the account deleted (docs/features/event-results-import/README.md "Gates"). Only this result: when it shares its
     * pair/team with the people's other results, it gets a team of its own.
     */
    public function playerToAnonymousSlot(string $timeId, string $playerId, string $newSlotKey): void
    {
        if (str_starts_with($newSlotKey, TeamComposition::ANONYMOUS_PREFIX) === false) {
            throw new LogicException(sprintf('An anonymous slot starts with "%s", got "%s".', TeamComposition::ANONYMOUS_PREFIX, $newSlotKey));
        }

        $this->replaceMemberOfTime(
            timeId: $timeId,
            oldKey: strtolower($playerId),
            newKey: $newSlotKey,
            newPlayerId: null,
            isReplaced: static fn (array $puzzler): bool => is_string($puzzler['player_id'] ?? null) && strtolower($puzzler['player_id']) === strtolower($playerId),
            // Exactly what PuzzlersGroupDoctrineType writes for an anonymous person
            replacement: ['player_id' => null, 'player_name' => null, 'anonymous_slot' => $newSlotKey],
        );
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

    /**
     * @param Closure(array<string, mixed>): bool $isReplaced picks the person in the result's group snapshot
     * @param array<string, null|string> $replacement
     */
    private function replaceMemberOfTime(
        string $timeId,
        string $oldKey,
        string $newKey,
        null|string $newPlayerId,
        Closure $isReplaced,
        array $replacement,
    ): void {
        $teamId = $this->connection->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        if (is_string($teamId) === false) {
            throw new LogicException(sprintf('Solving time %s has no pair/team.', $timeId));
        }

        /** @var list<array{member_key: string, player_id: null|string, guest_name: null|string, position: int}> $members */
        $members = $this->connection->fetchAllAssociative(
            'SELECT member_key, player_id, guest_name, position FROM puzzling_team_member WHERE team_id = :teamId ORDER BY position',
            ['teamId' => $teamId],
        );
        $memberKeys = array_column($members, 'member_key');

        if (in_array($oldKey, $memberKeys, true) === false) {
            throw new LogicException(sprintf('"%s" is not a member of the pair/team of solving time %s.', $oldKey, $timeId));
        }

        // One person cannot be in a team twice
        if (in_array($newKey, $memberKeys, true)) {
            throw new LogicException(sprintf('"%s" is already a member of the pair/team of solving time %s.', $newKey, $timeId));
        }

        $newMemberKeys = array_values(array_filter($memberKeys, static fn (string $key): bool => $key !== $oldKey));
        $newMemberKeys[] = $newKey;
        $newCompositionKey = TeamComposition::keyFromMemberKeys($newMemberKeys);

        $this->rewriteSnapshotOf($timeId, $isReplaced, $replacement);

        $sharedWithOtherResults = $this->connection->fetchOne(
            'SELECT 1 FROM puzzle_solving_time WHERE puzzling_team_id = :teamId AND id <> :timeId LIMIT 1',
            ['teamId' => $teamId, 'timeId' => $timeId],
        ) !== false;

        $survivingTeamId = $this->connection->fetchOne(
            'SELECT id FROM puzzling_team WHERE composition_key = :key AND id <> :teamId',
            ['key' => $newCompositionKey, 'teamId' => $teamId],
        );

        if (is_string($survivingTeamId)) {
            if ($sharedWithOtherResults) {
                $this->moveTimeTo($timeId, $survivingTeamId);
            } else {
                $this->mergeInto($teamId, $survivingTeamId);
            }

            return;
        }

        if ($sharedWithOtherResults) {
            // The people's other results keep their team; this one gets a team of its own, under the same name
            $newTeamId = Uuid::uuid7()->toString();

            $this->connection->executeStatement(
                'INSERT INTO puzzling_team (id, composition_key, size, created_at, name) SELECT :newId, :key, size, :now, name FROM puzzling_team WHERE id = :teamId',
                ['newId' => $newTeamId, 'key' => $newCompositionKey, 'now' => $this->clock->now()->format('Y-m-d H:i:s'), 'teamId' => $teamId],
            );

            foreach ($members as $member) {
                $replaced = $member['member_key'] === $oldKey;

                $this->connection->executeStatement(
                    'INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, guest_name, position) VALUES (:id, :teamId, :memberKey, :playerId, :guestName, :position)',
                    [
                        'id' => Uuid::uuid7()->toString(),
                        'teamId' => $newTeamId,
                        'memberKey' => $replaced ? $newKey : $member['member_key'],
                        'playerId' => $replaced ? $newPlayerId : $member['player_id'],
                        'guestName' => $replaced ? null : $member['guest_name'],
                        'position' => $member['position'],
                    ],
                );
            }

            $this->moveTimeTo($timeId, $newTeamId);

            return;
        }

        $this->connection->executeStatement(
            'UPDATE puzzling_team_member SET player_id = :playerId, guest_name = NULL, member_key = :newKey WHERE team_id = :teamId AND member_key = :oldKey',
            ['playerId' => $newPlayerId, 'newKey' => $newKey, 'teamId' => $teamId, 'oldKey' => $oldKey],
        );

        $this->connection->executeStatement(
            'UPDATE puzzling_team SET composition_key = :key WHERE id = :teamId',
            ['key' => $newCompositionKey, 'teamId' => $teamId],
        );
    }

    /**
     * @param Closure(array<string, mixed>): bool $isReplaced
     * @param array<string, null|string> $replacement
     */
    private function rewriteSnapshotOf(string $timeId, Closure $isReplaced, array $replacement): void
    {
        $json = $this->connection->fetchOne('SELECT team FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        if (is_string($json) === false) {
            throw new LogicException(sprintf('Solving time %s has no group snapshot.', $timeId));
        }

        /** @var array{team_id?: null|string, puzzlers?: list<array<string, mixed>>} $snapshot */
        $snapshot = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        foreach ($snapshot['puzzlers'] ?? [] as $index => $puzzler) {
            if ($isReplaced($puzzler)) {
                $snapshot['puzzlers'][$index] = $replacement;

                $this->connection->executeStatement(
                    'UPDATE puzzle_solving_time SET team = :team WHERE id = :id',
                    ['team' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 'id' => $timeId],
                );

                return;
            }
        }

        throw new LogicException(sprintf('The person to replace is not in the group snapshot of solving time %s.', $timeId));
    }

    private function moveTimeTo(string $timeId, string $teamId): void
    {
        $this->connection->executeStatement(
            'UPDATE puzzle_solving_time SET puzzling_team_id = :teamId WHERE id = :timeId',
            ['teamId' => $teamId, 'timeId' => $timeId],
        );
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

        // Comparison line-ups holding the merged team compare the survivor from now on - other players' rows, so
        // one statement rather than loading them. Whoever has both keeps the survivor's row; the other one goes
        // with the delete below (ON DELETE CASCADE)
        $this->connection->executeStatement(
            <<<SQL
UPDATE comparison_subject
SET subject_team_id = :survivor
WHERE subject_team_id = :teamId
    AND NOT EXISTS (
        SELECT 1 FROM comparison_subject already
        WHERE already.player_id = comparison_subject.player_id AND already.subject_team_id = :survivor
    )
SQL,
            ['survivor' => $survivingTeamId, 'teamId' => $teamId],
        );

        $this->connection->executeStatement('DELETE FROM puzzling_team WHERE id = :teamId', ['teamId' => $teamId]);
    }
}
