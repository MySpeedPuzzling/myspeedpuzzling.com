<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\CompetitionPick;
use SpeedPuzzling\Web\Value\CompetitionPickKind;

final class CompetitionPickTest extends TestCase
{
    private const string ID = '019a0000-0000-7000-8000-00000000ab12';

    public function testABareUuidIsAOneTimeEvent(): void
    {
        $pick = CompetitionPick::tryFrom(self::ID);

        self::assertNotNull($pick);
        self::assertSame(CompetitionPickKind::Event, $pick->kind);
        self::assertSame(self::ID, $pick->competitionId());
        self::assertNull($pick->seriesId());
    }

    public function testASeriesValueIsASeriesPick(): void
    {
        $pick = CompetitionPick::tryFrom('series:' . self::ID);

        self::assertNotNull($pick);
        self::assertSame(CompetitionPickKind::Series, $pick->kind);
        self::assertNull($pick->competitionId());
        self::assertSame(self::ID, $pick->seriesId());
    }

    public function testAnEditionValueLinksTheEditionExplicitly(): void
    {
        $pick = CompetitionPick::tryFrom('edition:' . self::ID);

        self::assertNotNull($pick);
        self::assertSame(CompetitionPickKind::Edition, $pick->kind);
        self::assertSame(self::ID, $pick->competitionId());
        self::assertNull($pick->seriesId());
    }

    public function testTheIdIsLowerCased(): void
    {
        $pick = CompetitionPick::tryFrom('series:' . strtoupper(self::ID));

        self::assertNotNull($pick);
        self::assertSame(self::ID, $pick->id);
        self::assertSame('series:' . self::ID, $pick->fieldValue());
        self::assertSame(self::ID, CompetitionPick::event(strtoupper(self::ID))->id);
    }

    #[DataProvider('malformedValues')]
    public function testAMalformedValueIsNoPick(null|string $value): void
    {
        self::assertNull(CompetitionPick::tryFrom($value));
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function malformedValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'not a uuid' => ['lantern-weekly-jam'];
        yield 'series without an id' => ['series:'];
        yield 'series with garbage' => ['series:no-uuid'];
        yield 'edition with garbage' => ['edition:123'];
        yield 'unknown prefix' => ['organization:' . self::ID];
        yield 'prefix twice' => ['series:series:' . self::ID];
    }

    #[DataProvider('kinds')]
    public function testFieldValueRoundTrips(CompetitionPick $pick, string $fieldValue): void
    {
        self::assertSame($fieldValue, $pick->fieldValue());

        $parsed = CompetitionPick::tryFrom($fieldValue);
        self::assertNotNull($parsed);
        self::assertTrue($parsed->equals($pick));
    }

    /**
     * @return iterable<string, array{CompetitionPick, string}>
     */
    public static function kinds(): iterable
    {
        yield 'event' => [CompetitionPick::event(self::ID), self::ID];
        yield 'series' => [CompetitionPick::series(self::ID), 'series:' . self::ID];
        yield 'edition' => [CompetitionPick::edition(self::ID), 'edition:' . self::ID];
    }

    public function testEqualsComparesKindAndId(): void
    {
        self::assertTrue(CompetitionPick::series(self::ID)->equals(CompetitionPick::series(self::ID)));
        self::assertFalse(CompetitionPick::series(self::ID)->equals(CompetitionPick::edition(self::ID)));
        self::assertFalse(CompetitionPick::event(self::ID)->equals(CompetitionPick::event('019a0000-0000-7000-8000-00000000ab13')));
    }

    /**
     * The edit form's prefill (H12 12): a series pick shows the series, an explicit edition the edition, a one-time
     * event the event.
     */
    public function testOfTime(): void
    {
        $series = '019a0000-0000-7000-8000-0000000000aa';

        self::assertNull(CompetitionPick::ofTime(null, null, false));
        self::assertTrue(CompetitionPick::series($series)->equals(CompetitionPick::ofTime(self::ID, $series, true) ?? self::fail()));
        self::assertTrue(CompetitionPick::series($series)->equals(CompetitionPick::ofTime(null, $series, false) ?? self::fail()));
        self::assertTrue(CompetitionPick::edition(self::ID)->equals(CompetitionPick::ofTime(self::ID, null, true) ?? self::fail()));
        self::assertTrue(CompetitionPick::event(self::ID)->equals(CompetitionPick::ofTime(self::ID, null, false) ?? self::fail()));
    }

    public function testTheConstructorRefusesAnythingButAUuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CompetitionPick(CompetitionPickKind::Event, 'not-a-uuid');
    }
}
