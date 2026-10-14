<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Controller\Compete\CompetePageController;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The participant page's live video parts (docs/features/online-competitions/contracts/phase-1b.md stream VP): only
 * with a media server configured (LIVEKIT_*) does the page carry them - the video value (the token and studio-answer
 * endpoints, the round's watch page), the quiet status line, the studio invitation, the host speaking, "tap to hear",
 * the second screen and its offer, the studio panel, the strip of the others and the texts. The round's own switch is
 * read live from the state (the parts are there whether it is on or off). Without a media server the page is the
 * Phase 1 page: no trace of video, and the parts with video are its only differences. Video costs no statement.
 */
final class CompetePageVideoTest extends WebTestCase
{
    use LiveVideoTesting;
    use QueryCountAssertions;

    private const string PAGE = '/en/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS;

    // Every trace the video parts leave in the page
    private const array TRACES = [
        'data-compete-video-value',
        'data-compete-video=',
        'compete-video-status',
        'compete-banner--studio',
        'videoAudioTap',
        'compete-voice',
        'compete-second-offer',
        'second_screen',
        'compete-studio',
        'compete-strip',
        'compete-audio',
        '/watch',
    ];

    protected function setUp(): void
    {
        self::resetLiveVideoDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreLiveVideo();

        parent::tearDown();
    }

    public function testWithoutAMediaServerThePageHasNoTraceOfVideo(): void
    {
        $this->turnLiveVideoOff();
        $html = $this->page();

        foreach (self::TRACES as $trace) {
            self::assertStringNotContainsString($trace, $html, $trace);
        }

        self::assertSame([], array_values(array_filter(array_keys(self::texts($html)), static fn (string $key): bool => str_starts_with($key, 'video_'))));
    }

    public function testWithAMediaServerThePageCarriesItsVideoParts(): void
    {
        $html = $this->page();
        $crawler = new Crawler($html);
        $root = $crawler->filter('.compete');

        $video = json_decode((string) $root->attr('data-compete-video-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($video);
        self::assertSame([
            'token' => '/api/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS . '/video-token',
            'studioAnswer' => '/api/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS . '/studio-invites/' . CompetePageController::STUDIO_INVITE_PLACEHOLDER,
            'watch' => '/en/online-rounds/' . OnlineRoundsFixture::ROUND_ONLINE_SOLO . '/watch',
        ], $video['urls'] ?? null);
        self::assertSame(CompetePageController::STUDIO_INVITE_PLACEHOLDER, $video['placeholder'] ?? null);

        // The containers the runtime fills, the buttons it disables
        foreach (['audio', 'strip', 'host', 'studio', 'inviteJoin', 'inviteDecline'] as $part) {
            self::assertCount(1, $crawler->filter(sprintf('[data-compete-video="%s"]', $part)), $part);
        }

        // Everything starts hidden - the page shows what the round's state and the room say. The host speaking: a pill on
        // the camera preview, a line on the second screen
        foreach (['videoInvite' => 1, 'videoInviteElsewhere' => 1, 'videoAudioTap' => 1, 'videoSpeaking' => 2, 'videoStudio' => 1, 'videoStrip' => 1, 'videoWatchLink' => 1] as $part => $count) {
            $elements = $crawler->filter(sprintf('[data-compete-show="%s"]', $part));
            self::assertCount($count, $elements, $part);
            $elements->each(static function (Crawler $element) use ($part): void {
                self::assertNotNull($element->attr('hidden'), $part);
            });
        }

        self::assertCount(1, $crawler->filter('.compete-camera [data-compete-show="videoSpeaking"]'), 'on the camera preview - nothing moves');

        // "Use as a second screen" on the checklist and after a takeover
        self::assertCount(2, $crawler->filter('[data-compete-show="videoSecondOffer"][hidden] [data-action="compete#useAsSecondScreen"]'));

        // The second screen: the clock, the host, the studio on its watch page - never Finished
        $second = $crawler->filter('[data-compete-screen="second_screen"]');
        self::assertCount(1, $second);
        self::assertNotNull($second->attr('hidden'));
        self::assertCount(1, $second->filter('[data-compete-field="elapsed"]'));
        self::assertCount(0, $second->filter('[data-action*="compete#finish"]'));
        $watch = $second->filter('a');
        self::assertSame('/en/online-rounds/' . OnlineRoundsFixture::ROUND_ONLINE_SOLO . '/watch', $watch->attr('href'));
        self::assertSame('false', $watch->attr('data-turbo'));
        self::assertSame('noopener noreferrer', $watch->attr('rel'), 'the personal link never leaves as a referrer');
        self::assertStringContainsString('Finished stays on the phone with the camera', $second->text());

        $texts = self::texts($html);
        self::assertSame('Video on', $texts['video_status_live'] ?? null);
        self::assertSame('The studio has moved on - thanks!', $texts['video_outcome_moved_on'] ?? null);
        self::assertSame(['message' => '1 other participant|%count% other participants', 'locale' => 'en'], $texts['video_strip_title'] ?? null);
    }

    /**
     * Without the video parts the page with video is the page without it - nothing else moves
     */
    public function testTheVideoPartsAreTheOnlyDifference(): void
    {
        $with = $this->page();
        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $without = $this->page();

        $stripped = (string) preg_replace([
            '/ data-compete-video-value="[^"]*"/',
            '/<span class="compete-video-status"[^>]*><\/span>/',
            '/<section class="compete-banner compete-banner--studio".*?<\/section>/s',
            '/<p class="compete-banner compete-banner--studio-elsewhere".*?<\/p>/s',
            '/<button type="button" class="compete-banner compete-banner--wake" data-compete-show="videoAudioTap".*?<\/button>/s',
            '/<p class="compete-voice[^"]*".*?<\/p>/s',
            '/<div class="compete-second-offer".*?<\/div>/s',
            '/<section class="compete-screen compete-screen--second".*?<\/section>/s',
            '/<section class="compete-studio".*?<\/section>/s',
            '/<aside class="compete-strip".*?<\/aside>/s',
            '/<div class="compete-audio"[^>]*><\/div>/',
        ], '', $with);

        self::assertSame(self::normalised($without), self::normalised($stripped));

        // The texts: Phase 1's, then the video ones
        $textsWithout = self::texts($without);
        $textsWith = self::texts($with);
        self::assertSame($textsWithout, array_slice($textsWith, 0, count($textsWithout), true));
        self::assertSame([], array_values(array_filter(array_keys(array_slice($textsWith, count($textsWithout), null, true)), static fn (string $key): bool => !str_starts_with($key, 'video_'))));
    }

    public function testTheRoundsOwnSwitchComesWithTheStateAndTheVideoCostsNoStatement(): void
    {
        $browser = self::createClient();
        self::turnRoundVideoOn($this->connection($browser), seeEachOther: true);
        $with = $this->statementsOf($browser);
        $sqlWith = $this->executedSql($browser);
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('data-compete-video-value', $content);
        $state = json_decode((new Crawler($content))->filter('script[data-compete-target="initialState"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $round = $state['round'] ?? null;
        self::assertIsArray($round);
        $settings = $round['settings'] ?? null;
        self::assertIsArray($settings);
        self::assertTrue($settings['video'] ?? null);
        self::assertTrue($settings['seeEachOther'] ?? null);

        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $other = self::createClient();
        self::turnRoundVideoOn($this->connection($other), seeEachOther: true);
        $without = $this->statementsOf($other);

        self::assertStringNotContainsString('data-compete-video-value', (string) $other->getResponse()->getContent());
        self::assertSame($without, $with, implode("\n", $sqlWith) . "\n---\n" . implode("\n", $this->executedSql($other)));
    }

    private function page(): string
    {
        $browser = self::createClient();
        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();

        return (string) $browser->getResponse()->getContent();
    }

    private function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
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

    /**
     * @return array<string, mixed>
     */
    private static function texts(string $html): array
    {
        $texts = json_decode((string) (new Crawler($html))->filter('.compete')->attr('data-compete-texts-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);

        /** @var array<string, mixed> $texts */
        return $texts;
    }

    /**
     * The page without what differs per request (the state: times, the Mercure token; nonces) and without the texts
     * (compared on their own), whitespace collapsed
     */
    private static function normalised(string $html): string
    {
        $html = (string) preg_replace('/<script type="application\/json" data-compete-target="initialState">.*?<\/script>/s', '<STATE/>', $html);
        $html = (string) preg_replace('/(data-compete-texts-value|(?<=\s)nonce|(?<=\s)content)="[^"]*"/', '$1="X"', $html);

        return (string) preg_replace('/\s+/', ' ', $html);
    }
}
