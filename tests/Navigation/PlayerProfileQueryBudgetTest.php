<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\DismissHint;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * What a player's profile page (/en/player-profile/{id}) costs in statements, own and other, for a guest, a signed-in
 * player and a member - chrome included (the signed-in chrome = the user refresh, the viewer's profile row and the
 * Inbox badge, see ChromeQueryBudgetTest; INB-02 made the two toolbar badges one statement: every signed-in row −1).
 *
 * Measured on a76cd36b (P0-02, 2026-10-12; docs/features/navigation/delivery/baseline.md) and pinned exactly. Budgets
 * only go down; every change carries a comment naming the requirement ID and why. A higher number is a bug to explain.
 * The second request is counted: the first warms the caches and claims an announcement modal, the second claimed
 * none in any row (no player_modal_impression statement).
 *
 * The admin → member row is ProfileProgressionTest::testTheProfilePageCost's member viewer (11): both tests stay, the
 * same number pinned twice, so a change that moves one moves the other.
 */
final class PlayerProfileQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideViews(): iterable
    {
        // "Other" = a public non-member with results (PLAYER_REGULAR); the member = PLAYER_WITH_STRIPE.
        // PROF-03/04 (the profile is the Overview, docs/features/navigation README §4.7): the profile row, the Overview's
        // bests + numbers (GetPlayerOverviewNumbers, one statement) and the bests' ranks, the 8 latest results and the
        // achievements showcase. Gone: the solved puzzles (player check, solo, pair and team lists, every rank - the
        // Results page lists them now) and the chart's pieces/brands + the rating card (moved to Statistics, pinned by
        // PlayerStatisticsQueryBudgetTest). 11 → 5
        yield 'guest → member' => [NavigationViewer::GUEST, PlayerFixture::PLAYER_WITH_STRIPE, 5];
        // No showcase (not a member); + the referral program block in About (3). PROF-03/04: 14 → 7 (no solved puzzles,
        // their pair/team players, chart or rating card; + the Overview's numbers and the bests' ranks)
        yield 'guest → other' => [NavigationViewer::GUEST, PlayerFixture::PLAYER_REGULAR, 7];
        // A private non-member seen by its owner: + the signed-in chrome (3), the owner's "Review your results" counts and
        // getting-started progress, the viewer's best times; no showcase, no rating (INB-02: 17 → 16; PROF-03/04: 16 → 10)
        yield 'player → own' => [NavigationViewer::PLAYER, PlayerFixture::PLAYER_PRIVATE, 10];
        // guest → other + the signed-in chrome (3) and the viewer's best times (INB-02: 19 → 18; PROF-03/04: 18 → 11)
        yield 'player → other' => [NavigationViewer::PLAYER, PlayerFixture::PLAYER_REGULAR, 11];
        // guest → member + the signed-in chrome (3), the owner's review counts and getting-started progress and the
        // latest results' difficulty tiers (members) (INB-02: 23 → 22; PROF-03/04: 22 → 11 - also no chart data, skill
        // card, first-tries count or the solved puzzles' tiers)
        yield 'member → own' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_WITH_STRIPE, 11];
        // guest → other + the signed-in chrome (3), the viewer's best times and the latest results' difficulty tiers
        // (INB-02: 24 → 23; PROF-03/04: 23 → 12)
        yield 'member → other' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_REGULAR, 12];
        // = ProfileProgressionTest::testTheProfilePageCost's member viewer: the admin key's queue counts, no
        // getting-started card (INB-02: 22 → 21; PROF-03/04: 21 → 11)
        yield 'admin → member' => [NavigationViewer::ADMIN, PlayerFixture::PLAYER_WITH_STRIPE, 11];
        // PROF-03: a private profile hidden from the viewer is the header and the notice - the profile row alone (new row)
        yield 'guest → private' => [NavigationViewer::GUEST, PlayerFixture::PLAYER_PRIVATE, 1];
    }

    #[DataProvider('provideViews')]
    public function testTheProfileCost(string $role, string $profilePlayerId, int $statements): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);
        $url = '/en/player-profile/' . $profilePlayerId;

        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        self::assertSame($statements, $this->queryCount($browser), $role . ' ' . $url . " ran:\n" . implode("\n", $this->executedSql($browser)));

        if ($role === NavigationViewer::GUEST) {
            // Guest HTML stays shared-cacheable (requirements, Conventions)
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }

    /**
     * INB-05: a dismissed navigation pointer rides on the viewer's profile row - the member's own profile costs the same.
     */
    public function testADismissedNavigationPointerCostsTheProfileNothing(): void
    {
        $browser = self::createClient();
        $playerId = NavigationViewer::signIn($browser, NavigationViewer::MEMBER);
        self::assertNotNull($playerId);
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new DismissHint($playerId, HintType::NavigationInboxPointer));
        $url = '/en/player-profile/' . $playerId;

        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        // = the 'member → own' row (PROF-03/04: 22 → 11)
        self::assertSame(11, $this->queryCount($browser), $url . " ran:\n" . implode("\n", $this->executedSql($browser)));
    }
}
