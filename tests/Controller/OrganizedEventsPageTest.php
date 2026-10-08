<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "You organize" (docs/features/events-page/README.md): the header button and the organized_events page share one
 * rule, and every item carries its status and its actions.
 */
final class OrganizedEventsPageTest extends WebTestCase
{
    public function testTheHeaderCountEqualsThePagesItems(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/events');
        self::assertResponseIsSuccessful();

        $button = $crawler->filter('a.ev-organize-button');
        self::assertCount(1, $button);
        self::assertSame('/en/you-organize', $button->attr('href'));
        self::assertStringContainsString('You organize', $button->text());
        $count = (int) $button->filter('.ev-organize-count')->text();
        self::assertSame(3, $count);

        $crawler = $browser->request('GET', '/en/you-organize');
        self::assertResponseIsSuccessful();
        self::assertCount($count, $crawler->filter('[data-organized-id]'));
        self::assertSelectorExists('meta[name="robots"][content="noindex, follow"]');
        self::assertSame('/en/events', $crawler->filter('.breadcrumb a')->attr('href'));
    }

    public function testARejectedEventShowsItsReason(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/you-organize');

        $garden = $this->item($crawler, EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED);
        self::assertSame('Rejected', $garden->filter('.ev-badge')->text());
        self::assertStringContainsString('ev-badge-rejected', (string) $garden->filter('.ev-badge')->attr('class'));
        self::assertSame('Reason: ' . EventsPageFixture::GARDEN_SWAP_REJECTION_REASON, $garden->filter('.ev-organized-reason')->text());

        // Rejected first, then waiting for approval
        $ids = $crawler->filter('[data-organized-id]')->each(static fn (Crawler $item): string => (string) $item->attr('data-organized-id'));
        self::assertSame(EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED, $ids[0]);
        self::assertSame(CompetitionFixture::COMPETITION_UNAPPROVED, $ids[1]);
        self::assertSame('Waiting for approval', $this->item($crawler, CompetitionFixture::COMPETITION_UNAPPROVED)->filter('.ev-badge')->text());
    }

    public function testEveryItemCarriesItsActionsBackToThisPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/you-organize');
        $item = $this->item($crawler, CompetitionFixture::COMPETITION_RECURRING_ONLINE);

        $edit = $item->filter('a.ev-action')->first();
        self::assertSame('Edit event', $edit->text());
        self::assertStringContainsString('/en/edit-event/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE, (string) $edit->attr('href'));
        parse_str((string) parse_url((string) $edit->attr('href'), PHP_URL_QUERY), $query);
        self::assertSame('/en/you-organize', $query['return'] ?? null);
        self::assertSame('You organize', $query['return_title'] ?? null);

        // The creator deletes it, confirmed in place, back to this page
        $delete = $item->filter('details.ev-confirm form');
        self::assertSame('/en/delete-event/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE, $delete->attr('action'));
        self::assertSame('/en/you-organize', $delete->filter('input[name="return"]')->attr('value'));
        self::assertStringContainsString('cannot be undone', $delete->text());

        // No approval for organisers
        self::assertCount(0, $crawler->filter('form[action*="/admin/"]'));
    }

    public function testAMaintainerOfASingleEditionSeesOnlyThatEdition(): void
    {
        $browser = self::createClient();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('competition_maintainer', [
            'competition_id' => EventsPageFixture::EDITION_HARBOR_1,
            'player_id' => PlayerFixture::PLAYER_WITH_FAVORITES,
        ]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/events');
        self::assertSame('1', $crawler->filter('.ev-organize-button .ev-organize-count')->text());

        $crawler = $browser->request('GET', '/en/you-organize');
        self::assertSame([EventsPageFixture::EDITION_HARBOR_1], $crawler->filter('[data-organized-id]')->each(static fn (Crawler $item): string => (string) $item->attr('data-organized-id')));

        $item = $this->item($crawler, EventsPageFixture::EDITION_HARBOR_1);
        self::assertStringContainsString(EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME, $item->filter('.ev-organized-name')->text());
        self::assertStringStartsWith('Edition', $item->filter('.ev-organized-sub')->text());
        self::assertSame('Upcoming', $item->filter('.ev-badge')->text());
        // A maintainer, not the owner: no Delete
        self::assertCount(0, $item->filter('details.ev-confirm'));
        self::assertGreaterThan(0, $item->filter('a.ev-action')->count());
    }

    public function testAdminsSeeTheirOwnItemsWithApproval(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/you-organize');
        self::assertResponseIsSuccessful();

        $harbor = $this->item($crawler, EventsPageFixture::SERIES_HARBOR_NIGHTS);
        self::assertStringStartsWith('Series', $harbor->filter('.ev-organized-sub')->text());
        self::assertStringContainsString('6 editions', $harbor->filter('.ev-organized-sub')->text());
        self::assertSame('Manage series', $harbor->filter('a.ev-action')->first()->text());

        $pending = $this->item($crawler, CompetitionSeriesFixture::SERIES_UNAPPROVED);
        self::assertSame('Waiting for approval', $pending->filter('.ev-badge')->text());
        self::assertCount(1, $pending->filter('form[action="/admin/series/' . CompetitionSeriesFixture::SERIES_UNAPPROVED . '/approve"]'));

        // Somebody else's event is not the admin's to list here
        self::assertCount(0, $crawler->filter('[data-organized-id="' . EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED . '"]'));
    }

    public function testAPlayerWhoOrganisesNothingHasNoButtonAndAnEmptyPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $crawler = $browser->request('GET', '/en/events');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ev-organize-button'));
        // No ⋯ and no menu host either
        self::assertCount(0, $crawler->filter('a[data-ev-manage-menu]'));
        self::assertCount(0, $crawler->filter('[data-controller="event-manage-menu"]'));

        $crawler = $browser->request('GET', '/en/you-organize');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-organized-id]'));
        self::assertSelectorTextContains('.ev-organized-empty', "You don't organise any event yet.");
    }

    public function testGuestsHaveNoButton(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/events');
        self::assertCount(0, $crawler->filter('.ev-organize-button'));
    }

    private function item(Crawler $crawler, string $id): Crawler
    {
        $item = $crawler->filter('[data-organized-id="' . $id . '"]');
        self::assertCount(1, $item, $id);

        return $item;
    }
}
