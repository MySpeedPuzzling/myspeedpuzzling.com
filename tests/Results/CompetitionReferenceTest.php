<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\CompetitionReference;

final class CompetitionReferenceTest extends TestCase
{
    public function testStandaloneEventLinksTheEventPage(): void
    {
        $competition = new CompetitionReference(name: 'World Jigsaw Puzzle Championship 2024', slug: 'wjpc-2024');

        self::assertSame('World Jigsaw Puzzle Championship 2024', $competition->displayName());
        self::assertSame('event_detail', $competition->routeName());
        self::assertSame(['slug' => 'wjpc-2024'], $competition->routeParameters());
    }

    public function testEditionCarriesItsSeriesNameAndLinksTheEditionPage(): void
    {
        $competition = new CompetitionReference(
            name: '#16 - December 2025',
            slug: '16-december-2025',
            seriesName: 'Piece-off',
            seriesSlug: 'piece-off',
        );

        self::assertSame('Piece-off · #16 - December 2025', $competition->displayName());
        self::assertSame('edition_detail', $competition->routeName());
        self::assertSame(['seriesSlug' => 'piece-off', 'editionSlug' => '16-december-2025'], $competition->routeParameters());
    }

    public function testEditionNamedLikeItsSeriesIsNotNamedTwice(): void
    {
        $competition = new CompetitionReference(
            name: 'euro jigsaw jam',
            slug: 'ejj',
            seriesName: 'Euro Jigsaw Jam',
            seriesSlug: 'euro-jigsaw-jam',
        );

        self::assertSame('euro jigsaw jam', $competition->displayName());
    }

    public function testWholeSeriesLinksTheSeriesPage(): void
    {
        $competition = new CompetitionReference(name: 'Piece-off', slug: 'piece-off', isSeries: true);

        self::assertSame('Piece-off', $competition->displayName());
        self::assertSame('competition_series_detail', $competition->routeName());
        self::assertSame(['slug' => 'piece-off'], $competition->routeParameters());
    }

    public function testWithoutTheSlugsThereIsNoLink(): void
    {
        $withoutSlug = new CompetitionReference(name: 'Old Event', slug: null);
        $editionOfSeriesWithoutSlug = new CompetitionReference(name: '#1', slug: 'first', seriesName: 'Cup', seriesSlug: null);

        self::assertNull($withoutSlug->routeName());
        self::assertSame([], $withoutSlug->routeParameters());
        self::assertNull($editionOfSeriesWithoutSlug->routeName());
        self::assertSame([], $editionOfSeriesWithoutSlug->routeParameters());
    }
}
