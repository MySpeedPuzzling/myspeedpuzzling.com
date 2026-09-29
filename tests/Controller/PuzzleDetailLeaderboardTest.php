<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Component\PuzzleTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\LeaderboardSeeding;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Popular puzzle pages used to carry the whole leaderboard (up to ~1,700 rows / 8 MB of HTML).
 * The page renders the top PuzzleTimes::DEFAULT_LIMIT rows; everybody else stays reachable
 * through Live Component buttons, never through crawlable pagination URLs.
 */
final class PuzzleDetailLeaderboardTest extends WebTestCase
{
    use LeaderboardSeeding;

    public function testPuzzlePageRendersAtMostDefaultLimitLeaderboardRows(): void
    {
        $browser = self::createClient();
        $solvers = $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, PuzzleTimes::DEFAULT_LIMIT + 1);
        $slowestSolver = $solvers[PuzzleTimes::DEFAULT_LIMIT];

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04);

        $this->assertResponseIsSuccessful();

        $rows = $crawler->filter('tr[id^="leaderboard-row-"]');
        self::assertCount(PuzzleTimes::DEFAULT_LIMIT, $rows);
        self::assertSame('leaderboard-row-' . $solvers[0], $rows->first()->attr('id'));
        self::assertSame('leaderboard-row-' . $solvers[PuzzleTimes::DEFAULT_LIMIT - 1], $rows->last()->attr('id'));

        // The slowest solver is nowhere in the HTML - no row, no profile link
        self::assertStringNotContainsString($slowestSolver, (string) $browser->getResponse()->getContent());

        // ...but still counted and reachable, through buttons only
        self::assertStringContainsString('Solo (101)', $crawler->filter('.puzzle-category-types')->text());
        self::assertSame('Show 1 more', trim($crawler->filter('button[data-live-action-param="showMore"]')->text()));
        self::assertSame('Show all (101)', trim($crawler->filter('button[data-live-action-param="showAll"]')->text()));
    }
}
