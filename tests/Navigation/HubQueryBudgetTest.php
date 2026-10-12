<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
    // The toolbar badges: unread conversations + unread notifications
    private const int INBOX = 2;
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
        // Following players changes nothing yet: the eager feed is everybody's, the Favorites tab loads lazily
        yield 'player with favorites' => [NavigationViewer::PLAYER_WITH_FAVORITES, self::SIGNED_IN];
        yield 'member' => [NavigationViewer::MEMBER, self::SIGNED_IN + self::MEMBER_TIERS];
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
}
