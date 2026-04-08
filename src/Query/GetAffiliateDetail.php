<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\AffiliateOverview;
use SpeedPuzzling\Web\Results\PayoutOverview;
use SpeedPuzzling\Web\Results\ReferralOverview;

readonly final class GetAffiliateDetail
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function overview(string $affiliateId): null|AffiliateOverview
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
WHERE a.id = :affiliateId
SQL;

        $row = $this->database->fetchAssociative($query, [
            'affiliateId' => $affiliateId,
        ]);

        if ($row === false) {
            return null;
        }

        return AffiliateOverview::fromDatabaseRow($row);
    }

    /**
     * @return array<ReferralOverview>
     */
    public function referrals(string $affiliateId): array
    {
        $query = <<<SQL
SELECT
    r.id AS referral_id,
    r.subscriber_id,
    sp.name AS subscriber_name,
    sp.avatar AS subscriber_avatar,
    r.affiliate_id,
    ap.name AS affiliate_player_name,
    a.code AS affiliate_code,
    r.source,
    r.created_at
FROM referral r
JOIN player sp ON sp.id = r.subscriber_id
JOIN affiliate a ON a.id = r.affiliate_id
JOIN player ap ON ap.id = a.player_id
WHERE r.affiliate_id = :affiliateId
ORDER BY r.created_at DESC
SQL;

        $rows = $this->database->fetchAllAssociative($query, [
            'affiliateId' => $affiliateId,
        ]);

        return array_map(
            static fn(array $row): ReferralOverview => ReferralOverview::fromDatabaseRow($row),
            $rows,
        );
    }

    /**
     * @return array<PayoutOverview>
     */
    public function payouts(string $affiliateId): array
    {
        $query = <<<SQL
SELECT
    ap.id AS payout_id,
    ap.affiliate_id,
    afp.name AS affiliate_player_name,
    t.subscriber_id,
    sp.name AS subscriber_name,
    ap.stripe_invoice_id,
    ap.payment_amount_cents,
    ap.payout_amount_cents,
    ap.currency,
    ap.status,
    ap.created_at,
    ap.paid_at
FROM affiliate_payout ap
JOIN referral r ON r.id = ap.referral_id
JOIN player sp ON sp.id = r.subscriber_id
JOIN affiliate a ON a.id = ap.affiliate_id
JOIN player afp ON afp.id = a.player_id
WHERE ap.affiliate_id = :affiliateId
ORDER BY ap.created_at DESC
SQL;

        $rows = $this->database->fetchAllAssociative($query, [
            'affiliateId' => $affiliateId,
        ]);

        return array_map(
            static fn(array $row): PayoutOverview => PayoutOverview::fromDatabaseRow($row),
            $rows,
        );
    }
}
