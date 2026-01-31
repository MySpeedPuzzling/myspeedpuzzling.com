<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\VoucherOverview;

readonly final class GetAllVouchers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{available: int, used: int, expired: int}
     */
    public function countByStatus(DateTimeImmutable $now): array
    {
        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE used_at IS NULL AND valid_until >= :now) as available,
    COUNT(*) FILTER (WHERE used_at IS NOT NULL) as used,
    COUNT(*) FILTER (WHERE used_at IS NULL AND valid_until < :now) as expired
FROM voucher
SQL;

        $row = $this->database->fetchAssociative($query, [
            'now' => $now->format('Y-m-d H:i:s'),
        ]);

        if ($row === false) {
            return ['available' => 0, 'used' => 0, 'expired' => 0];
        }

        /** @var int $available */
        $available = $row['available'];
        /** @var int $used */
        $used = $row['used'];
        /** @var int $expired */
        $expired = $row['expired'];

        return [
            'available' => $available,
            'used' => $used,
            'expired' => $expired,
        ];
    }

    /**
     * @return array<VoucherOverview>
     */
    public function allAvailable(DateTimeImmutable $now): array
    {
        $query = <<<SQL
SELECT
    v.id,
    v.code,
    v.months_value,
    v.valid_until,
    v.created_at,
    v.used_at,
    v.internal_note,
    p.id as used_by_id,
    p.name as used_by_name
FROM voucher v
LEFT JOIN player p ON p.id = v.used_by_id
WHERE v.used_at IS NULL AND v.valid_until >= :now
ORDER BY v.created_at DESC
SQL;

        $rows = $this->database->fetchAllAssociative($query, [
            'now' => $now->format('Y-m-d H:i:s'),
        ]);

        return array_map(
            static fn(array $row): VoucherOverview => VoucherOverview::fromDatabaseRow($row),
            $rows,
        );
    }

    /**
     * @return array<VoucherOverview>
     */
    public function allUsed(): array
    {
        $query = <<<SQL
SELECT
    v.id,
    v.code,
    v.months_value,
    v.valid_until,
    v.created_at,
    v.used_at,
    v.internal_note,
    p.id as used_by_id,
    p.name as used_by_name
FROM voucher v
LEFT JOIN player p ON p.id = v.used_by_id
WHERE v.used_at IS NOT NULL
ORDER BY v.used_at DESC
SQL;

        $rows = $this->database->fetchAllAssociative($query);

        return array_map(
            static fn(array $row): VoucherOverview => VoucherOverview::fromDatabaseRow($row),
            $rows,
        );
    }

    /**
     * @return array<VoucherOverview>
     */
    public function allExpired(DateTimeImmutable $now): array
    {
        $query = <<<SQL
SELECT
    v.id,
    v.code,
    v.months_value,
    v.valid_until,
    v.created_at,
    v.used_at,
    v.internal_note,
    p.id as used_by_id,
    p.name as used_by_name
FROM voucher v
LEFT JOIN player p ON p.id = v.used_by_id
WHERE v.used_at IS NULL AND v.valid_until < :now
ORDER BY v.valid_until DESC
SQL;

        $rows = $this->database->fetchAllAssociative($query, [
            'now' => $now->format('Y-m-d H:i:s'),
        ]);

        return array_map(
            static fn(array $row): VoucherOverview => VoucherOverview::fromDatabaseRow($row),
            $rows,
        );
    }
}
