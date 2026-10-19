<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\Insights\InsightsCriteria;
use SpeedPuzzling\Web\Services\Insights\InsightsPageLoader;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The first response of the Insights page is at most 5 statements whatever the player's size (implementation plan
 * §4.5): solo results, close calls, context (generation + profile card + learning ratios + rating), hydrate.
 */
final class InsightsQueryBudgetTest extends KernelTestCase
{
    use ClonesSolvingTimes;

    public const int BUDGET = 5;

    public function testTheFirstResponseCostsTheSameStatementsForASmallAndABigPlayer(): void
    {
        self::bootKernel();
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        $small = $this->statementsOfALoad(PlayerFixture::PLAYER_REGULAR, isMember: false);

        // Grow the player: 300 more solo results over two years, repeats of their puzzles
        /** @var list<string> $times */
        $times = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            "SELECT id::text FROM puzzle_solving_time WHERE player_id = :player AND puzzling_type = 'solo' AND seconds_to_solve > 0 ORDER BY id",
            ['player' => PlayerFixture::PLAYER_REGULAR],
        );

        for ($i = 0; $i < 300; $i++) {
            $this->cloneSolvingTime($times[$i % count($times)], ['days_ago' => $i * 2 + 1, 'first_attempt' => false]);
        }

        $big = $this->statementsOfALoad(PlayerFixture::PLAYER_REGULAR, isMember: true);

        self::assertLessThanOrEqual(self::BUDGET, count($small), implode("\n", $small));
        // Today: solo results, close calls, context, hydrate
        self::assertCount(4, $small, implode("\n", $small));
        self::assertCount(count($small), $big, implode("\n", $big));
    }

    /**
     * @return list<string>
     */
    private function statementsOfALoad(string $playerId, bool $isMember): array
    {
        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $loader = self::getContainer()->get(InsightsPageLoader::class);
        $criteria = InsightsCriteria::fromUserInput(['period' => 'all'], $isMember);

        // The viewer's own profile (hidden players, allow list) rides on the page's profile row - load it first
        self::getContainer()->get(HiddenPlayers::class)->ids();
        self::getContainer()->get(PrivateProfileAccess::class)->revealedIds();
        $debugDataHolder->reset();

        $page = $loader->load($playerId, $criteria, 'Europe/Prague');

        self::assertNotEmpty($page->puzzles);

        /** @var list<array{sql: string}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];

        return array_values(array_map(
            static fn (array $query): string => substr(preg_replace('/\s+/', ' ', $query['sql']) ?? '', 0, 120),
            array_filter($executed, static fn (array $query): bool => preg_match('/^"?(SAVEPOINT|RELEASE|START|BEGIN|COMMIT)\b/i', $query['sql']) !== 1),
        ));
    }
}
