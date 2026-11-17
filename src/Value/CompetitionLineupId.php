<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;

/**
 * A pair/team "for the whole competition" - the line-up (docs/features/competitions-management/toolkit/
 * B-qr-competitor-page.md §3.2). Pairs/teams are per-round CompetitionTeam rows, rebuilt by the participants sheet, so
 * the same people are a different row in every round; the line-up is derived from the exact set of its members and
 * never stored: uuid5 in the event's namespace over the sorted, lower-cased participant ids. The same people in rounds
 * 1-6 = one id; a changed member (or a member removed and added again - a new participant id) = another line-up.
 */
final readonly class CompetitionLineupId
{
    /**
     * @param list<string> $memberParticipantIds
     */
    public static function of(string $competitionId, array $memberParticipantIds): string
    {
        $members = array_values(array_unique(array_map(strtolower(...), $memberParticipantIds)));
        sort($members);

        return Uuid::uuid5(strtolower($competitionId), implode(',', $members))->toString();
    }
}
