<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What a player's profile page (/en/player-profile/{id}) costs in statements, own and other, for a guest, a signed-in
 * player and a member - chrome included (the signed-in chrome = the user refresh, the viewer's profile row and the
 * two toolbar badges, see ChromeQueryBudgetTest).
 *
 * Measured on a76cd36b (P0-02, 2026-10-12; docs/features/navigation/delivery/baseline.md) and pinned exactly. Budgets
 * only go down; every change carries a comment naming the requirement ID and why. A higher number is a bug to explain.
 * The second request is counted: the first warms the caches and claims an announcement modal, the second claimed
 * none in any row (no player_modal_impression statement).
 *
 * The admin → member row is ProfileProgressionTest::testTheProfilePageCost's member viewer (22): both tests stay, the
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
        // The profile row, the achievements showcase, the statistics by pieces and by brand, the rating, the latest
        // results and the solved puzzles (= ProfileProgressionTest's guest row)
        yield 'guest → member' => [NavigationViewer::GUEST, PlayerFixture::PLAYER_WITH_STRIPE, 11];
        // No showcase (not a member); + the referral program block (3) and the pair/team rows of the solved puzzles
        yield 'guest → other' => [NavigationViewer::GUEST, PlayerFixture::PLAYER_REGULAR, 14];
        // A private non-member seen by its owner: + the signed-in chrome (4), the owner's "Review your results"
        // counts and getting-started progress, the viewer's best times; no showcase, no rating
        yield 'player → own' => [NavigationViewer::PLAYER, PlayerFixture::PLAYER_PRIVATE, 17];
        // guest → other + the signed-in chrome (4) and the viewer's best times
        yield 'player → other' => [NavigationViewer::PLAYER, PlayerFixture::PLAYER_REGULAR, 19];
        // guest → member + the signed-in chrome (4), the owner's review counts and getting-started progress and the
        // members' insights (chart, skill, first tries, difficulty tiers)
        yield 'member → own' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_WITH_STRIPE, 23];
        // guest → other + the signed-in chrome (4), the viewer's best times and the members' insights
        yield 'member → other' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_REGULAR, 24];
        // = ProfileProgressionTest::testTheProfilePageCost's member viewer: the admin key's queue counts, no
        // getting-started card
        yield 'admin → member' => [NavigationViewer::ADMIN, PlayerFixture::PLAYER_WITH_STRIPE, 22];
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
}
