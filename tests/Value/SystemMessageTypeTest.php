<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\SystemMessageType;

final class SystemMessageTypeTest extends TestCase
{
    public function testListingReservedForViewer(): void
    {
        $key = SystemMessageType::resolveTranslationKey(
            SystemMessageType::ListingReserved,
            'player-1',
            'player-1',
        );

        self::assertSame('messaging.system.listing_reserved_for_you', $key);
    }

    public function testListingReservedForThisPuzzler(): void
    {
        $key = SystemMessageType::resolveTranslationKey(
            SystemMessageType::ListingReserved,
            'player-1',
            'player-2',
            'player-1',
        );

        self::assertSame('messaging.system.listing_reserved_for_this_puzzler', $key);
    }

    public function testListingReservedForSomeoneElse(): void
    {
        $key = SystemMessageType::resolveTranslationKey(
            SystemMessageType::ListingReserved,
            'player-1',
            'player-2',
            'player-3',
        );

        self::assertSame('messaging.system.listing_reserved_for_someone_else', $key);
    }

    public function testListingReservedWithNoTarget(): void
    {
        $key = SystemMessageType::resolveTranslationKey(
            SystemMessageType::ListingReserved,
            null,
            'player-1',
        );

        self::assertSame('messaging.system.listing_reserved', $key);
    }

    public function testListingReservationRemoved(): void
    {
        $key = SystemMessageType::resolveTranslationKey(
            SystemMessageType::ListingReservationRemoved,
            null,
            'player-1',
        );

        self::assertSame('messaging.system.listing_reservation_removed', $key);
    }

    public function testListingSoldToViewer(): void
    {
        $key = SystemMessageType::resolveTranslationKey(
            SystemMessageType::ListingSold,
            'player-1',
            'player-1',
        );

        self::assertSame('messaging.system.listing_sold_to_you', $key);
    }

    public function testListingSoldToThisPuzzler(): void
    {
        $key = SystemMessageType::resolveTranslationKey(
            SystemMessageType::ListingSold,
            'player-1',
            'player-2',
            'player-1',
        );

        self::assertSame('messaging.system.listing_sold_to_this_puzzler', $key);
    }

    public function testListingSoldToSomeoneElse(): void
    {
        $key = SystemMessageType::resolveTranslationKey(
            SystemMessageType::ListingSold,
            'player-1',
            'player-2',
            'player-3',
        );

        self::assertSame('messaging.system.listing_sold_to_someone_else', $key);
    }

    public function testListingSoldWithNoTarget(): void
    {
        $key = SystemMessageType::resolveTranslationKey(
            SystemMessageType::ListingSold,
            null,
            'player-1',
        );

        self::assertSame('messaging.system.listing_sold', $key);
    }
}
