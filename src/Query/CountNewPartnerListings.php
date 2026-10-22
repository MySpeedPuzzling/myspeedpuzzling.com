<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\PartnerListing;

/**
 * "N new puzzlers listed since you did" of the "Still looking?" e-mail (docs/features/partner-finder.md "Phase 2 as
 * built"): the other open listings at the event made after this one, nobody blocked either way - a count, nobody's
 * identity.
 */
readonly final class CountNewPartnerListings
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function since(PartnerListing $listing): int
    {
        $count = $this->database->fetchOne(
            <<<SQL
SELECT COUNT(*)
FROM partner_listing other
WHERE other.competition_id = :competitionId
    AND other.closed_at IS NULL
    AND other.player_id <> :playerId
    AND other.created_at > :since
    AND NOT EXISTS (
        SELECT 1 FROM user_block ub
        WHERE (ub.blocker_id = other.player_id AND ub.blocked_id = :playerId)
            OR (ub.blocker_id = :playerId AND ub.blocked_id = other.player_id)
    )
SQL,
            [
                'competitionId' => $listing->competition->id->toString(),
                'playerId' => $listing->player->id->toString(),
                'since' => $listing->createdAt->format('Y-m-d H:i:s.u'),
            ],
        );

        return is_numeric($count) ? (int) $count : 0;
    }
}
