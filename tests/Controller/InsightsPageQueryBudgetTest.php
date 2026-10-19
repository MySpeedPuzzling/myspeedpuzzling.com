<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Insights page's first response costs a fixed number of statements whatever the player's size (README "Performance":
 * 4-5 statements for the insights themselves, ≤ 1 s, ~60-80 ms server): the cache key (generation + fingerprint, one
 * statement) and, on a miss, InsightsPageLoader's four (solo results, close calls, context, hydrate) - plus the
 * header's profile row and what every signed-in request loads. The test pool is an array, so every request is a miss:
 * the worst case is what is counted.
 */
final class InsightsPageQueryBudgetTest extends WebTestCase
{
    use ClonesSolvingTimes;
    use OverridesFeatureFlagEnv;
    use QueryCountAssertions;

    /** Cache key 1 + solo results, close calls, context, hydrate */
    public const int INSIGHTS = 5;

    /** Account, profile, unread conversations, unread notifications + the header's profile row (GetPlayerProfile::byId) */
    public const int PAGE_OVERHEAD = 5;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testTheFirstResponseCostsTheSameForASmallAndABigPlayer(): void
    {
        $this->overrideFeatureFlagEnv('INSIGHTS_PUBLIC', true);
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // The first request of a session does one-off work (sign-in bookkeeping, a modal claim) - not the page's
        $browser->request('GET', '/en/insights');
        $small = $this->countedRequest($browser);
        $smallSql = $this->executedSql($browser);

        /** @var list<string> $times */
        $times = $browser->getContainer()->get('doctrine.dbal.default_connection')->fetchFirstColumn(
            "SELECT id::text FROM puzzle_solving_time WHERE player_id = :player AND puzzling_type = 'solo' AND seconds_to_solve > 0 ORDER BY id",
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        for ($i = 0; $i < 300; $i++) {
            $this->cloneSolvingTime($times[$i % count($times)], ['days_ago' => $i * 2 + 1, 'first_attempt' => false]);
        }

        $big = $this->countedRequest($browser);

        self::assertSame($small, $big, "A bigger player costs no extra statement\n" . implode("\n", $smallSql) . "\n---\n" . implode("\n", $this->executedSql($browser)));
        self::assertSame(self::INSIGHTS + self::PAGE_OVERHEAD, $big, implode("\n", $this->executedSql($browser)));
    }

    private function countedRequest(KernelBrowser $browser): int
    {
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/insights?period=all');
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }
}
