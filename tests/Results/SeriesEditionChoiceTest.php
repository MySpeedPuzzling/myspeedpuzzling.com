<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\SeriesEditionChoice;

final class SeriesEditionChoiceTest extends TestCase
{
    public function testEditionNamedAfterItsSeriesIsNotPrefixedTwice(): void
    {
        self::assertSame('Lantern Weekly Jam #164', $this->choice('Lantern Weekly Jam #164')->label());
        self::assertSame('lantern weekly jam - 8 October 2026', $this->choice('lantern weekly jam - 8 October 2026')->label());
    }

    public function testEditionNotMentioningItsSeriesGetsItInFront(): void
    {
        self::assertSame('Lantern Weekly Jam · Spring Final', $this->choice('Spring Final')->label());
    }

    private function choice(string $name): SeriesEditionChoice
    {
        return new SeriesEditionChoice(
            id: '018d0000-0000-0000-0000-000000000001',
            name: $name,
            seriesId: '018d0000-0000-0000-0000-000000000002',
            seriesName: 'Lantern Weekly Jam',
            seriesShortcut: null,
            logo: null,
            seriesLogo: null,
            location: null,
            locationCountryCode: null,
            dayFrom: null,
            dayTo: null,
        );
    }
}
