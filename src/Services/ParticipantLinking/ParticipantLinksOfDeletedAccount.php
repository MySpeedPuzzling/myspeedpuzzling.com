<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantLinking;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use SpeedPuzzling\Web\Value\ParticipantLinkAction;
use SpeedPuzzling\Web\Value\ParticipantSource;

/**
 * Account deletion's side of the one link path (ParticipantLinks): set-based, since the deletion is (DeletePlayerHandler).
 */
readonly final class ParticipantLinksOfDeletedAccount
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The deleted account's competition rows (docs/features/competitions-management/participant-linking.md §11), called
     * by DeletePlayerHandler. Bulk SQL on purpose (the CLAUDE.md exception): every row of the account at once, like the
     * rest of the deletion, instead of loading each of its participants of every event through ParticipantLinks. In this
     * order:
     * 1. the link log forgets the account's name, and gets one `account_deleted` row per participant it was linked to;
     * 2. its own self-joined rows without official data go (soft delete - what leaving the event does,
     *    CompetitionSelfJoin::releaseOtherParticipants()): left with the profile's name and no player they would read as
     *    an unclaimed name anybody could pick from the "I'm going" list;
     * 3. every row lets go of the account - the organiser's rows and self-joined ones holding official data stay on the
     *    list, unlinked, with nothing of the link left.
     */
    public function release(string $playerId): void
    {
        $conn = $this->database;
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $conn->executeStatement('UPDATE participant_link_log SET player_name = \'\' WHERE player_id = :p', ['p' => $playerId]);

        $conn->executeStatement(
            <<<SQL
INSERT INTO participant_link_log (id, competition_id, participant_id, participant_name, player_id, player_name, action, method, by_player_id, at)
SELECT gen_uuid_v7(), cp.competition_id, cp.id, cp.name, cp.player_id, '', :action, NULL, NULL, CAST(:now AS timestamp)
FROM competition_participant cp
WHERE cp.player_id = :p
SQL,
            ['p' => $playerId, 'action' => ParticipantLinkAction::AccountDeleted->value, 'now' => $now],
        );

        $holdsOfficialData = OfficialResultsGuard::sqlParticipantHoldsOfficialData('cp');

        $conn->executeStatement(
            <<<SQL
UPDATE competition_participant cp
SET deleted_at = CAST(:now AS timestamp)
WHERE cp.player_id = :p
    AND cp.source = :selfJoined
    AND cp.deleted_at IS NULL
    AND NOT {$holdsOfficialData}
SQL,
            ['p' => $playerId, 'selfJoined' => ParticipantSource::SelfJoined->value, 'now' => $now],
        );

        $conn->executeStatement(
            'UPDATE competition_participant SET player_id = NULL, connected_at = NULL, connected_via = NULL, online_link_confirmed_at = NULL WHERE player_id = :p',
            ['p' => $playerId],
        );
    }
}
