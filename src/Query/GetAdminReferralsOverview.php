<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetAdminReferralsOverview
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<array{currency: string, unpaid_cents: int, paid_cents: int}>
     */
    public function totalsPerCurrency(): array
    {
        $query = <<<SQL
SELECT
    ap.currency,
    COALESCE(SUM(ap.payout_amount_cents) FILTER (WHERE ap.status = 'pending'), 0) AS unpaid_cents,
    COALESCE(SUM(ap.payout_amount_cents) FILTER (WHERE ap.status = 'paid'), 0) AS paid_cents
FROM affiliate_payout ap
GROUP BY ap.currency
ORDER BY ap.currency
SQL;

        /** @var list<array{currency: string, unpaid_cents: int|string, paid_cents: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query);

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
     *     player_id: string,
     *     player_name: null|string,
     *     code: string,
     *     referral_program_joined_at: null|string,
     *     referral_program_suspended: bool,
     *     referral_count: int,
     *     unpaid_total_cents: int,
     *     payouts: list<array{currency: string, unpaid_cents: int, paid_cents: int}>,
     * }>
     */
    public function affiliates(): array
    {
        $affiliatesQuery = <<<SQL
SELECT
    p.id AS player_id,
    p.name AS player_name,
    p.code,
    p.referral_program_joined_at,
    p.referral_program_suspended,
    COALESCE((SELECT COUNT(*) FROM referral r WHERE r.affiliate_player_id = p.id), 0) AS referral_count
FROM player p
WHERE p.referral_program_joined_at IS NOT NULL
    OR EXISTS (SELECT 1 FROM referral r WHERE r.affiliate_player_id = p.id)
ORDER BY p.referral_program_joined_at DESC NULLS LAST
SQL;

        /** @var list<array{
         *     player_id: string,
         *     player_name: null|string,
         *     code: string,
         *     referral_program_joined_at: null|string,
         *     referral_program_suspended: bool,
         *     referral_count: int|string,
         * }> $affiliateRows
         */
        $affiliateRows = $this->database->fetchAllAssociative($affiliatesQuery);

        $payoutsQuery = <<<SQL
SELECT
    ap.affiliate_player_id,
    ap.currency,
    COALESCE(SUM(ap.payout_amount_cents) FILTER (WHERE ap.status = 'pending'), 0) AS unpaid_cents,
    COALESCE(SUM(ap.payout_amount_cents) FILTER (WHERE ap.status = 'paid'), 0) AS paid_cents
FROM affiliate_payout ap
GROUP BY ap.affiliate_player_id, ap.currency
ORDER BY ap.currency
SQL;

        /** @var list<array{affiliate_player_id: string, currency: string, unpaid_cents: int|string, paid_cents: int|string}> $payoutRows */
        $payoutRows = $this->database->fetchAllAssociative($payoutsQuery);

        $payoutsByAffiliate = [];

        foreach ($payoutRows as $payoutRow) {
            $payoutsByAffiliate[$payoutRow['affiliate_player_id']][] = [
                'currency' => $payoutRow['currency'],
                'unpaid_cents' => (int) $payoutRow['unpaid_cents'],
                'paid_cents' => (int) $payoutRow['paid_cents'],
            ];
        }

        $affiliates = [];

        foreach ($affiliateRows as $affiliateRow) {
            $payouts = $payoutsByAffiliate[$affiliateRow['player_id']] ?? [];
            $unpaidTotalCents = array_sum(
                array_map(
                    static fn(array $payout): int => $payout['unpaid_cents'],
                    $payouts,
                ),
            );

            $affiliates[] = [
                'player_id' => $affiliateRow['player_id'],
                'player_name' => $affiliateRow['player_name'],
                'code' => $affiliateRow['code'],
                'referral_program_joined_at' => $affiliateRow['referral_program_joined_at'],
                'referral_program_suspended' => $affiliateRow['referral_program_suspended'],
                'referral_count' => (int) $affiliateRow['referral_count'],
                'unpaid_total_cents' => $unpaidTotalCents,
                'payouts' => $payouts,
            ];
        }

        // Affiliates we owe money to first (sums mix currencies, used only for ordering)
        usort(
            $affiliates,
            static fn(array $a, array $b): int => [$b['unpaid_total_cents'], $b['referral_count']] <=> [$a['unpaid_total_cents'], $a['referral_count']],
        );

        return $affiliates;
    }
}
