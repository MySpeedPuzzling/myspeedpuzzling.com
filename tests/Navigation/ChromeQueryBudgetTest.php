<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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

    /**
     * ADD-03: the running-stopwatch pill rides on the profile row (a subselect), so a running stopwatch costs the chrome
     * nothing. The lap start is set here relative to the clock - never the fixture's STOPWATCH_RUNNING, fresh only while
     * the cached test database is younger than a day (risk 17); the member (PLAYER_WITH_STRIPE) has no stopwatch of its own.
     */
    public function testARunningStopwatchCostsTheChromeNothing(): void
    {
        $browser = self::createClient();
        $playerId = NavigationViewer::signIn($browser, NavigationViewer::MEMBER);
        self::assertNotNull($playerId);

        $without = $this->faqStatements($browser);

        $container = $browser->getContainer();
        $now = $container->get(ClockInterface::class)->now();
        $container->get(Connection::class)->insert('stopwatch', [
            'id' => Uuid::uuid7()->toString(),
            'player_id' => $playerId,
            'puzzle_id' => null,
            'status' => StopwatchStatus::Running->value,
            'laps' => json_encode([['start' => $now->modify('-10 minutes')->format('Y-m-d H:i:s'), 'end' => null]], JSON_THROW_ON_ERROR),
        ]);

        $user = $container->get(GetPlayerProfile::class)->byId($playerId)->userId;
        self::assertNotNull($user);
        self::assertNotNull($container->get(GetPlayerProfile::class)->byUserId($user)->runningStopwatch, 'The member now has a fresh running stopwatch');

        $with = $this->faqStatements($browser);

        self::assertCount(self::SIGNED_IN, $without, "without a stopwatch /en/faq ran:\n" . implode("\n", $without));
        self::assertCount(count($without), $with, "with a running stopwatch /en/faq ran:\n" . implode("\n", $with));
    }

    /**
     * @return list<string>
     */
    private function faqStatements(KernelBrowser $browser): array
    {
        $browser->request('GET', '/en/faq');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        return $this->executedSql($browser);
    }
}
