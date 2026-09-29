<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PuzzleMarketplaceOffer;
use SpeedPuzzling\Web\Value\PuzzleCondition;

/**
 * The "from €5" next to the offers badge of a puzzle page.
 */
final class PuzzleMarketplaceOfferTest extends TestCase
{
    public function testNoOffersNoPrice(): void
    {
        self::assertSame([], PuzzleMarketplaceOffer::lowestPricePerCurrency([]));
    }

    public function testCheapestOfferOfTheCurrency(): void
    {
        self::assertSame(['EUR' => 5.0], PuzzleMarketplaceOffer::lowestPricePerCurrency([
            self::offer(12.5, 'EUR'),
            self::offer(5.0, 'EUR'),
            self::offer(20.0, 'EUR'),
        ]));
    }

    public function testCurrenciesAreNeverComparedWithEachOther(): void
    {
        // 100 CZK is cheaper than 5 EUR in no meaningful way - each currency keeps its own lowest price,
        // the one most offers use first, then alphabetically
        self::assertSame(
            ['EUR' => 5.0, 'CZK' => 100.0, 'USD' => 7.0],
            PuzzleMarketplaceOffer::lowestPricePerCurrency([
                self::offer(100.0, 'CZK'),
                self::offer(7.0, 'USD'),
                self::offer(5.0, 'EUR'),
                self::offer(9.0, 'EUR'),
            ]),
        );
    }

    private static function offer(float $price, string $currency): PuzzleMarketplaceOffer
    {
        return new PuzzleMarketplaceOffer(price: $price, currency: $currency, condition: PuzzleCondition::Normal);
    }
}
