<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The stars and ⋯ buttons of the events page rows (docs/features/events-page/README.md, "Follow" and "The ⋯ menu").
 * The follow/unfollow endpoints themselves are covered by EventsControllerTest.
 */
final class EventFollowStarTest extends WebTestCase
{
    public function testPlayersGetStarForms(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/events?country=de');
        self::assertResponseIsSuccessful();

        $riverside = $this->star($crawler, 'competition:' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN);
        self::assertSame('false', $riverside->attr('aria-pressed'));
        self::assertSame('Follow ' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN_NAME, $riverside->attr('aria-label'));

        $form = $riverside->ancestors()->filter('form')->first();
        self::assertSame('/en/follow-event', $form->attr('action'));
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertSame('event-follow', $form->attr('data-controller'));
        self::assertSame('/en/unfollow-event', $form->attr('data-unfollow-url'));
        self::assertNotEmpty($form->filter('input[name="_token"]')->attr('value'));
        self::assertSame('/en/events?country=de', $form->filter('input[name="return"]')->attr('value'));

        // Followed: pressed, the form unfollows - on every star of that target (an edition's star follows its series)
        $harbor = $crawler->filter('button.ev-star[data-follow-target="series:' . EventsPageFixture::SERIES_HARBOR_NIGHTS . '"]');
        self::assertGreaterThan(1, $harbor->count());
        $harbor->each(static function (Crawler $star): void {
            self::assertSame('true', $star->attr('aria-pressed'));
            self::assertSame('Following ' . EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME, $star->attr('aria-label'));
            self::assertSame('/en/unfollow-event', $star->ancestors()->filter('form')->first()->attr('action'));
        });

        // No sign-in note for players
        self::assertCount(0, $crawler->filter('template[data-ev-signin-note-template]'));
        self::assertCount(0, $crawler->filter('.ev-star-guest'));
    }

    public function testGuestsGetASignInStarInstead(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/events');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('form.ev-star-form'));
        self::assertCount(0, $crawler->filter('a[data-ev-manage-menu]'));

        $stars = $crawler->filter('a.ev-star-guest');
        self::assertGreaterThan(0, $stars->count());
        $stars->each(static function (Crawler $star): void {
            self::assertStringStartsWith('/login?return=', (string) $star->attr('href'));
            self::assertSame('event-follow#guest', $star->attr('data-action'));
        });

        $note = $crawler->filter('template[data-ev-signin-note-template]');
        self::assertCount(1, $note);
        self::assertStringContainsString('Sign in to follow events and series.', (string) $note->html());
    }

    public function testNoStarOnPastRows(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/events');

        $crawler->filter('.ev-row[data-ev-status="past"]')->each(static function (Crawler $row): void {
            self::assertCount(0, $row->filter('.ev-star'));
        });
        self::assertCount(0, $crawler->filter('.ev-line .ev-star'));
    }

    public function testNoStarOnRowsWaitingForApproval(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/events');

        $pending = $crawler->filter('.ev-row-pending');
        self::assertGreaterThan(0, $pending->count(), 'admins see items waiting for approval');
        $pending->each(static function (Crawler $row): void {
            self::assertCount(0, $row->filter('.ev-star'), $row->text());
        });
    }

    public function testTheManageButtonOnlyOnRowsTheViewerManages(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/events');

        $buttons = $crawler->filter('a[data-ev-manage-menu]');
        self::assertCount(1, $buttons, 'PLAYER_REGULAR manages one public event: Euro Jigsaw Jam');
        self::assertStringStartsWith('/en/event-actions/competition/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE . '?', (string) $buttons->attr('href'));
        self::assertSame('dialog', $buttons->attr('aria-haspopup'));
        self::assertSame('false', $buttons->attr('aria-expanded'));
        self::assertCount(1, $crawler->filter('[data-controller="event-manage-menu"]'));
    }

    public function testAdminsGetTheManageButtonOnEveryRow(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/events');

        $rows = $crawler->filter('.ev-row');
        self::assertGreaterThan(0, $rows->count());
        $rows->each(static function (Crawler $row): void {
            self::assertCount(1, $row->filter('a[data-ev-manage-menu]'), $row->text());
        });

        // A series row's ⋯ manages the series
        self::assertGreaterThan(0, $crawler->filter('a[data-ev-manage-menu][href^="/en/event-actions/series/' . EventsPageFixture::SERIES_HARBOR_NIGHTS . '"]')->count());
    }

    public function testYourEventsMarks(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/events');

        $marks = $crawler->filter('.ev-mark')->each(static fn (Crawler $mark): string => trim($mark->text()));
        self::assertContains('Following', $marks);
        self::assertGreaterThan(0, $crawler->filter('.ev-your-event .ev-mark-following')->count());
    }

    private function star(Crawler $crawler, string $target): Crawler
    {
        $star = $crawler->filter('button.ev-star[data-follow-target="' . $target . '"]');
        self::assertGreaterThan(0, $star->count(), $target);

        return $star->first();
    }
}
