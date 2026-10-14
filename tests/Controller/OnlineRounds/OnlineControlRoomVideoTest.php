<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The control room with live video (docs/features/online-competitions/contracts/phase-1b.md stream VM): only with a
 * media server configured (LIVEKIT_*) and the round's video on does the page carry the console - the video value
 * (the hall's token, the studio's invite / end / JSON, the CSRF token), the studio's tool link, the push-to-talk panel
 * and the console's texts. Without it the page is the Phase 1 page: no trace of video, the same with the server off as
 * with the round's switch off, and the parts with video are its only differences. Video costs no statement.
 */
final class OnlineControlRoomVideoTest extends WebTestCase
{
    use LiveVideoTesting;
    use QueryCountAssertions;

    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string PAGE = '/en/online-rounds/' . self::ROUND . '/control';

    protected function setUp(): void
    {
        self::resetLiveVideoDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreLiveVideo();

        parent::tearDown();
    }

    public function testWithoutLiveVideoTheControlRoomIsThePhaseOnePage(): void
    {
        // A media server, the round's video off
        $roundOff = $this->page(roundVideo: false);
        self::assertWithoutVideo($roundOff);

        // No media server, the round's video on
        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $serverOff = $this->page(roundVideo: true);
        self::assertWithoutVideo($serverOff);

        self::assertSame(self::normalised($roundOff), self::normalised($serverOff), 'the same page either way');
    }

    public function testWithLiveVideoTheControlRoomHasTheConsole(): void
    {
        $html = $this->page(roundVideo: true);
        $crawler = new Crawler($html);
        $room = $crawler->filter('[data-controller="online-control"]');

        $video = json_decode((string) $room->attr('data-online-control-video-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($video);
        self::assertSame([
            'token' => '/en/online-rounds/' . self::ROUND . '/video-token',
            'invite' => '/en/online-rounds/' . self::ROUND . '/studio-invites',
            'end' => '/en/online-rounds/' . self::ROUND . '/studio-invites/00000000-0000-0000-0000-000000000000/end',
            'studio' => '/en/online-rounds/' . self::ROUND . '/studio',
        ], $video['urls'] ?? null);
        self::assertSame('00000000-0000-0000-0000-000000000000', $video['placeholder'] ?? null);
        self::assertIsString($video['csrfToken'] ?? null);
        self::assertNotSame('', $video['csrfToken']);
        self::assertSame(25, $video['pageSize'] ?? null);

        // The host's studio opens beside the control room
        $studio = $crawler->filter('[data-online-tool="studio"]');
        self::assertCount(1, $studio);
        self::assertSame('/en/online-rounds/' . self::ROUND . '/studio', $studio->attr('href'));
        self::assertSame('_blank', $studio->attr('target'));

        // Push-to-talk: the microphone off until asked for, the hold button and the latch hidden until then
        foreach (['connection', 'reconnect', 'micEnable', 'hold', 'latch', 'onAir', 'onAirTime', 'talkError', 'othersTalking'] as $part) {
            self::assertCount(1, $crawler->filter(sprintf('[data-online-console="%s"]', $part)), $part);
        }

        self::assertNotNull($crawler->filter('[data-online-console="hold"]')->attr('hidden'));
        self::assertNotNull($crawler->filter('[data-online-console="onAir"]')->attr('hidden'));
        self::assertSame('Turn on the microphone', trim($crawler->filter('[data-online-console="micEnable"]')->text()));

        $texts = json_decode((string) $room->attr('data-online-control-texts-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);
        self::assertSame('Invite to the studio', $texts['video_studio_invite'] ?? null);
        self::assertSame('Invited - %time% left to answer', $texts['video_studio_invited'] ?? null);
        self::assertSame('No video from this phone', $texts['video_tile_no_camera'] ?? null);
    }

    /**
     * Without the video parts the page with video is the page without it - nothing else moves
     */
    public function testTheVideoPartsAreTheOnlyDifference(): void
    {
        $without = $this->page(roundVideo: false);
        self::ensureKernelShutdown();
        $with = $this->page(roundVideo: true);

        $stripped = (string) preg_replace([
            '/ data-online-control-video-value="[^"]*"/',
            '/<a href="[^"]*\/studio" class="btn btn-sm btn-outline-primary" data-online-tool="studio".*?<\/a>/s',
            '/<section class="card shadow-sm mb-4 online-console".*?<\/section>/s',
        ], '', $with);

        self::assertSame(self::normalised($without, collapse: true), self::normalised($stripped, collapse: true));

        // The texts: Phase 1's, then the console's
        $textsWithout = self::texts($without);
        $textsWith = self::texts($with);
        self::assertSame($textsWithout, array_slice($textsWith, 0, count($textsWithout), true));
        self::assertSame([], array_filter(array_keys(array_slice($textsWith, count($textsWithout), null, true)), static fn (string $key): bool => !str_starts_with($key, 'video_')));
    }

    public function testTheVideoCostsNoStatement(): void
    {
        $browser = $this->signedIn();
        $without = $this->statementsOf($browser);

        self::turnRoundVideoOn($this->connection($browser));
        $with = $this->statementsOf($browser);

        self::assertStringContainsString('data-online-control-video-value', (string) $browser->getResponse()->getContent());
        self::assertSame($without, $with, implode("\n", $this->executedSql($browser)));
    }

    private function page(bool $roundVideo): string
    {
        $browser = $this->signedIn();

        if ($roundVideo) {
            self::turnRoundVideoOn($this->connection($browser));
        }

        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();

        return (string) $browser->getResponse()->getContent();
    }

    private function statementsOf(KernelBrowser $browser): int
    {
        // Warm-up: whatever the page caches, the counted request finds it cached
        $browser->request('GET', self::PAGE);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }

    private static function assertWithoutVideo(string $html): void
    {
        foreach (['data-online-control-video-value', 'data-online-console', 'data-online-tool="studio"', '/studio', 'video_studio', 'online-console'] as $trace) {
            self::assertStringNotContainsString($trace, $html, $trace);
        }

        self::assertSame([], array_filter(array_keys(self::texts($html)), static fn (string $key): bool => str_starts_with($key, 'video_')));
    }

    /**
     * @return array<string, mixed>
     */
    private static function texts(string $html): array
    {
        $texts = json_decode((string) (new Crawler($html))->filter('[data-controller="online-control"]')->attr('data-online-control-texts-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);

        /** @var array<string, mixed> $texts */
        return $texts;
    }

    /**
     * The page without what differs per request: the state (times, the Mercure token), the CSRF tokens, the suggested
     * start, the texts (compared on their own)
     */
    private static function normalised(string $html, bool $collapse = false): string
    {
        $html = (string) preg_replace('/<script type="application\/json" data-online-control-target="initialState">.*?<\/script>/s', '<STATE/>', $html);
        $html = (string) preg_replace('/(data-online-control-texts-value|data-online-control-csrf-token-value|(?<=\s)value|(?<=\s)min|(?<=\s)content|(?<=\s)nonce)="[^"]*"/', '$1="X"', $html);

        return $collapse ? (string) preg_replace('/\s+/', ' ', $html) : $html;
    }

    private function signedIn(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        return $browser;
    }

    private function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}
