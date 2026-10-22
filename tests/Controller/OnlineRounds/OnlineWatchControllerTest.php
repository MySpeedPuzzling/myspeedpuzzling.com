<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Controller\OnlineRounds\OnlineWatchController;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `GET /en/online-rounds/{roundId}/watch` (contract phase-1b.md §5 `online_watch`): the public watch page of a round's
 * studio. Who sees it (OnlineWatchGate): anybody for a public event, only its editors for a draft, a pending or a
 * rejected one; a round without online timing has none. No live video = a page saying so. Never indexed, never
 * cached, `?obs=1` the video alone. The Online Test Cup is a draft with its round's video off (OnlineRoundsFixture) -
 * each test publishes it / turns the video on in its own transaction.
 */
final class OnlineWatchControllerTest extends WebTestCase
{
    use LiveVideoTesting;

    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string URL = '/en/online-rounds/' . self::ROUND . '/watch';

    protected function setUp(): void
    {
        self::resetLiveVideoDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreLiveVideo();

        parent::tearDown();
    }

    public function testAnybodyWatchesAPublicRoundsStudio(): void
    {
        $browser = self::createClient();
        self::publish($this->connection($browser));
        self::turnRoundVideoOn($this->connection($browser));

        $crawler = $browser->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $response = $browser->getResponse();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(0, $crawler->filter('link[rel="canonical"]'), 'never indexed - no canonical either');
        // A guest gets no session for it
        self::assertSame([], $response->headers->getCookies());

        $page = $crawler->filter('[data-controller="online-watch"]');
        self::assertCount(1, $page);
        self::assertSame('Online Test Cup', trim($crawler->filter('.online-watch-event')->text()));
        self::assertSame('Online Round', trim($crawler->filter('h1.online-watch-round')->text()));
        self::assertCount(1, $crawler->filter('.online-watch-curtain'));
        self::assertCount(1, $crawler->filter('.online-watch-clock'));
        self::assertCount(0, $crawler->filter('.online-watch-not-public'));
        self::assertCount(0, $crawler->filter('.online-watch--obs'));

        $urls = self::jsonAttribute($page->attr('data-online-watch-urls-value'));
        self::assertSame('/en/online-rounds/' . self::ROUND . '/watch/token', $urls['token']);
        self::assertSame('/-/time', $urls['time']);
        self::assertSame('/round-stopwatch-state/' . self::ROUND, $urls['state']);
        self::assertSame('/round-stopwatch/' . self::ROUND, $page->attr('data-online-watch-topic-value'));
        self::assertSame('false', $page->attr('data-online-watch-obs-value'));

        $stopwatch = self::jsonAttribute($page->attr('data-online-watch-stopwatch-value'));
        self::assertSame(self::ROUND, $stopwatch['roundId']);
        self::assertArrayHasKey('asOfMs', $stopwatch);

        $texts = self::jsonAttribute($page->attr('data-online-watch-texts-value'));
        self::assertSame('Not live yet', $texts['not_live']);
        self::assertIsArray($texts['watching']);

        // The planned start in the round's own zone, named
        self::assertStringContainsString('Planned start:', $crawler->filter('.online-watch-planned')->text());
        self::assertStringContainsString('Central European', $crawler->filter('.online-watch-planned')->text());
    }

    public function testEveryLanguageHasTheRoute(): void
    {
        $browser = self::createClient();
        self::publish($this->connection($browser));
        self::turnRoundVideoOn($this->connection($browser));

        foreach (['/online-kola/' . self::ROUND . '/sledovat', '/de/online-rounds/' . self::ROUND . '/watch', '/ja/online-rounds/' . self::ROUND . '/watch'] as $url) {
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertCount(1, $crawler->filter('[data-controller="online-watch"]'), $url);
        }
    }

    public function testNoLiveVideoIsAPageSayingSo(): void
    {
        $browser = self::createClient();
        self::publish($this->connection($browser));

        // The round's video is off
        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'No live video for this round');
        self::assertCount(0, $crawler->filter('[data-controller="online-watch"]'));
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('/en/events/online-test-cup', $crawler->filter('.online-watch-unavailable a.btn')->attr('href'));

        // Under OBS: an empty transparent page instead of the text
        $crawler = $browser->request('GET', self::URL . '?obs=1');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.online-watch--obs[data-watch-state="unavailable"]'));
        self::assertCount(0, $crawler->filter('h1'));

        // No media server (LIVEKIT_* empty - production today), the round's video on
        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $browser = self::createClient();
        self::publish($this->connection($browser));
        self::turnRoundVideoOn($this->connection($browser));

        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'No live video for this round');
        self::assertCount(0, $crawler->filter('[data-controller="online-watch"]'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideHiddenEvents(): iterable
    {
        yield 'a draft' => ['UPDATE competition SET is_draft = true WHERE id = :id'];
        yield 'waiting for approval' => ['UPDATE competition SET is_draft = false, approved_at = NULL, approved_by_player_id = NULL WHERE id = :id'];
        yield 'rejected' => ['UPDATE competition SET is_draft = false, rejected_at = ' . TestClock::SQL_NOW . ' WHERE id = :id'];
    }

    #[DataProvider('provideHiddenEvents')]
    public function testAnEventThatIsNotPublicIsOnlyForItsEditors(string $statement): void
    {
        $browser = self::createClient();
        $this->connection($browser)->executeStatement($statement, ['id' => OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP]);
        self::turnRoundVideoOn($this->connection($browser));

        $browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(404);

        // Olivia takes part, she does not edit it
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(404);

        // Its organiser previews it - told who else can see it
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="online-watch"]'));
        self::assertCount(1, $crawler->filter('.online-watch-not-public'));
    }

    public function testOnlyARoundWithOnlineTimingHasAWatchPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/online-rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/watch');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/online-rounds/018d0050-0000-0000-0000-00000000dead/watch');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheObsViewIsTheVideoAlone(): void
    {
        $browser = self::createClient();
        self::publish($this->connection($browser));
        self::turnRoundVideoOn($this->connection($browser));

        $crawler = $browser->request('GET', self::URL . '?obs=1');

        self::assertResponseIsSuccessful();
        $page = $crawler->filter('[data-controller="online-watch"]');
        self::assertCount(1, $page);
        self::assertStringContainsString('online-watch--obs', (string) $page->attr('class'));
        self::assertSame('true', $page->attr('data-online-watch-obs-value'));
        self::assertCount(1, $crawler->filter('.online-watch-stage'));

        // Nothing over or around the video: no bar, no curtain, no buttons, no clock, no site header or footer
        foreach (['.online-watch-bar', '.online-watch-curtain', '.online-watch-sound', '.online-watch-mute', '.online-watch-clock', 'header.sticky-top', 'footer.site-footer'] as $selector) {
            self::assertCount(0, $crawler->filter($selector), $selector);
        }

        self::assertStringContainsString('background:transparent!important', (string) $browser->getResponse()->getContent());
    }

    public function testTheOrganisersYoutubeStreamIsOfferedForAFullStudio(): void
    {
        $browser = self::createClient();
        self::publish($this->connection($browser));
        self::turnRoundVideoOn($this->connection($browser));

        $crawler = $browser->request('GET', self::URL);
        self::assertCount(0, $crawler->filter('[data-online-watch-target="youtube"]'), 'none set - none offered');

        $this->connection($browser)->update('online_round', ['youtube_url' => 'https://www.youtube.com/live/abc123'], ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO]);
        $crawler = $browser->request('GET', self::URL);
        $link = $crawler->filter('[data-online-watch-target="youtube"]');
        self::assertCount(1, $link);
        self::assertSame('https://www.youtube.com/live/abc123', $link->attr('href'));
        self::assertSame('noopener noreferrer', $link->attr('rel'));

        // Never anything else than YouTube over https
        $this->connection($browser)->update('online_round', ['youtube_url' => 'javascript:alert(1)'], ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO]);
        $crawler = $browser->request('GET', self::URL);
        self::assertCount(0, $crawler->filter('[data-online-watch-target="youtube"]'));
    }

    /**
     * @return iterable<string, array{null|string, null|string}>
     */
    public static function provideYoutubeAddresses(): iterable
    {
        yield 'none' => [null, null];
        yield 'a live stream' => ['https://www.youtube.com/live/abc123', 'https://www.youtube.com/live/abc123'];
        yield 'a video' => ['https://youtube.com/watch?v=abc123', 'https://youtube.com/watch?v=abc123'];
        yield 'the phone site' => ['https://m.youtube.com/watch?v=abc123', 'https://m.youtube.com/watch?v=abc123'];
        yield 'a short link' => ['https://youtu.be/abc123', 'https://youtu.be/abc123'];
        yield 'plain http' => ['http://www.youtube.com/live/abc123', null];
        yield 'another site' => ['https://www.twitch.tv/somebody', null];
        yield 'a look-alike host' => ['https://youtube.com.example.org/live', null];
        yield 'a host ending like it' => ['https://notyoutube.com/live', null];
        yield 'credentials' => ['https://user:pass@www.youtube.com/live/abc', null];
        yield 'a script' => ['javascript:alert(1)', null];
        yield 'no address' => ['youtube', null];
    }

    #[DataProvider('provideYoutubeAddresses')]
    public function testOnlyAYoutubeAddressOverHttpsIsLinked(null|string $stored, null|string $linked): void
    {
        self::assertSame($linked, OnlineWatchController::youtubeLink($stored));
    }

    private static function publish(Connection $connection): void
    {
        $connection->executeStatement('UPDATE competition SET is_draft = false WHERE id = :id', ['id' => OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonAttribute(null|string $value): array
    {
        $decoded = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}
