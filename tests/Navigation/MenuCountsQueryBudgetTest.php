<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the Menu's lazy numbers cost (docs/features/navigation README §4.12 "Menu data" 3, MENU-01): `menu_counts` runs
 * at most 2 statements more than an empty signed-in endpoint (EmptySignedInEndpoint) - the organiser's events
 * (GetEventsViewerData, only when the profile row says they organise anything) and Review your results
 * (GetPlayerReviewCounts). Pinned exactly; numbers only go down.
 */
final class MenuCountsQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;
    use EmptySignedInEndpoint;

    // README §4.12: ≤ 2 statements more than the empty signed-in endpoint
    private const int MAX_OVER_EMPTY = 2;

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideViewers(): iterable
    {
        // Review your results only: the profile row says the player organises nothing
        yield 'player' => [NavigationViewer::PLAYER, 1];
        yield 'member' => [NavigationViewer::MEMBER_ONLY, 1];
        // + the organiser's events (GetEventsViewerData::forPlayer())
        yield 'organiser' => [NavigationViewer::ORGANISER, 2];
        yield 'moderator' => [NavigationViewer::MODERATOR, 2];
        yield 'admin' => [NavigationViewer::ADMIN, 2];
    }

    #[DataProvider('provideViewers')]
    public function testMenuCountsCostAtMostTwoStatementsMoreThanAnEmptyEndpoint(string $role, int $overEmpty): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);

        $empty = $this->emptySignedInEndpointStatements($browser);

        $browser->request('GET', '/en/menu-counts.json');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/menu-counts.json');
        self::assertResponseIsSuccessful();

        $statements = $this->executedSql($browser);
        self::assertCount(count($empty) + $overEmpty, $statements, $role . " menu_counts ran:\n" . implode("\n", $statements));
        self::assertLessThanOrEqual(count($empty) + self::MAX_OVER_EMPTY, count($statements));
        self::assertSame($empty, array_slice($statements, 0, count($empty)), 'The same user refresh and profile row first');
    }

    public function testAGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/menu-counts.json');

        self::assertResponseRedirects();
        self::assertStringStartsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
    }
}
