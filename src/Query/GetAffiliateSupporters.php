<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetAffiliateSupporters
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{
     *     total_count: int,
     *     public_supporters: list<array{player_id: string, player_name: null|string, player_avatar: null|string, created_at: string}>,
     *     total_earned_cents: int,
     *     pending_payout_cents: int,
     * }
     */
    public function byPlayerId(string $affiliatePlayerId): array
    {
        $totalQuery = <<<SQL
SELECT COUNT(*) FROM referral WHERE affiliate_player_id = :playerId
SQL;

        /** @var int|string|false $totalResult */
        $totalResult = $this->database->fetchOne($totalQuery, [
            'playerId' => $affiliatePlayerId,
        ]);
        $totalCount = (int) $totalResult;

        $publicQuery = <<<SQL
SELECT
    p.id AS player_id,
    p.name AS player_name,
    p.avatar AS player_avatar,
    r.created_at
FROM referral r
JOIN player p ON p.id = r.subscriber_id
WHERE r.affiliate_player_id = :playerId
    AND p.is_private = false
ORDER BY r.created_at DESC
SQL;

        $rows = $this->database->fetchAllAssociative($publicQuery, [
            'playerId' => $affiliatePlayerId,
        ]);

        /** @var list<array{player_id: string, player_name: null|string, player_avatar: null|string, created_at: string}> $publicSupporters */
        $publicSupporters = $rows;

        $payoutQuery = <<<SQL
SELECT
    COALESCE(SUM(ap.payout_amount_cents), 0) AS total_earned_cents,
    COALESCE(SUM(ap.payout_amount_cents) FILTER (WHERE ap.status = 'pending'), 0) AS pending_payout_cents
FROM affiliate_payout ap
WHERE ap.affiliate_player_id = :playerId
SQL;

        $payoutRow = $this->database->fetchAssociative($payoutQuery, [
            'playerId' => $affiliatePlayerId,
        ]);

        /** @var int|string $totalEarned */
        $totalEarned = $payoutRow['total_earned_cents'] ?? 0;
        /** @var int|string $pendingPayout */
        $pendingPayout = $payoutRow['pending_payout_cents'] ?? 0;

        return [
            'total_count' => $totalCount,
            'public_supporters' => $publicSupporters,
            'total_earned_cents' => (int) $totalEarned,
            'pending_payout_cents' => (int) $pendingPayout,
        ];
    }
}
