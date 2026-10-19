<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Component\Insights;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The Insights live component: the URL state normalized and reflected (members-only values of a free player dropped),
 * the period select, the skill-map drill-down with its own hydrate (members only), and nothing at all while
 * InsightsFeature hides the page - its /_components endpoint included.
 */
final class InsightsComponentTest extends WebTestCase
{
    use ClonesSolvingTimes;
    use InteractsWithLiveComponents;
    use OverridesFeatureFlagEnv;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testNothingRendersWhileTheFlagHidesIt(): void
    {
        $client = $this->client(flag: false, player: PlayerFixture::PLAYER_REGULAR);

        $component = $this->createLiveComponent('Insights', [], $client);
        $component->setRouteLocale('en');

        self::assertCount(0, $component->render()->crawler()->filter('.ins-sec'));
        self::assertCount(0, $component->render()->crawler()->filter('[data-testid="insights-filters"]'));
    }

    public function testAFreePlayerKeepsOnlyTheFreeFilters(): void
    {
        $client = $this->client(flag: true, player: PlayerFixture::PLAYER_REGULAR);
        $this->growResults($client, PlayerFixture::PLAYER_REGULAR, 20);

        $component = $this->createLiveComponent('Insights', [
            'period' => 'all',
            'results' => 'first',
            'weekdays' => ['6', '7'],
            'pieces' => '500',
        ], $client);
        $component->setRouteLocale('en');
        $crawler = $component->render()->crawler();

        /** @var Insights $insights */
        $insights = $component->component();
        self::assertSame('6m', $insights->when, 'members-only period dropped to the free default');
        self::assertNull($insights->period);
        self::assertNull($insights->results);
        self::assertSame([], $insights->weekdays);
        self::assertSame('500', $insights->pieces, 'the piece count is free');
        self::assertCount(1, $crawler->filter('.ins-filters__stripped'));

        // Free players get no drill-down even when they ask the endpoint for one
        $component->call('toggleDrill', ['segment' => 'difficulty:3']);
        self::assertCount(0, $component->render()->crawler()->filter('[data-testid="insights-drill"]'));
    }

    public function testAMemberPicksAPastYearAndDrillsIntoARow(): void
    {
        $client = $this->client(flag: true, player: PlayerFixture::PLAYER_WITH_STRIPE);
        $this->growResults($client, PlayerFixture::PLAYER_WITH_STRIPE, 40);

        $component = $this->createLiveComponent('Insights', ['period' => 'all'], $client);
        $component->setRouteLocale('en');
        $rows = $component->render()->crawler()->filter('.ins-srow');
        self::assertGreaterThan(0, $rows->count(), 'the grown member has a skill map');
        $segmentId = (string) $rows->first()->attr('data-live-segment-param');

        /** @var Insights $insights */
        $insights = $component->component();
        self::assertSame('all', $insights->when);

        $component->call('toggleDrill', ['segment' => $segmentId]);
        $drill = $component->render()->crawler()->filter('[data-testid="insights-drill"]');
        self::assertCount(1, $drill);
        self::assertGreaterThan(0, $drill->filter('.ins-row')->count());

        // Tapping the open row closes it
        $component->call('toggleDrill', ['segment' => $segmentId]);
        self::assertCount(0, $component->render()->crawler()->filter('[data-testid="insights-drill"]'));

        // The period select: a past year sets period + year, a preset clears the year again
        $year = (string) ((int) self::getContainer()->get(ClockInterface::class)->now()->format('Y') - 1);
        $component->set('when', Insights::WHEN_PAST_YEAR_PREFIX . $year);
        /** @var Insights $insights */
        $insights = $component->component();
        self::assertSame('past_year', $insights->period);
        self::assertSame($year, $insights->year);

        $component->set('when', '12m');
        /** @var Insights $insights */
        $insights = $component->component();
        self::assertSame('12m', $insights->period);
        self::assertNull($insights->year);
    }

    private function client(bool $flag, string $player): KernelBrowser
    {
        $this->overrideFeatureFlagEnv('INSIGHTS_PUBLIC', $flag);
        $client = self::createClient();
        TestingLogin::asPlayer($client, $player);

        return $client;
    }

    private function growResults(KernelBrowser $client, string $playerId, int $count): void
    {
        /** @var Connection $connection */
        $connection = $client->getContainer()->get(Connection::class);

        /** @var list<string> $times */
        $times = $connection->fetchFirstColumn(
            "SELECT id::text FROM puzzle_solving_time WHERE player_id = :player AND puzzling_type = 'solo' AND seconds_to_solve > 0 ORDER BY id",
            ['player' => $playerId],
        );

        for ($i = 0; $i < $count; $i++) {
            $this->cloneSolvingTime($times[$i % count($times)], [
                'days_ago' => ($i * 9) % 700 + 1,
                // Half of them first tries: "your usual" (the form) rests on first tries
                'first_attempt' => $i % 2 === 0,
                'seconds_to_solve' => 1800 + ($i * 137) % 1500,
            ]);
        }

        $connection->executeStatement(
            'UPDATE puzzle_statistics SET median_time_solo = 2400, solved_times_solo_count = GREATEST(solved_times_solo_count, 10)
             WHERE puzzle_id IN (SELECT puzzle_id FROM puzzle_solving_time WHERE player_id = :player)',
            ['player' => $playerId],
        );
    }
}
