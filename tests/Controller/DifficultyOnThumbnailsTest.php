<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Component\ActivityCalendar;
use SpeedPuzzling\Web\Component\LadderTable;
use SpeedPuzzling\Web\Component\MarketplaceListing;
use SpeedPuzzling\Web\Component\MostSolvedPuzzles;
use SpeedPuzzling\Web\Component\RecentActivity;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The difficulty tier on the corner of puzzle thumbnails (_difficulty_corner.html.twig via ResolveDifficultyTiers):
 * every listed puzzle for a member, nothing for a free player or a guest - the viewer decides.
 */
final class DifficultyOnThumbnailsTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string MEMBER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string FREE = PlayerFixture::PLAYER_REGULAR;

    public function testRecentActivity(): void
    {
        foreach ($this->viewers() as $viewer => $isMember) {
            $crawler = $this->component(RecentActivity::class, ['limit' => 20], $viewer);
            $rows = $crawler->filter('tr[id^="activity-"]');

            self::assertGreaterThan(0, $rows->count());
            $this->assertCorners($isMember ? $rows->count() : 0, $crawler, $viewer);

            if ($isMember) {
                self::assertNotSame([], $this->ratedCorners($crawler), 'Some fixture puzzles are rated');
            }
        }
    }

    public function testLadder(): void
    {
        foreach ($this->viewers() as $viewer => $isMember) {
            $crawler = $this->component(LadderTable::class, ['type' => 'solo', 'piecesCount' => 500, 'limit' => 10], $viewer);
            $rows = $crawler->filter('.ps-table tbody tr');

            self::assertGreaterThan(0, $rows->count());
            $this->assertCorners($isMember ? $rows->count() : 0, $crawler, $viewer);
        }
    }

    public function testMostSolvedPuzzles(): void
    {
        foreach ($this->viewers() as $viewer => $isMember) {
            $crawler = $this->component(MostSolvedPuzzles::class, ['timespan' => 'all_time'], $viewer);
            $rows = $crawler->filter('tbody tr');

            self::assertGreaterThan(0, $rows->count());
            $this->assertCorners($isMember ? $rows->count() : 0, $crawler, $viewer);
        }
    }

    public function testMarketplaceCards(): void
    {
        foreach ($this->viewers() as $viewer => $isMember) {
            $crawler = $this->component(MarketplaceListing::class, [], $viewer);
            $cards = $crawler->filter('[id^="marketplace-listing-"]');

            self::assertGreaterThan(0, $cards->count());
            $this->assertCorners($isMember ? $cards->count() : 0, $crawler, $viewer);
        }
    }

    public function testActivityCalendarSelectedDay(): void
    {
        $finishedAt = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COALESCE(finished_at, tracked_at) FROM puzzle_solving_time WHERE player_id = :player ORDER BY tracked_at DESC LIMIT 1',
            ['player' => self::FREE],
        );
        self::assertIsString($finishedAt);
        $day = new DateTimeImmutable($finishedAt);
        self::ensureKernelShutdown();

        foreach ($this->viewers() as $viewer => $isMember) {
            $crawler = $this->component(ActivityCalendar::class, [
                'playerId' => self::FREE,
                'year' => (int) $day->format('Y'),
                'month' => (int) $day->format('n'),
                'selectedDay' => $day->format('Y-m-d'),
            ], $viewer);
            $solves = $crawler->filter('li a.diff-corner-host');

            self::assertGreaterThan(0, $solves->count());
            $this->assertCorners($isMember ? $solves->count() : 0, $crawler, $viewer);
        }
    }

    public function testRoundResultsShowTheTierOnlyOnARevealedPicture(): void
    {
        foreach ($this->viewers() as $viewer => $isMember) {
            $browser = $this->browser($viewer);
            $connection = self::getContainer()->get(Connection::class);
            $connection->executeStatement(
                "UPDATE competition_round SET starts_at = NOW() - INTERVAL '2 days' WHERE id = :id",
                ['id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
            );
            // Of the round's two puzzles only one has a picture
            $connection->executeStatement("UPDATE puzzle SET image = 'box.jpg' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_500_01]);

            $crawler = $browser->request('GET', '/en/events/wjpc-2024/results/qualification-round');
            self::assertResponseIsSuccessful();

            $this->assertCorners($isMember ? 1 : 0, $crawler, $viewer);
            self::ensureKernelShutdown();
        }
    }

    public function testResultDetail(): void
    {
        foreach ($this->viewers() as $viewer => $isMember) {
            $browser = $this->browser($viewer);

            $crawler = $browser->request('GET', '/en/result/' . PuzzleSolvingTimeFixture::TIME_01, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
            self::assertResponseIsSuccessful();

            $this->assertCorners($isMember ? 1 : 0, $crawler->filter('.pr-puzzle'), $viewer);
            self::ensureKernelShutdown();
        }
    }

    /**
     * @return iterable<string, bool> viewer (player id, '' = guest) => is a member
     */
    private function viewers(): iterable
    {
        yield self::MEMBER => true;
        yield self::FREE => false;
        yield '' => false;
    }

    private function browser(string $viewer): KernelBrowser
    {
        $browser = self::createClient();
        self::getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();

        if ($viewer !== '') {
            TestingLogin::asPlayer($browser, $viewer);
        }

        return $browser;
    }

    /**
     * @param class-string $component
     * @param array<string, mixed> $props
     */
    private function component(string $component, array $props, string $viewer): Crawler
    {
        $live = $this->createLiveComponent($component, $props, $this->browser($viewer));
        $live->setRouteLocale('en');
        $crawler = $live->render()->crawler();
        self::ensureKernelShutdown();

        return $crawler;
    }

    private function assertCorners(int $expected, Crawler $crawler, string $viewer): void
    {
        self::assertCount($expected, $crawler->filter('[data-testid="difficulty-corner"]'), $viewer === '' ? 'guest' : $viewer);
    }

    /**
     * @return list<string> tier names other than "Unknown"
     */
    private function ratedCorners(Crawler $crawler): array
    {
        return array_values(array_filter(
            $crawler->filter('[data-testid="difficulty-corner"]')->each(static fn (Crawler $corner): string => (string) $corner->attr('title')),
            static fn (string $name): bool => $name !== 'Unknown',
        ));
    }
}
