<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Component\EventsListing;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class EventsControllerTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use QueryCountAssertions;

    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/events');

        $this->assertResponseIsSuccessful();
    }

    /**
     * Every event and series card asks the edit/delete voters about itself (Sentry
     * WEB-BZ): the page must cost the same number of queries at 10 more cards.
     */
    public function testListingQueryCountDoesNotGrowWithTheNumberOfEvents(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        // Warm-up, so one-off work of a player's first request is not counted
        $browser->request('GET', '/en/events');
        $this->assertResponseIsSuccessful();

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/events');
        $this->assertResponseIsSuccessful();
        $before = $this->queryCount($browser);

        $maintainedEventId = $this->addListedEvents($browser, 8, 2);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/events');
        $this->assertResponseIsSuccessful();

        self::assertSame($before, $this->queryCount($browser), 'Listing 10 more event/series cards must not add queries');

        $listing = $crawler->filter('[data-live-name-value="EventsListing"]');
        self::assertCount(8, $listing->filter('h3:contains("Query budget event")'));
        // The maintained event still gets its edit button, the others do not
        self::assertCount(1, $listing->filter(sprintf('a[href*="/en/edit-event/%s"]', $maintainedEventId)));
        self::assertCount(1, $listing->filter('a[href*="/en/edit-event/"]'));
    }

    /**
     * Month navigation re-renders the whole listing (Sentry WEB-C0 / WEB-C2).
     */
    public function testCalendarMonthNavigationQueryCountDoesNotGrowWithTheNumberOfEvents(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $component = $this->createLiveComponent(EventsListing::class, ['showCalendar' => true], $browser);
        $component->setRouteLocale('en');
        $component->render();

        $this->startCountingQueries($browser);
        $component->call('nextMonth');
        $before = $this->queryCount($browser);

        $this->addListedEvents($browser, 8, 2);

        $this->startCountingQueries($browser);
        $component->call('prevMonth');
        $html = $component->render()->toString();

        self::assertSame($before, $this->queryCount($browser), 'Listing 10 more event/series cards must not add queries');
        self::assertSame(8, substr_count($html, 'Query budget event'));
    }

    /**
     * Adds approved standalone events and series (all of them listed), one of the
     * events maintained by PLAYER_WITH_FAVORITES; returns that event's id.
     */
    private function addListedEvents(KernelBrowser $browser, int $events, int $series): string
    {
        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $firstEventId = null;

        for ($i = 1; $i <= $events; $i++) {
            $eventId = Uuid::uuid7()->toString();
            $firstEventId ??= $eventId;

            $connection->executeStatement(
                "INSERT INTO competition (id, name, location, location_country_code, date_from, date_to, is_online, approved_at, created_at)
                 VALUES (:id, :name, 'Prague', 'cz', NOW() + make_interval(days => :days), NOW() + make_interval(days => :days), false, NOW(), NOW())",
                ['id' => $eventId, 'name' => 'Query budget event ' . $i, 'days' => 40 + $i],
            );
        }

        for ($i = 1; $i <= $series; $i++) {
            $connection->executeStatement(
                'INSERT INTO competition_series (id, name, is_online, approved_at, added_by_player_id) VALUES (:id, :name, false, NOW(), :owner)',
                ['id' => Uuid::uuid7()->toString(), 'name' => 'Query budget series ' . $i, 'owner' => PlayerFixture::PLAYER_ADMIN],
            );
        }

        assert($firstEventId !== null);

        $connection->insert('competition_maintainer', [
            'competition_id' => $firstEventId,
            'player_id' => PlayerFixture::PLAYER_WITH_FAVORITES,
        ]);

        return $firstEventId;
    }
}
