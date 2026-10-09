<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\MarkSolvingTimeSuspiciousDirectly;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A series pick (docs/features/events-page/high-frequency-series.md) is a normal result of its series - and the read
 * side has no chokepoint: every reader joins the event by hand (SeriesPickQueryCoverageTest guards the files). This
 * test proves the surfaces: a **series-level** time (the player picked the series, no edition identified) shows the
 * series' badge linking the series page, an **automatic** time (the edition MySpeedPuzzling found) shows "series ·
 * edition" linking the edition page - exactly like an edition the player picked. **Add every new surface showing a
 * time's event here.**
 *
 * John Doe saved the series-level time, Michael Johnson the automatic one - each the fastest 500-piece solo time.
 */
final class SeriesPickCanaryTest extends WebTestCase
{
    private const string SERIES_LEVEL_PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const string SERIES_LEVEL_USER = PlayerFixture::PLAYER_REGULAR_USER_ID;
    private const string AUTOMATIC_PLAYER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string AUTOMATIC_USER = PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID;
    private const string SERIES_LABEL = 'Lantern Weekly Jam';
    private const string EDITION_LABEL = 'Lantern Weekly Jam · Jam No. 154';

    private KernelBrowser $browser;
    private SeriesEditionScenario $scenario;
    private string $seriesLevelPuzzleId;
    private string $automaticPuzzleId;
    private string $seriesLevelTimeId;
    private string $automaticTimeId;
    private string $seriesUrl;
    private string $editionUrl;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->scenario = new SeriesEditionScenario(self::getContainer());

        $seriesId = $this->scenario->series();
        $this->seriesLevelPuzzleId = $this->scenario->puzzle('Copper Lighthouse');
        $this->automaticPuzzleId = $this->scenario->puzzle('Starry Harbor');
        $editionId = $this->scenario->edition($seriesId, 'Jam No. 154', '2026-10-07');
        $this->scenario->round($editionId, RoundCategory::Solo, '2026-10-07 19:00', puzzleIds: [$this->automaticPuzzleId]);

        // Far from the only edition: not identified, a series-level time
        $this->seriesLevelTimeId = $this->add(self::SERIES_LEVEL_USER, $this->seriesLevelPuzzleId, '2026-09-01 20:00:00', $seriesId);
        // The jam's puzzle on its day: matched by the puzzle
        $this->automaticTimeId = $this->add(self::AUTOMATIC_USER, $this->automaticPuzzleId, '2026-10-07 20:00:00', $seriesId);

        self::assertNull($this->scenario->link($this->seriesLevelTimeId)['competition_id']);
        self::assertSame($editionId, $this->scenario->link($this->automaticTimeId)['competition_id']);

        /** @var array{series_slug: string, edition_slug: string} $slugs */
        $slugs = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT cs.slug AS series_slug, c.slug AS edition_slug FROM competition c INNER JOIN competition_series cs ON cs.id = c.series_id WHERE c.id = :id',
            ['id' => $editionId],
        );
        $this->seriesUrl = '/en/series/' . $slugs['series_slug'];
        $this->editionUrl = $this->seriesUrl . '/' . $slugs['edition_slug'];
    }

    public function testProfileResults(): void
    {
        $this->assertSeriesBadge($this->page('/en/player-profile/' . self::SERIES_LEVEL_PLAYER));
        $this->assertEditionBadge($this->page('/en/player-profile/' . self::AUTOMATIC_PLAYER));
    }

    public function testPuzzleLeaderboard(): void
    {
        $this->assertSeriesBadge($this->page('/en/puzzle/' . $this->seriesLevelPuzzleId));
        $this->assertEditionBadge($this->page('/en/puzzle/' . $this->automaticPuzzleId));
    }

    public function testLadder(): void
    {
        $ladder = $this->page('/en/ladder/solo/500-pieces');

        $this->assertSeriesBadge($ladder);
        $this->assertEditionBadge($ladder);
    }

    public function testRecentActivity(): void
    {
        $activity = $this->page('/en/recent-activity');

        $this->assertSeriesBadge($activity);
        $this->assertEditionBadge($activity);
    }

    public function testResultDetail(): void
    {
        $this->assertSeriesBadge($this->page('/en/result/' . $this->seriesLevelTimeId));
        $this->assertEditionBadge($this->page('/en/result/' . $this->automaticTimeId));
    }

    /**
     * "Review your results": a second copy of each time (another comment, so not the resend safety net) - the copies
     * name the series of a series-level time, the edition of an automatic one
     */
    public function testDuplicateReview(): void
    {
        $this->add(self::SERIES_LEVEL_USER, $this->seriesLevelPuzzleId, '2026-09-01 21:00:00', $this->seriesIdOf($this->seriesLevelTimeId), 'Saved again');
        $this->add(self::AUTOMATIC_USER, $this->automaticPuzzleId, '2026-10-07 21:00:00', $this->seriesIdOf($this->automaticTimeId), 'Saved again');

        TestingLogin::asPlayer($this->browser, self::SERIES_LEVEL_PLAYER);
        $crawler = $this->browser->request('GET', '/en/review-results');
        self::assertResponseIsSuccessful();
        self::assertSame([self::SERIES_LABEL, self::SERIES_LABEL], $this->copyEvents($crawler));

        TestingLogin::asPlayer($this->browser, self::AUTOMATIC_PLAYER);
        $crawler = $this->browser->request('GET', '/en/review-results');
        self::assertResponseIsSuccessful();
        self::assertSame(['Jam No. 154', 'Jam No. 154'], $this->copyEvents($crawler));
    }

    public function testTimeVerificationCaseCard(): void
    {
        foreach ([$this->seriesLevelTimeId, $this->automaticTimeId] as $timeId) {
            $this->scenario->dispatch(new MarkSolvingTimeSuspiciousDirectly(
                timeId: $timeId,
                decidedById: SeriesEditionScenario::ADMIN_PLAYER_ID,
                note: null,
                reasonCodes: null,
                toldByHand: false,
            ));
        }

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->browser->request('GET', '/admin/time-verification?tab=marked');
        self::assertResponseIsSuccessful();
        $text = (string) preg_replace('/\s+/', ' ', $crawler->filter('body')->text());

        self::assertStringContainsString('Event: ' . self::SERIES_LABEL . ' ', $text, 'The series-level time names its series');
        self::assertStringContainsString('Event: Jam No. 154 - Solo 2026-10-07', $text, 'The automatic time names its edition and round');
    }

    private function page(string $url): Crawler
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function assertSeriesBadge(Crawler $crawler): void
    {
        $badges = $crawler->filter(sprintf('a[href="%s"] .badge', $this->seriesUrl));

        self::assertGreaterThan(0, $badges->count(), 'No series badge for the series-level time.');
        self::assertSame(self::SERIES_LABEL, trim($badges->first()->text()));
    }

    private function assertEditionBadge(Crawler $crawler): void
    {
        $badges = $crawler->filter(sprintf('a[href="%s"] .badge', $this->editionUrl));

        self::assertGreaterThan(0, $badges->count(), 'No edition badge for the automatic time.');
        self::assertSame(self::EDITION_LABEL, trim($badges->first()->text()));
    }

    /**
     * @return list<string>
     */
    private function copyEvents(Crawler $crawler): array
    {
        $sets = implode('', $crawler->filter('[data-testid="duplicate-set"]')->each(static fn (Crawler $set): string => $set->html()));
        preg_match_all('~<i class="bi bi-trophy me-1"></i>([^<]+)~', $sets, $events);

        return array_map(static fn (string $event): string => html_entity_decode(trim($event)), $events[1]);
    }

    private function seriesIdOf(string $timeId): string
    {
        return (string) $this->scenario->link($timeId)['competition_series_id'];
    }

    private function add(string $userId, string $puzzleId, string $finishedAt, string $seriesId, null|string $comment = null): string
    {
        $timeId = Uuid::uuid7();

        $this->scenario->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: $userId === self::SERIES_LEVEL_USER ? '00:15:00' : '00:15:01',
            comment: $comment,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable($finishedAt),
            firstAttempt: false,
            unboxed: false,
            seriesId: $seriesId,
        ));

        return $timeId->toString();
    }
}
