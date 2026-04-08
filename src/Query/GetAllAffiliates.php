<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\AffiliateOverview;
use SpeedPuzzling\Web\Value\AffiliateStatus;

readonly final class GetAllAffiliates
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{pending: int, active: int, suspended: int}
     */
    public function countByStatus(): array
    {
        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE status = 'pending') AS pending,
    COUNT(*) FILTER (WHERE status = 'active') AS active,
    COUNT(*) FILTER (WHERE status = 'suspended') AS suspended
FROM affiliate
SQL;

        $row = $this->database->fetchAssociative($query);

        if ($row === false) {
            return ['pending' => 0, 'active' => 0, 'suspended' => 0];
        }

        /** @var int|string $pending */
        $pending = $row['pending'];
        /** @var int|string $active */
        $active = $row['active'];
        /** @var int|string $suspended */
        $suspended = $row['suspended'];

        return [
            'pending' => (int) $pending,
            'active' => (int) $active,
            'suspended' => (int) $suspended,
        ];
    }

    /**
     * @return array<AffiliateOverview>
     */
    public function byStatus(AffiliateStatus $status): array
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
WHERE a.status = :status
ORDER BY a.created_at DESC
SQL;

        $rows = $this->database->fetchAllAssociative($query, [
            'status' => $status->value,
        ]);

        return array_map(
            static fn(array $row): AffiliateOverview => AffiliateOverview::fromDatabaseRow($row),
            $rows,
        );
    }
}
