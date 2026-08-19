<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class PuzzleTimesCompetitionBadgeTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testEditionTimeLinksToEditionAndStandaloneTimeLinksToEvent(): void
    {
        // PLAYER_REGULAR on PUZZLE_500_01: TIME_36 (1750 s, fastest) is pointed at an EJJ edition,
        // TIME_09 (1850 s) stays on the standalone WJPC 2024 and is rendered in the "more times"
        // list of the same leaderboard row.
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :timeId',
            ['competitionId' => CompetitionSeriesFixture::EDITION_EJJ_68, 'timeId' => PuzzleSolvingTimeFixture::TIME_36],
        );

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_500_01,
            'piecesCount' => 500,
        ], $client);
        $component->setRouteLocale('en');

        $html = $component->render()->toString();

        // Edition: "<series> · <edition name>" badge, linked straight to the edition page.
        self::assertStringContainsString('Euro Jigsaw Jam · EJJ #68 — February 2026', $html);
        self::assertStringContainsString('href="/en/series/euro-jigsaw-jam-series/ejj-68-february-2026"', $html);

        // Standalone: unchanged shortcut badge linked to the event page.
        self::assertStringContainsString('<i class="bi bi-trophy-fill"></i> WJPC24</span>', $html);
        self::assertStringContainsString('href="/en/events/wjpc-2024"', $html);

        // An edition must never be linked through the bare-slug event route.
        self::assertStringNotContainsString('/en/events/ejj-68-february-2026', $html);
    }
}
