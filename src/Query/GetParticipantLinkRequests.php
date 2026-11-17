<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ParticipantsSheet\ParticipantsSheetLinkRequest;
use SpeedPuzzling\Web\Value\LinkRequestStatus;

/**
 * The pending link requests of an event, oldest first (docs/features/competitions-management/participant-linking.md
 * §7.1) - organiser tooling behind COMPETITION_EDIT (the participants sheet's state). One statement.
 *
 * Identity: the claimant's name and #code are the request's own snapshot, never the profile (the player table is not
 * read): the claimant showed themselves to this event's organisers on purpose - the claim screen says so - so a private
 * profile's claim is decided like any other, and the organiser's own blocks do not hide it (blocklist rule 7: a blocked
 * player's legitimate claim could otherwise never be decided).
 */
readonly final class GetParticipantLinkRequests
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<ParticipantsSheetLinkRequest>
     */
    public function pendingOf(string $competitionId): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT r.id, r.participant_id, r.kind, r.player_id, r.player_name, r.player_code, r.created_at
FROM participant_link_request r
WHERE r.competition_id = :competitionId
    AND r.status = :pending
ORDER BY r.created_at, r.id
SQL,
            ['competitionId' => $competitionId, 'pending' => LinkRequestStatus::Pending->value],
        );

        $requests = [];
        foreach ($rows as $row) {
            /** @var array{id: string, participant_id: string, kind: string, player_id: string, player_name: string, player_code: string, created_at: string} $row */
            $requests[] = new ParticipantsSheetLinkRequest(
                id: strtolower($row['id']),
                participantId: strtolower($row['participant_id']),
                kind: $row['kind'],
                playerId: strtolower($row['player_id']),
                playerName: $row['player_name'],
                playerCode: $row['player_code'],
                createdAt: new DateTimeImmutable($row['created_at']),
            );
        }

        return $requests;
    }
}
