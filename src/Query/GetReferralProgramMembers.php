<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetReferralProgramMembers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{active: int, suspended: int}
     */
    public function countByStatus(): array
    {
        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE referral_program_joined_at IS NOT NULL AND referral_program_suspended = false) AS active,
    COUNT(*) FILTER (WHERE referral_program_joined_at IS NOT NULL AND referral_program_suspended = true) AS suspended
FROM player
WHERE referral_program_joined_at IS NOT NULL
SQL;

        $row = $this->database->fetchAssociative($query);

        if ($row === false) {
            return ['active' => 0, 'suspended' => 0];
        }

        /** @var int|string $active */
        $active = $row['active'];
        /** @var int|string $suspended */
        $suspended = $row['suspended'];

        return [
            'active' => (int) $active,
            'suspended' => (int) $suspended,
        ];
    }

    /**
     * @return list<array{
     *     player_id: string,
     *     player_name: null|string,
     *     code: string,
     *     referral_program_joined_at: string,
     *     referral_program_suspended: bool,
     *     supporter_count: int,
     *     total_earned_cents: int,
     *     pending_payout_cents: int,
     * }>
     */
    public function byStatus(bool $suspended): array
    {
        $query = <<<SQL
SELECT
    p.id AS player_id,
    p.name AS player_name,
    p.code,
    p.referral_program_joined_at,
    p.referral_program_suspended,
    COALESCE((SELECT COUNT(*) FROM referral r WHERE r.affiliate_player_id = p.id), 0) AS supporter_count,
    COALESCE((SELECT SUM(ap.payout_amount_cents) FROM affiliate_payout ap WHERE ap.affiliate_player_id = p.id), 0) AS total_earned_cents,
    COALESCE((SELECT SUM(ap.payout_amount_cents) FROM affiliate_payout ap WHERE ap.affiliate_player_id = p.id AND ap.status = 'pending'), 0) AS pending_payout_cents
FROM player p
WHERE p.referral_program_joined_at IS NOT NULL
    AND p.referral_program_suspended = :suspended
ORDER BY p.referral_program_joined_at DESC
SQL;

        /** @var list<array{player_id: string, player_name: null|string, code: string, referral_program_joined_at: string, referral_program_suspended: bool, supporter_count: int, total_earned_cents: int, pending_payout_cents: int}> */
        return $this->database->fetchAllAssociative($query, [
            'suspended' => $suspended,
        ], [
            'suspended' => \Doctrine\DBAL\ParameterType::BOOLEAN,
        ]);
    }
}
