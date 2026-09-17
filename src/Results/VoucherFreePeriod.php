<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class VoucherFreePeriod
{
    public function __construct(
        public string $voucherCode,
        public int $months,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {
    }

    public function hasStarted(DateTimeImmutable $now): bool
    {
        return $this->startsAt <= $now;
    }

    /**
     * @param array{
     *     code: string,
     *     months_value: int,
     *     free_period_starts_at: string,
     *     free_period_ends_at: string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $startsAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['free_period_starts_at']);
        assert($startsAt instanceof DateTimeImmutable);

        $endsAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['free_period_ends_at']);
        assert($endsAt instanceof DateTimeImmutable);

        return new self(
            voucherCode: $row['code'],
            months: $row['months_value'],
            startsAt: $startsAt,
            endsAt: $endsAt,
        );
    }
}
