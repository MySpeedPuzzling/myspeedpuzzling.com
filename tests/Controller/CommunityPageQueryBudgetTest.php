<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Community landing's previews (docs/features/navigation COM-01, README §4.9) cost one statement each - the solo
 * 500-piece leaders, the most active puzzlers, the next events (CommunityPreview) - and no statement of the viewer's
 * own (their favourites' stars and Compare come from the profile row), so a member pays exactly what every signed-in
 * page pays on top (account, profile row, the Inbox badge).
 *
 * Ceilings of the plan: guest ≤ 4, member ≤ 6 with the chrome. Pinned at the measured values (COM-01, 2026-10-13);
 * budgets only go down, every change commented with its requirement ID. The second request is counted: the first warms
 * the caches and claims an announcement modal (ChromeQueryBudgetTest).
 */
final class CommunityPageQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    // Leaders (GetFastestPlayers::window), the most active puzzlers (GetSpotlightPeople), the next events
    // (GetEventOccurrences::upcoming()) - the guests' times board cache is off in the test environment (.env.test)
    private const int PREVIEWS = 3;
    // The session's user refresh, the viewer's profile row, the Inbox badge (INB-02: one statement)
    private const int SIGNED_IN_CHROME = 3;
    // The admin key's review queue counts (moderators and admins only)
    private const int QUEUE_COUNTS = 1;

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => [NavigationViewer::GUEST, self::PREVIEWS];
        yield 'player' => [NavigationViewer::PLAYER, self::PREVIEWS + self::SIGNED_IN_CHROME];
        yield 'member' => [NavigationViewer::MEMBER, self::PREVIEWS + self::SIGNED_IN_CHROME];
        // Following players changes nothing: the previews are never the viewer's own
        yield 'player with favorites' => [NavigationViewer::PLAYER_WITH_FAVORITES, self::PREVIEWS + self::SIGNED_IN_CHROME];
        yield 'admin' => [NavigationViewer::ADMIN, self::PREVIEWS + self::SIGNED_IN_CHROME + self::QUEUE_COUNTS];
    }

    #[DataProvider('provideViewers')]
    public function testTheLandingCost(string $role, int $statements): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);

        $browser->request('GET', '/en/community');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/community');
        self::assertResponseIsSuccessful();

        self::assertSame($statements, $this->queryCount($browser), $role . " /en/community ran:\n" . implode("\n", $this->executedSql($browser)));
    }
}
