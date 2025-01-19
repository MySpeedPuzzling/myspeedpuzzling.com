<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * @phpstan-type PlayerChartDataRow array{
 *     period: string,
 *     time: numeric-string,
 * }
 */
readonly final class PlayerChartData
{
    public function __construct(
        public DateTimeImmutable $period,
        public int $time,
    ) {
    }

    /**
     * @param PlayerChartDataRow $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            period: new DateTimeImmutable($row['period']),
            time: (int) $row['time'],
        );
    }
}
