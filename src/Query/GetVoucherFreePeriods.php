<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\VoucherFreePeriod;

readonly final class GetVoucherFreePeriods
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Free months from vouchers the player claimed that are still running or yet to come, earliest first.
     *
     * @return list<VoucherFreePeriod>
     */
    public function notEndedForPlayer(string $playerId, DateTimeImmutable $now): array
    {
        if (Uuid::isValid($playerId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT
    code,
    months_value,
    free_period_starts_at,
    free_period_ends_at
FROM voucher
WHERE used_by_id = :playerId
    AND voucher_type = 'free_months'
    AND months_value IS NOT NULL
    AND free_period_starts_at IS NOT NULL
    AND free_period_ends_at > :now
ORDER BY free_period_starts_at
SQL;

        /**
         * @var list<array{
         *     code: string,
         *     months_value: int,
         *     free_period_starts_at: string,
         *     free_period_ends_at: string,
         * }> $rows
         */
        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'now' => $now->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(VoucherFreePeriod::fromDatabaseRow(...), $rows);
    }
}
