<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The events page costs a fixed number of statements (docs/features/events-page/implementation-plan.md, 4):
 * occurrences, series, going counts; signed in + the viewer's own relations + the permissions statement the ⋯ voters
 * share (GetCompetitionPermissions, memoised) + what every signed-in request loads (account, profile, unread
 * conversations and notifications; admins also the review queue counts). The archive is one statement.
 */
final class EventsPageQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    private const int GUEST = 3;
    // Account, profile, unread conversations, unread notifications
    private const int SIGNED_IN_OVERHEAD = 4;
    // The viewer statement + the permissions statement (the ⋯ voters, workstream C)
    private const int SIGNED_IN_PAGE = self::GUEST + 2;

    /**
     * @return iterable<string, array{null|string, int}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => [null, self::GUEST];
        yield 'player' => [PlayerFixture::PLAYER_WITH_FAVORITES, self::SIGNED_IN_PAGE + self::SIGNED_IN_OVERHEAD];
        // Maintains and created events, follows a series, is going to one
        yield 'maintainer' => [PlayerFixture::PLAYER_REGULAR, self::SIGNED_IN_PAGE + self::SIGNED_IN_OVERHEAD];
        // + the review queue counts of the admin menu
        yield 'admin' => [PlayerFixture::PLAYER_ADMIN, self::SIGNED_IN_PAGE + self::SIGNED_IN_OVERHEAD + 1];
    }

    #[DataProvider('provideViewers')]
    public function testThePageCost(null|string $playerId, int $budget): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        foreach (['/en/events', '/en/events?country=de&view=calendar&q=cup'] as $url) {
            $browser->request('GET', $url);
            $this->startCountingQueries($browser);
            $browser->request('GET', $url);

            self::assertResponseIsSuccessful();
            $this->assertQueryCountAtMost($browser, $budget, ($playerId ?? 'guest') . ' ' . $url);
        }
    }

    public function testTheArchiveIsOneStatement(): void
    {
        $browser = self::createClient();
        $url = '/en/events/archive/' . ((int) self::getContainer()->get(ClockInterface::class)->now()->format('Y') - 1);

        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 1, 'events archive');
    }

    /**
     * Every row asks the voters about itself (Sentry WEB-BZ on the old page): 10 more events and series add nothing
     */
    public function testTenMoreEventsAndSeriesAddNoStatement(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', '/en/events');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/events');
        self::assertResponseIsSuccessful();
        $before = $this->queryCount($browser);

        $this->addListedEvents($browser, 8, 2);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/events');
        self::assertResponseIsSuccessful();

        self::assertSame($before, $this->queryCount($browser), 'Listing 10 more events and series must not add statements');
        self::assertCount(8, $crawler->filter('.ev-row')->reduce(static fn ($row): bool => str_contains($row->text(), 'Query budget event')));
        self::assertSame(8 + 2, substr_count((string) $browser->getResponse()->getContent(), '"n":"Query budget '), 'every one of them is in the index');
    }

    /**
     * Approved standalone events and series, one of the events maintained by PLAYER_WITH_FAVORITES.
     */
    private function addListedEvents(KernelBrowser $browser, int $events, int $series): void
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

        $connection->insert('competition_maintainer', [
            'competition_id' => $firstEventId,
            'player_id' => PlayerFixture::PLAYER_WITH_FAVORITES,
        ]);
    }
}
