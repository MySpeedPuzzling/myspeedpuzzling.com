<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\AffiliateOverview;

readonly final class GetAffiliateDashboard
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function byPlayerId(string $playerId): null|AffiliateOverview
    {
        $query = <<<SQL
SELECT
    a.id AS affiliate_id,
    a.player_id,
    p.name AS player_name,
    p.avatar AS player_avatar,
    a.code,
    a.status,
    a.created_at,
    COALESCE((SELECT COUNT(*) FROM referral r WHERE r.affiliate_id = a.id), 0) AS supporter_count,
    COALESCE((SELECT SUM(ap.payout_amount_cents) FROM affiliate_payout ap WHERE ap.affiliate_id = a.id), 0) AS total_earned_cents,
    COALESCE((SELECT SUM(ap.payout_amount_cents) FROM affiliate_payout ap WHERE ap.affiliate_id = a.id AND ap.status = 'pending'), 0) AS pending_payout_cents
FROM affiliate a
JOIN player p ON p.id = a.player_id
WHERE a.player_id = :playerId
SQL;

        $row = $this->database->fetchAssociative($query, [
            'playerId' => $playerId,
        ]);

        if ($row === false) {
            return null;
        }

        return AffiliateOverview::fromDatabaseRow($row);
    }
}
