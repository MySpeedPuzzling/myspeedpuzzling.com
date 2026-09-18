<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class NotificationsControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string BADGE = 'a[href="/en/notifications"] .navbar-tool-label';

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/notifications');
        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
    }

    /**
     * The page marks every notification read, so the layout's bell badge shows 0 there. The
     * layout used to count the unread notifications again to get that 0 - a second, identical
     * COUNT query on every visit.
     */
    public function testBellBadgeReusesTheCountOfThePage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $unread = (string) $this->unreadCount($connection);
        self::assertNotSame('0', $unread, 'The player needs unread notifications');

        // Other pages count on their own
        $crawler = $browser->request('GET', '/en/marketplace');
        $this->assertResponseIsSuccessful();
        self::assertSame($unread, $crawler->filter(self::BADGE)->text());

        // Unread ones get marked read on the page - the bell shows 0, as it always did
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter(self::BADGE)->text());
        self::assertSame(1, $this->unreadCountQueries($browser));
        self::assertSame(0, $this->unreadCount($connection));

        // Nothing unread any more: still 0 from a single query
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter(self::BADGE)->text());
        self::assertSame(1, $this->unreadCountQueries($browser));
    }

    private function unreadCount(Connection $connection): int
    {
        /** @var int|string $count */
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM notification WHERE player_id = :playerId AND read_at IS NULL',
            ['playerId' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );

        return (int) $count;
    }

    private function unreadCountQueries(KernelBrowser $browser): int
    {
        $profile = $browser->getProfile();
        self::assertInstanceOf(Profile::class, $profile);

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        /** @var array<string, list<array{sql: string}>> $queriesByConnection */
        $queriesByConnection = $collector->getQueries();
        $count = 0;

        foreach ($queriesByConnection as $queries) {
            foreach ($queries as $query) {
                if (str_contains($query['sql'], 'FROM notification') && str_contains($query['sql'], 'COUNT(id)')) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
