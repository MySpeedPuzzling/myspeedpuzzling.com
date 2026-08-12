<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;

readonly final class GetAdminReferralDetail
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PlayerNotFound
     *
     * @return array{
     *     player_id: string,
     *     player_name: null|string,
     *     code: string,
     *     email: null|string,
     *     country: null|string,
     *     referral_program_joined_at: null|string,
     *     referral_program_suspended: bool,
     * }
     */
    public function affiliate(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    p.id AS player_id,
    p.name AS player_name,
    p.code,
    p.email,
    p.country,
    p.referral_program_joined_at,
    p.referral_program_suspended
FROM player p
WHERE p.id = :playerId
SQL;

        /** @var false|array{
         *     player_id: string,
         *     player_name: null|string,
         *     code: string,
         *     email: null|string,
         *     country: null|string,
         *     referral_program_joined_at: null|string,
         *     referral_program_suspended: bool,
         * } $row
         */
        $row = $this->database->fetchAssociative($query, [
            'playerId' => $playerId,
        ]);

        if ($row === false) {
            throw new PlayerNotFound();
        }

        return $row;
    }

    /**
     * @return list<array{currency: string, unpaid_cents: int, paid_cents: int}>
     */
    public function payoutTotals(string $playerId): array
    {
        $query = <<<SQL
SELECT
    ap.currency,
    COALESCE(SUM(ap.payout_amount_cents) FILTER (WHERE ap.status = 'pending'), 0) AS unpaid_cents,
    COALESCE(SUM(ap.payout_amount_cents) FILTER (WHERE ap.status = 'paid'), 0) AS paid_cents
FROM affiliate_payout ap
WHERE ap.affiliate_player_id = :playerId
GROUP BY ap.currency
ORDER BY ap.currency
SQL;

        /** @var list<array{currency: string, unpaid_cents: int|string, paid_cents: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'playerId' => $playerId,
        ]);

        return array_map(
            static fn(array $row): array => [
                'currency' => $row['currency'],
                'unpaid_cents' => (int) $row['unpaid_cents'],
                'paid_cents' => (int) $row['paid_cents'],
            ],
            $rows,
        );
    }

    /**
     * @return list<array{
     *     referral_id: string,
     *     subscriber_id: string,
     *     subscriber_name: null|string,
     *     subscriber_code: string,
     *     source: string,
     *     created_at: string,
     *     has_active_subscription: bool,
     *     payments: list<array{currency: string, payment_count: int, commission_cents: int, unpaid_cents: int}>,
     * }>
     */
    public function referrals(string $playerId): array
    {
        $referralsQuery = <<<SQL
SELECT
    r.id AS referral_id,
    r.source,
    r.created_at,
    p.id AS subscriber_id,
    p.name AS subscriber_name,
    p.code AS subscriber_code,
    (m.ends_at IS NULL AND m.billing_period_ends_at IS NOT NULL) AS has_active_subscription
FROM referral r
JOIN player p ON p.id = r.subscriber_id
LEFT JOIN membership m ON m.player_id = p.id
WHERE r.affiliate_player_id = :playerId
ORDER BY r.created_at DESC
SQL;

        /** @var list<array{
         *     referral_id: string,
         *     source: string,
         *     created_at: string,
         *     subscriber_id: string,
         *     subscriber_name: null|string,
         *     subscriber_code: string,
         *     has_active_subscription: bool,
         * }> $referralRows
         */
        $referralRows = $this->database->fetchAllAssociative($referralsQuery, [
            'playerId' => $playerId,
        ]);

        $paymentsQuery = <<<SQL
SELECT
    ap.referral_id,
    ap.currency,
    COUNT(*) AS payment_count,
    COALESCE(SUM(ap.payout_amount_cents), 0) AS commission_cents,
    COALESCE(SUM(ap.payout_amount_cents) FILTER (WHERE ap.status = 'pending'), 0) AS unpaid_cents
FROM affiliate_payout ap
WHERE ap.affiliate_player_id = :playerId
GROUP BY ap.referral_id, ap.currency
ORDER BY ap.currency
SQL;

        /** @var list<array{referral_id: string, currency: string, payment_count: int|string, commission_cents: int|string, unpaid_cents: int|string}> $paymentRows */
        $paymentRows = $this->database->fetchAllAssociative($paymentsQuery, [
            'playerId' => $playerId,
        ]);

        $paymentsByReferral = [];

        foreach ($paymentRows as $paymentRow) {
            $paymentsByReferral[$paymentRow['referral_id']][] = [
                'currency' => $paymentRow['currency'],
                'payment_count' => (int) $paymentRow['payment_count'],
                'commission_cents' => (int) $paymentRow['commission_cents'],
                'unpaid_cents' => (int) $paymentRow['unpaid_cents'],
            ];
        }

        $referrals = [];

        foreach ($referralRows as $referralRow) {
            $referrals[] = [
                'referral_id' => $referralRow['referral_id'],
                'subscriber_id' => $referralRow['subscriber_id'],
                'subscriber_name' => $referralRow['subscriber_name'],
                'subscriber_code' => $referralRow['subscriber_code'],
                'source' => $referralRow['source'],
                'created_at' => $referralRow['created_at'],
                'has_active_subscription' => $referralRow['has_active_subscription'],
                'payments' => $paymentsByReferral[$referralRow['referral_id']] ?? [],
            ];
        }

        return $referrals;
    }

    /**
     * @return list<array{
     *     payout_id: string,
     *     created_at: string,
     *     subscriber_id: string,
     *     subscriber_name: null|string,
     *     stripe_invoice_id: string,
     *     payment_amount_cents: int,
     *     payout_amount_cents: int,
     *     currency: string,
     *     status: string,
     *     paid_at: null|string,
     * }>
     */
    public function payouts(string $playerId): array
    {
        $query = <<<SQL
SELECT
    ap.id AS payout_id,
    ap.created_at,
    sub.id AS subscriber_id,
    sub.name AS subscriber_name,
    ap.stripe_invoice_id,
    ap.payment_amount_cents,
    ap.payout_amount_cents,
    ap.currency,
    ap.status,
    ap.paid_at
FROM affiliate_payout ap
JOIN referral r ON r.id = ap.referral_id
JOIN player sub ON sub.id = r.subscriber_id
WHERE ap.affiliate_player_id = :playerId
ORDER BY ap.created_at DESC
SQL;

        /** @var list<array{
         *     payout_id: string,
         *     created_at: string,
         *     subscriber_id: string,
         *     subscriber_name: null|string,
         *     stripe_invoice_id: string,
         *     payment_amount_cents: int,
         *     payout_amount_cents: int,
         *     currency: string,
         *     status: string,
         *     paid_at: null|string,
         * }>
         */
        return $this->database->fetchAllAssociative($query, [
            'playerId' => $playerId,
        ]);
    }
}
