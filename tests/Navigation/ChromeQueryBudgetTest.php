<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the chrome (header, footer and everything base.html.twig renders around a page) costs in statements, measured
 * on the cheapest page that has it: /en/faq, whose only statement of its own is the 1000-piece distribution.
 *
 * Measured on a76cd36b (P0-02, 2026-10-12; docs/features/navigation/delivery/baseline.md) and pinned exactly. Budgets
 * only go down; every change carries a comment naming the requirement ID and why. A higher number is a bug to explain.
 * The second request is counted: the first warms the caches and claims an announcement modal, the second claimed
 * none in any row (no player_modal_impression statement).
 */
final class ChromeQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    // The FAQ's own statement (FaqController: the 1000-piece distribution); the guest chrome costs nothing
    private const int GUEST_PAGE = 1;
    // The session's user refresh (user_account)
    private const int ACCOUNT = 1;
    // The viewer's profile row (RetrieveLoggedUserProfile)
    private const int PROFILE_ROW = 1;
    // The toolbar badges: unread conversations + unread notifications
    private const int INBOX = 2;
    // The admin key's review queue counts (admin_queue_counts(), moderators and admins only)
    private const int QUEUE_COUNTS = 1;

    private const int SIGNED_IN = self::GUEST_PAGE + self::ACCOUNT + self::PROFILE_ROW + self::INBOX;

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => [NavigationViewer::GUEST, self::GUEST_PAGE];
        // The role-free row: what every signed-in page pays for the chrome
        yield 'player' => [NavigationViewer::PLAYER, self::SIGNED_IN];
        // Membership changes nothing in the chrome
        yield 'member' => [NavigationViewer::MEMBER, self::SIGNED_IN];
        // The role-pure member (MEMBER is also an event owner); added after P0-02 for the per-role checks
        yield 'member only' => [NavigationViewer::MEMBER_ONLY, self::SIGNED_IN];
        // Maintaining events changes nothing in the chrome
        yield 'organiser' => [NavigationViewer::ORGANISER, self::SIGNED_IN];
        yield 'moderator' => [NavigationViewer::MODERATOR, self::SIGNED_IN + self::QUEUE_COUNTS];
        yield 'admin' => [NavigationViewer::ADMIN, self::SIGNED_IN + self::QUEUE_COUNTS];
    }

    #[DataProvider('provideViewers')]
    public function testTheChromeCost(string $role, int $statements): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);

        $browser->request('GET', '/en/faq');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        self::assertSame($statements, $this->queryCount($browser), $role . " /en/faq ran:\n" . implode("\n", $this->executedSql($browser)));

        if ($role === NavigationViewer::GUEST) {
            // Guest HTML stays shared-cacheable (requirements, Conventions)
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }
}
