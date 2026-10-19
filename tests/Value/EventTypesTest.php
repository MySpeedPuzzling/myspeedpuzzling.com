<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\EventType;
use SpeedPuzzling\Web\Value\EventTypes;

final class EventTypesTest extends TestCase
{
    public function testNoneIsNotSet(): void
    {
        self::assertNull(EventTypes::of([]));
        self::assertNull(EventTypes::fromStored([]));
        self::assertNull(EventTypes::fromJson('[]'));
        self::assertNull(EventTypes::fromJson(null));
        self::assertSame([], EventTypes::toStoredOrEmpty(null));
    }

    public function testEachKindOnceInTheEnumsOrder(): void
    {
        $types = EventTypes::of([EventType::PuzzleSwap, EventType::Competition, EventType::PuzzleSwap]);

        self::assertNotNull($types);
        self::assertSame(['competition', 'puzzle_swap'], $types->toStored());
    }

    public function testUnknownStoredValuesAreLeftOut(): void
    {
        self::assertSame(['workshop'], EventTypes::fromJson('["party", "workshop"]')?->toStored());
        self::assertNull(EventTypes::fromJson('["party"]'));
    }

    public function testAPlainCompetitionShowsNothingAMixShowsEveryKind(): void
    {
        self::assertSame([], EventTypes::shownOf(null));
        self::assertSame([], EventTypes::shownOf(EventTypes::of([EventType::Competition])));
        self::assertSame(
            [EventType::Competition, EventType::CasualPuzzling],
            EventTypes::shownOf(EventTypes::of([EventType::CasualPuzzling, EventType::Competition])),
        );
    }

    public function testSame(): void
    {
        self::assertTrue(EventTypes::same(null, null));
        self::assertTrue(EventTypes::same(EventTypes::of([EventType::Workshop]), EventTypes::of([EventType::Workshop])));
        self::assertFalse(EventTypes::same(null, EventTypes::of([EventType::Competition])));
    }
}
