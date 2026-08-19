<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetRecentActivity;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetRecentActivityTest extends KernelTestCase
{
    private GetRecentActivity $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetRecentActivity::class);
        $this->database = $container->get(Connection::class);
    }

    public function testLatestExposesSeriesOfEditionAndKeepsStandaloneShape(): void
    {
        // TIME_36 (PLAYER_REGULAR, public profile, no competition) is pointed at an EJJ edition;
        // TIME_09 stays linked to the standalone WJPC 2024.
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :timeId',
            ['competitionId' => CompetitionSeriesFixture::EDITION_EJJ_68, 'timeId' => PuzzleSolvingTimeFixture::TIME_36],
        );

        $byTimeId = [];
        foreach ($this->query->latest(200) as $item) {
            $byTimeId[$item->id] = $item;
        }

        $edition = $byTimeId[PuzzleSolvingTimeFixture::TIME_36];
        self::assertSame('EJJ #68 — February 2026', $edition->competitionName);
        self::assertSame('ejj-68-february-2026', $edition->competitionSlug);
        self::assertSame('Euro Jigsaw Jam', $edition->competitionSeriesName);
        self::assertSame('euro-jigsaw-jam-series', $edition->competitionSeriesSlug);
        self::assertNull($edition->competitionSeriesShortcut);

        $standalone = $byTimeId[PuzzleSolvingTimeFixture::TIME_09];
        self::assertSame('WJPC 2024', $standalone->competitionName);
        self::assertSame('WJPC24', $standalone->competitionShortcut);
        self::assertSame('wjpc-2024', $standalone->competitionSlug);
        self::assertNull($standalone->competitionSeriesName);
        self::assertNull($standalone->competitionSeriesShortcut);
        self::assertNull($standalone->competitionSeriesSlug);
    }

    public function testForPlayerExposesSeriesOfEdition(): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :timeId',
            ['competitionId' => CompetitionSeriesFixture::EDITION_EJJ_68, 'timeId' => PuzzleSolvingTimeFixture::TIME_36],
        );

        $byTimeId = [];
        foreach ($this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, 200) as $item) {
            $byTimeId[$item->id] = $item;
        }

        $edition = $byTimeId[PuzzleSolvingTimeFixture::TIME_36];
        self::assertSame('Euro Jigsaw Jam', $edition->competitionSeriesName);
        self::assertSame('euro-jigsaw-jam-series', $edition->competitionSeriesSlug);
        self::assertSame('ejj-68-february-2026', $edition->competitionSlug);
    }
}
