<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Watch live" on a round's row of the event and edition pages (templates/event_parts/_round.html.twig,
 * RoundsTimelineBuilder): only while the round's online timing has live video on, a media server is configured and the
 * event is public - and it costs those pages no statement (the flag rides on GetEditionRounds' rounds statement;
 * DetailPagesQueryBudgetTest pins the pages themselves).
 */
final class OnlineWatchLinkTest extends WebTestCase
{
    use LiveVideoTesting;
    use QueryCountAssertions;

    private const string TEST_CUP = '/en/events/online-test-cup';
    private const string SEASON_ONE = '/en/series/moonlight-sprint-league/season-one';
    private const string LINK = '#round-' . OnlineRoundsFixture::ROUND_ONLINE_SOLO . ' [data-round-watch-live]';

    protected function setUp(): void
    {
        self::resetLiveVideoDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreLiveVideo();

        parent::tearDown();
    }

    public function testTheEventPageLinksTheStudioOfARoundWithLiveVideo(): void
    {
        $browser = self::createClient();
        self::publishTheTestCup($this->connection($browser));

        $crawler = $browser->request('GET', self::TEST_CUP);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#round-' . OnlineRoundsFixture::ROUND_ONLINE_SOLO), 'the round is on the page');
        self::assertCount(0, $crawler->filter('[data-round-watch-live]'), 'its video is off');

        self::turnRoundVideoOn($this->connection($browser));
        $crawler = $browser->request('GET', self::TEST_CUP);
        $link = $crawler->filter(self::LINK);
        self::assertCount(1, $link);
        self::assertSame('/en/online-rounds/' . OnlineRoundsFixture::ROUND_ONLINE_SOLO . '/watch', $link->attr('href'));
        self::assertSame('Watch live', trim($link->text()));
        // Coming in three weeks - no pulsing dot before it runs
        self::assertCount(0, $link->filter('.live-dot'));

        // The Czech page links the Czech watch page
        $crawler = $browser->request('GET', '/eventy/online-test-cup');
        self::assertSame('/online-kola/' . OnlineRoundsFixture::ROUND_ONLINE_SOLO . '/sledovat', $crawler->filter(self::LINK)->attr('href'));
    }

    public function testNoLinkWithoutAMediaServerOrOnAPageThatIsNotPublic(): void
    {
        // A draft's page, seen by its organiser
        $browser = self::createClient();
        self::turnRoundVideoOn($this->connection($browser));
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::TEST_CUP);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#round-' . OnlineRoundsFixture::ROUND_ONLINE_SOLO));
        self::assertCount(0, $crawler->filter('[data-round-watch-live]'));

        // No media server (LIVEKIT_* empty - production today)
        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $browser = self::createClient();
        self::publishTheTestCup($this->connection($browser));
        self::turnRoundVideoOn($this->connection($browser));

        $crawler = $browser->request('GET', self::TEST_CUP);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-round-watch-live]'));
    }

    public function testTheEditionPageLinksItTooButNotOnALongPastRound(): void
    {
        $browser = self::createClient();
        // A round 25 days ahead and one 60 days ago, both with online timing and live video
        self::addLiveVideo($this->connection($browser), EventsPageFixture::ROUND_SPRINT_3);
        self::addLiveVideo($this->connection($browser), EventsPageFixture::ROUND_SPRINT_1);

        $crawler = $browser->request('GET', self::SEASON_ONE);

        self::assertResponseIsSuccessful();
        self::assertSame(
            '/en/online-rounds/' . EventsPageFixture::ROUND_SPRINT_3 . '/watch',
            $crawler->filter('#round-' . EventsPageFixture::ROUND_SPRINT_3 . ' [data-round-watch-live]')->attr('href'),
        );
        self::assertCount(0, $crawler->filter('#round-' . EventsPageFixture::ROUND_SPRINT_1 . ' [data-round-watch-live]'));
        self::assertCount(1, $crawler->filter('[data-round-watch-live]'));
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => [null];
        yield 'player' => [PlayerFixture::PLAYER_REGULAR];
    }

    #[DataProvider('provideViewers')]
    public function testTheLinkCostsThePagesNoStatement(null|string $viewer): void
    {
        $browser = self::createClient();
        self::publishTheTestCup($this->connection($browser));

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        }

        $withoutVideo = $this->measure($browser, self::TEST_CUP);
        $editionWithout = $this->measure($browser, self::SEASON_ONE);

        self::turnRoundVideoOn($this->connection($browser));
        self::addLiveVideo($this->connection($browser), EventsPageFixture::ROUND_SPRINT_3);

        self::assertSame($withoutVideo, $this->measure($browser, self::TEST_CUP), 'the event page with "Watch live"');
        self::assertCount(1, $browser->getCrawler()->filter('[data-round-watch-live]'));
        self::assertSame($editionWithout, $this->measure($browser, self::SEASON_ONE), 'the edition page with "Watch live"');
        self::assertCount(1, $browser->getCrawler()->filter('[data-round-watch-live]'));
    }

    private function measure(KernelBrowser $browser, string $url): int
    {
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }

    private static function publishTheTestCup(Connection $connection): void
    {
        $connection->executeStatement('UPDATE competition SET is_draft = false WHERE id = :id', ['id' => OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP]);
    }

    private static function addLiveVideo(Connection $connection, string $roundId): void
    {
        $connection->executeStatement(
            "INSERT INTO online_round (id, round_id, enabled_at, detection_mode, video_enabled, participants_see_each_other, studio_enabled, recording_enabled, unfinished_measure, missing_piece_rule)
             VALUES (:id, :round, " . TestClock::SQL_NOW . ", 'off', true, false, false, false, 'left', 'complete_required')",
            ['id' => Uuid::uuid7()->toString(), 'round' => $roundId],
        );
    }

    private function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}
