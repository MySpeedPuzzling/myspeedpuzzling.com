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
     *     public_supporters: list<array{player_id: string, player_name: null|string, player_avatar: null|string, created_at: string}>
     * }
     */
    public function byAffiliateId(string $affiliateId): array
    {
        $totalQuery = <<<SQL
SELECT COUNT(*) FROM tribute WHERE affiliate_id = :affiliateId
SQL;

        /** @var int|string|false $totalResult */
        $totalResult = $this->database->fetchOne($totalQuery, [
            'affiliateId' => $affiliateId,
        ]);
        $totalCount = (int) $totalResult;

        $publicQuery = <<<SQL
SELECT
    p.id AS player_id,
    p.name AS player_name,
    p.avatar AS player_avatar,
    t.created_at
FROM tribute t
JOIN player p ON p.id = t.subscriber_id
WHERE t.affiliate_id = :affiliateId
    AND p.is_private = false
ORDER BY t.created_at DESC
SQL;

        $rows = $this->database->fetchAllAssociative($publicQuery, [
            'affiliateId' => $affiliateId,
        ]);

        /** @var list<array{player_id: string, player_name: null|string, player_avatar: null|string, created_at: string}> $publicSupporters */
        $publicSupporters = $rows;

        return [
            'total_count' => $totalCount,
            'public_supporters' => $publicSupporters,
        ];
    }
}
