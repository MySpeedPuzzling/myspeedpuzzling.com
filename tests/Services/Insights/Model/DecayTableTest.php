<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights\Model;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\Model\DecayTable;

final class DecayTableTest extends TestCase
{
    public function testMonthsAheadNeverDriftsIntoTheNextMonth(): void
    {
        $endOfJanuary = new DateTimeImmutable('2026-01-31 12:00:00');

        self::assertSame('2026-02-28 12:00:00', DecayTable::monthsAhead($endOfJanuary, 1)->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-31 12:00:00', DecayTable::monthsAhead($endOfJanuary, 2)->format('Y-m-d H:i:s'));
        self::assertSame('2026-04-30 12:00:00', DecayTable::monthsAhead($endOfJanuary, 3)->format('Y-m-d H:i:s'));
        self::assertSame('2024-02-29 12:00:00', DecayTable::monthsAhead(new DateTimeImmutable('2024-01-31 12:00:00'), 1)->format('Y-m-d H:i:s'));
        self::assertSame('2027-04-15 08:30:00', DecayTable::monthsAhead(new DateTimeImmutable('2026-10-15 08:30:00'), 6)->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-31 12:00:00', (new DecayTable(new DateTimeImmutable('2026-08-31 12:00:00')))->month(0)->format('Y-m-d H:i:s'));
    }
}
