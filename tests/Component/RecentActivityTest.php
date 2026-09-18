<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use SpeedPuzzling\Web\Component\RecentActivity;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetRecentActivity;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Sentry WEB-BP: the feed ranked the signed-in player against everybody on every
 * puzzle they ever solved just to print "My time", and loaded its items twice.
 */
final class RecentActivityTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use QueryCountAssertions;

    private const int LIMIT = 20;

    public function testMyTimeLinesStayTheSameWithoutTheFullRanking(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Warm-up, so one-off work of a player's first request (locale sync) is not counted
        $this->createLiveComponent(RecentActivity::class, ['limit' => self::LIMIT], $browser)->setRouteLocale('en')->render();

        $component = $this->createLiveComponent(RecentActivity::class, ['limit' => self::LIMIT, 'showLimit' => 4], $browser);
        $component->setRouteLocale('en');

        $this->startCountingQueries($browser);
        $html = $component->render()->toString();

        preg_match_all('~My time: (.+?)</small>~', $html, $matches);
        self::assertNotEmpty($matches[1], 'Fixtures must show at least one "My time" line');
        self::assertSame($this->expectedMyTimes($browser->getContainer()), $matches[1]);

        $sql = $this->executedSql($browser);
        self::assertCount(1, array_filter($sql, static fn (string $query): bool => str_contains($query, 'ORDER BY puzzle_solving_time.tracked_at DESC')), 'The items are loaded once');
        self::assertCount(0, array_filter($sql, static fn (string $query): bool => str_contains($query, 'RANK() OVER')), 'No full ranking for "My time"');
        // profile + user account + items + best times
        $this->assertQueryCountAtMost($browser, 4, 'recent activity, signed in');
    }

    /**
     * The "My time" lines the component rendered with GetRanking::allForPlayer().
     *
     * @return list<string>
     */
    private function expectedMyTimes(ContainerInterface $container): array
    {
        /** @var GetRecentActivity $getRecentActivity */
        $getRecentActivity = $container->get(GetRecentActivity::class);
        /** @var GetRanking $getRanking */
        $getRanking = $container->get(GetRanking::class);
        /** @var PuzzlingTimeFormatter $formatter */
        $formatter = $container->get(PuzzlingTimeFormatter::class);

        $ranking = $getRanking->allForPlayer(PlayerFixture::PLAYER_REGULAR);
        $expected = [];

        foreach ($getRecentActivity->latest(self::LIMIT) as $item) {
            if ($item->playerId !== PlayerFixture::PLAYER_REGULAR && $item->players === null && isset($ranking[$item->puzzleId])) {
                $expected[] = $formatter->formatTime($ranking[$item->puzzleId]->time);
            }
        }

        return $expected;
    }

    /**
     * @return list<string>
     */
    private function executedSql(KernelBrowser $browser): array
    {
        $profile = $browser->getProfile();
        self::assertInstanceOf(Profile::class, $profile);

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        /** @var array<string, list<array{sql: string}>> $queriesByConnection */
        $queriesByConnection = $collector->getQueries();
        $sql = [];

        foreach ($queriesByConnection as $queries) {
            foreach ($queries as $query) {
                $sql[] = $query['sql'];
            }
        }

        return $sql;
    }
}
