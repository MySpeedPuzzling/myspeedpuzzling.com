<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\NavigationLaunch;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * What /en/hub costs in statements per viewer, chrome included. The eagerly rendered RecentActivity feed is part of
 * the page and counted; the lazy components are requests of their own and are not.
 *
 * Measured on a76cd36b (P0-02, 2026-10-12; docs/features/navigation/delivery/baseline.md) and pinned exactly. Budgets
 * only go down; every change carries a comment naming the requirement ID and why. A higher number is a bug to explain.
 * The second request is counted: the first warms the caches and claims an announcement modal, the second claimed
 * none in any row (no player_modal_impression statement).
 */
final class HubQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    // The feed (RecentActivity); the guest chrome costs nothing
    private const int GUEST_PAGE = 1;
    // The session's user refresh (user_account)
    private const int ACCOUNT = 1;
    // The viewer's profile row (RetrieveLoggedUserProfile)
    private const int PROFILE_ROW = 1;
    // The Inbox badge: one statement for the unread conversations, message requests and notifications (INB-02: 2 → 1,
    // it replaced the toolbar's two unread counts)
    private const int INBOX = 1;
    // The admin key's review queue counts (admin_queue_counts(), moderators and admins only)
    private const int QUEUE_COUNTS = 1;
    // The feed + the "Review your results" counts, the getting-started progress and the viewer's best times of the
    // feed's puzzles
    private const int SIGNED_IN_PAGE = self::GUEST_PAGE + 3;
    // The difficulty tiers of the feed's puzzles (members only)
    private const int MEMBER_TIERS = 1;

    private const int SIGNED_IN = self::SIGNED_IN_PAGE + self::ACCOUNT + self::PROFILE_ROW + self::INBOX;

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => [NavigationViewer::GUEST, self::GUEST_PAGE];
        yield 'player' => [NavigationViewer::PLAYER, self::SIGNED_IN];
        // INB-04b: the eager feed is the Favorites one (Everyone loads lazily) - one statement like everybody's feed, as the
        // favorites come from the profile row; its "N new" on the tab counts the same rows (FavoritesFeed)
        yield 'player with favorites' => [NavigationViewer::PLAYER_WITH_FAVORITES, self::SIGNED_IN];
        yield 'member' => [NavigationViewer::MEMBER, self::SIGNED_IN + self::MEMBER_TIERS];
        // The role-pure member (MEMBER is also an event owner); added after P0-02 for the per-role checks
        yield 'member only' => [NavigationViewer::MEMBER_ONLY, self::SIGNED_IN + self::MEMBER_TIERS];
        yield 'organiser' => [NavigationViewer::ORGANISER, self::SIGNED_IN];
        // A non-member moderator: no tiers, the queue counts
        yield 'moderator' => [NavigationViewer::MODERATOR, self::SIGNED_IN + self::QUEUE_COUNTS];
        // The admin is a member: the tiers and the queue counts
        yield 'admin' => [NavigationViewer::ADMIN, self::SIGNED_IN + self::MEMBER_TIERS + self::QUEUE_COUNTS];
    }

    #[DataProvider('provideViewers')]
    public function testTheHubCost(string $role, int $statements): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);

        $browser->request('GET', '/en/hub');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();

        self::assertSame($statements, $this->queryCount($browser), $role . " /en/hub ran:\n" . implode("\n", $this->executedSql($browser)));

        if ($role === NavigationViewer::GUEST) {
            // Guest HTML stays shared-cacheable (requirements, Conventions)
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }

    /**
     * LAUNCH-03: with the launch date set, inside its 30 days, every account older than it gets the Hub banner and the
     * Menu line - from the profile row (its registration date, its navigation dismissals): the same pins. A guest gets
     * neither and stays shared-cacheable. The first request claims "What moved" (LAUNCH-01), the counted one nothing.
     */
    #[DataProvider('provideViewers')]
    public function testTheHubCostWithTheLaunchPointer(string $role, int $statements): void
    {
        $browser = self::createClient();
        // One container for the whole test, so every request asks the replaced launch service (decision R5)
        $browser->disableReboot();
        self::getContainer()->set(NavigationLaunch::class, new NavigationLaunch(new MockClock('2026-11-10 12:00:00', 'Europe/Prague'), '2026-11-02'));

        if ($role !== NavigationViewer::GUEST) {
            // An account the old navigation knew, whenever the fixtures were loaded
            self::getContainer()->get(Connection::class)->executeStatement("UPDATE player SET registered_at = '2025-05-01 10:00:00' WHERE id = :id", ['id' => NavigationViewer::playerId($role)]);
        }

        NavigationViewer::signIn($browser, $role);

        $browser->request('GET', '/en/hub');
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();

        self::assertCount($role === NavigationViewer::GUEST ? 0 : 1, $crawler->filter('main .nav-pointer--hub'), $role);
        self::assertSame($statements, $this->queryCount($browser), $role . " /en/hub ran:\n" . implode("\n", $this->executedSql($browser)));

        if ($role === NavigationViewer::GUEST) {
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }
}
