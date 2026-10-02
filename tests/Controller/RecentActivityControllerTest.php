<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RecentActivityControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/recent-activity');

        $this->assertResponseIsSuccessful();
    }

    public function testFeedRefreshesThroughTheRingAndItsLabelsCountUp(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/recent-activity');

        $feed = $crawler->filter('[data-controller~="live-refresh"][data-controller~="relative-time"]');
        self::assertCount(1, $feed);
        // 100 rows, no "Show more" row: the status line sits in the gap above the table
        self::assertCount(1, $feed->filter('.live-refresh-anchor > .live-refresh-status-above[data-live-ignore]'));
        self::assertCount(0, $feed->filter('.ra-footer'));
        self::assertCount(0, $crawler->filter('[data-poll]'));
        self::assertMatchesRegularExpression('~^\d{10}$~', (string) $feed->attr('data-relative-time-server-now-value'));
        self::assertSame('en', $feed->attr('data-relative-time-locale-value'));

        /** @var array<string, string> $messages */
        $messages = json_decode((string) $feed->attr('data-relative-time-messages-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['second', 'minute', 'hour', 'day', 'empty'], array_keys($messages));
        self::assertSame('1 second ago|%count% seconds ago', $messages['second']);

        $rows = $feed->filter('tbody tr');
        self::assertGreaterThan(0, $rows->count());
        self::assertCount($rows->count(), $feed->filter('tbody tr time.lb-time-date[data-relative-time]'));
        self::assertCount($rows->count(), array_unique($rows->each(static fn ($row): string => (string) $row->attr('id'))));
        self::assertStringStartsWith('activity-all-', (string) $rows->first()->attr('id'));
        self::assertMatchesRegularExpression('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$~', (string) $feed->filter('time.lb-time-date')->first()->attr('datetime'));
    }

    public function testCountdownTextComesWithTheLocaleOfItsCatalogue(): void
    {
        $browser = self::createClient();

        // Translated only in English so far: a Czech page must pick its plural form by English rules, as PHP does
        $crawler = $browser->request('GET', '/nedavna-aktivita');

        /** @var array<string, array{message: string, locale: string}> $texts */
        $texts = json_decode((string) $crawler->filter('.live-refresh-status')->attr('data-texts'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['running', 'refreshing', 'paused', 'idle', 'failed'], array_keys($texts));
        self::assertSame(['message' => 'Auto-update in 1 second|Auto-update in %count% seconds', 'locale' => 'en'], $texts['running']);
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/recent-activity');

        $this->assertResponseIsSuccessful();
    }
}
