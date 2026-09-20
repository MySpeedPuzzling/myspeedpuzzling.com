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
