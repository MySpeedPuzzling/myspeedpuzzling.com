<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\LinkRequestStatus;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;

/**
 * The counters of the toolkit hub's "Participants & profiles" card (docs/features/competitions-management/
 * participant-linking.md §7.5) - counts only, no identity: the event's people on the list (not removed), how many are
 * linked, how many linked themselves, and the link requests waiting for the organiser. The same numbers as the
 * participants sheet's People filters (ParticipantsSheetState::linkCounts()). One statement.
 */
readonly final class GetParticipantLinkSummary
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{people: int, linked: int, notLinked: int, linkedByThemselves: int, requests: int}
     */
    public function of(string $competitionId): array
    {
        $own = implode(', ', array_map(
            static fn (ParticipantLinkMethod $method): string => "'" . $method->value . "'",
            array_values(array_filter(ParticipantLinkMethod::cases(), static fn (ParticipantLinkMethod $method): bool => $method->isThePlayersOwn())),
        ));

        /** @var array{people: int|string, linked: int|string, by_themselves: int|string, requests: int|string} $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    COUNT(*) FILTER (WHERE cp.id IS NOT NULL) AS people,
    COUNT(*) FILTER (WHERE cp.player_id IS NOT NULL) AS linked,
    COUNT(*) FILTER (WHERE cp.player_id IS NOT NULL AND cp.connected_via IN ({$own})) AS by_themselves,
    (
        SELECT COUNT(*) FROM participant_link_request r
        WHERE r.competition_id = :competitionId AND r.status = :pending
    ) AS requests
FROM competition_participant cp
WHERE cp.competition_id = :competitionId
    AND cp.deleted_at IS NULL
SQL,
            ['competitionId' => $competitionId, 'pending' => LinkRequestStatus::Pending->value],
        );

        $people = (int) $row['people'];
        $linked = (int) $row['linked'];

        return [
            'people' => $people,
            'linked' => $linked,
            'notLinked' => $people - $linked,
            'linkedByThemselves' => (int) $row['by_themselves'],
            'requests' => (int) $row['requests'],
        ];
    }
}
