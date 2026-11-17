<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\AnalyticsStudio;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Analytics studio" on the organiser pages (docs/features/competitions-management/analytics-studio.md §8, S3): the
 * displays page (one link per slugged round), the round stopwatch page and the online control room link the round's
 * studio by the round's id (analytics_studio_round) - `rel="nofollow"`, and at no statement of their own: each page
 * runs the same statements whether its round has a slug (link) or not (no link).
 */
final class AnalyticsStudioLinksTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;
    use QueryCountAssertions;

    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string STOPWATCH = '/en/manage-round-stopwatch/' . OfficialResultsFixture::ROUND_GROUP_A;
    private const string DISPLAYS = '/en/manage-displays/' . self::EVENT;
    private const string ONLINE_EVENT = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string CONTROL_ROOM = '/en/online-rounds/' . OnlineRoundsFixture::ROUND_ONLINE_SOLO . '/control';

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testTheDisplaysPageLinksEverySluggedRound(): void
    {
        $browser = $this->browser();
        [$crawler, $withSlugs] = $this->page($browser, self::DISPLAYS);

        $links = $crawler->filter('[data-analytics-studio-rounds] a[data-analytics-studio-link]');
        self::assertSame(
            ['/en/analytics-studio/' . OfficialResultsFixture::ROUND_GROUP_A, '/en/analytics-studio/' . OfficialResultsFixture::ROUND_GROUP_B, '/en/analytics-studio/' . OfficialResultsFixture::ROUND_PAIRS, '/en/analytics-studio/' . OfficialResultsFixture::ROUND_FINAL, '/en/analytics-studio/' . OfficialResultsFixture::ROUND_PAIRS_FINAL],
            $links->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        self::assertSame('nofollow noopener', $links->first()->attr('rel'));

        // Without slugs: no section, the same statements
        $this->database()->executeStatement('UPDATE competition_round SET slug = NULL WHERE competition_id = :id', ['id' => self::EVENT]);
        [$crawler, $withoutSlugs] = $this->page($browser, self::DISPLAYS);
        self::assertCount(0, $crawler->filter('[data-analytics-studio-rounds]'));
        self::assertSame($withSlugs, $withoutSlugs);
    }

    public function testTheStopwatchPageLinksTheRoundsStudio(): void
    {
        $browser = $this->browser();
        [$crawler, $withSlug] = $this->page($browser, self::STOPWATCH);

        $link = $crawler->filter('[data-stopwatch-tools] a[data-analytics-studio-link]');
        self::assertSame('/en/analytics-studio/' . OfficialResultsFixture::ROUND_GROUP_A, $link->attr('href'));
        self::assertSame('nofollow noopener', $link->attr('rel'));

        $this->database()->executeStatement('UPDATE competition_round SET slug = NULL WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_A]);
        [$crawler, $withoutSlug] = $this->page($browser, self::STOPWATCH);
        self::assertCount(0, $crawler->filter('[data-analytics-studio-link]'));
        self::assertSame($withSlug, $withoutSlug);
    }

    public function testTheControlRoomLinksTheRoundsStudio(): void
    {
        $this->overrideFeatureFlagEnv('ONLINE_ROUNDS_FOR_ORGANISERS', true);
        $browser = $this->browser();
        $this->database()->executeStatement(
            'INSERT INTO competition_maintainer (competition_id, player_id) VALUES (:competition, :player) ON CONFLICT DO NOTHING',
            ['competition' => self::ONLINE_EVENT, 'player' => self::ORGANISER],
        );
        [$crawler, $withSlug] = $this->page($browser, self::CONTROL_ROOM);

        $link = $crawler->filter('[data-online-control-tools] a[data-online-tool="analytics-studio"]');
        self::assertSame('/en/analytics-studio/' . OnlineRoundsFixture::ROUND_ONLINE_SOLO, $link->attr('href'));
        self::assertSame('nofollow noopener', $link->attr('rel'));

        $this->database()->executeStatement('UPDATE competition_round SET slug = NULL WHERE id = :id', ['id' => OnlineRoundsFixture::ROUND_ONLINE_SOLO]);
        [$crawler, $withoutSlug] = $this->page($browser, self::CONTROL_ROOM);
        self::assertCount(0, $crawler->filter('[data-analytics-studio-link]'));
        self::assertSame($withSlug, $withoutSlug);
    }

    /**
     * @return array{Crawler, int}
     */
    private function page(KernelBrowser $browser, string $url): array
    {
        // Once to warm what a first request loads, then counted
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return [$crawler, $this->queryCount($browser)];
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ORGANISER);

        return $browser;
    }
}
