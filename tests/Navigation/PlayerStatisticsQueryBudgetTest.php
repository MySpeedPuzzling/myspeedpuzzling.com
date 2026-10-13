<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What a player's Statistics page (/en/player-statistics/{id}) costs in statements, own and other, for a guest, a
 * signed-in player and a member - chrome included (the signed-in chrome = the user refresh, the viewer's profile row and
 * the Inbox badge, see ChromeQueryBudgetTest). The second request is counted, like PlayerProfileQueryBudgetTest.
 *
 * Created with PROF-04 (docs/features/navigation README §4.7, implementation plan decision 98): the average-time chart
 * and the skill / MSP Rating cards moved here from the profile (templates/player/_standing.html.twig) - the chart's
 * pieces and brands (+ its data for a member), the rating card's ladder place (+ the owner's qualification progress) and,
 * for a member, the skill card's tiers and qualification. Pinned exactly at the values measured with them; budgets only
 * go down, every change carries a comment naming the requirement ID.
 *
 * Before PROF-04 (measured on a52f7dc6 with the throwaway BudgetProbe, the same rows): guest → member 6,
 * guest → other 7, player → own 10, player → other 10, member → own 9, member → other 10, admin → member 10. The profile
 * dropped more than this page grew in every row (PlayerProfileQueryBudgetTest, PROF-03/04): guest → member −6 / +3,
 * guest → other −7 / +3, player → own −6 / +2, player → other −7 / +3, member → own −11 / +7, member → other −11 / +6,
 * admin → member −10 / +6.
 */
final class PlayerStatisticsQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideViews(): iterable
    {
        // The profile row, the first result's date (the period menu), the solo/pair/team lists of the period (+ their
        // player check) and, from PROF-04, the chart's pieces and brands and the rating card (6 → 9)
        yield 'guest → member' => [NavigationViewer::GUEST, PlayerFixture::PLAYER_WITH_STRIPE, 9];
        // + the pair results' players (7 → 10)
        yield 'guest → other' => [NavigationViewer::GUEST, PlayerFixture::PLAYER_REGULAR, 10];
        // A private non-member on their own page: + the signed-in chrome (3); the standing is the owner's explanation,
        // no rating card (10 → 12)
        yield 'player → own' => [NavigationViewer::PLAYER, PlayerFixture::PLAYER_PRIVATE, 12];
        // guest → other + the signed-in chrome (3) (10 → 13)
        yield 'player → other' => [NavigationViewer::PLAYER, PlayerFixture::PLAYER_REGULAR, 13];
        // guest → member + the signed-in chrome (3), the members' chart data and skill card (tiers, qualification) and the
        // rating card's qualification progress for its owner (9 → 16)
        yield 'member → own' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_WITH_STRIPE, 16];
        // guest → other + the signed-in chrome (3), the chart data and the skill card (10 → 16)
        yield 'member → other' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_REGULAR, 16];
        // = member → own with the admin key's queue counts instead of the owner's qualification progress (10 → 16)
        yield 'admin → member' => [NavigationViewer::ADMIN, PlayerFixture::PLAYER_WITH_STRIPE, 16];
    }

    #[DataProvider('provideViews')]
    public function testTheStatisticsCost(string $role, string $playerId, int $statements): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);
        $url = '/en/player-statistics/' . $playerId;

        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        // The parts this budget pays for are on the page (PROF-04)
        self::assertCount(1, $crawler->filter('[data-live-name-value="Chart:PlayerAverageTimeChart"]'));
        self::assertCount(1, $crawler->filter('.profile-standing'));

        self::assertSame($statements, $this->queryCount($browser), $role . ' ' . $url . " ran:\n" . implode("\n", $this->executedSql($browser)));

        if ($role === NavigationViewer::GUEST) {
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }
}
