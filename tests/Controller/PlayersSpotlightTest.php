<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\SpotlightSeeding;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Players page spotlight (docs/features/players-page/README.md, stream S2) at World and at a country.
 */
final class PlayersSpotlightTest extends WebTestCase
{
    use QueryCountAssertions;
    use SpotlightSeeding;

    public function testTheWorld(): void
    {
        $browser = $this->browserWithNumbers();
        $crawler = $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#players-spotlight-title', 'Speed puzzlers worldwide');
        self::assertSelectorExists('.players-spotlight .players-spotlight-globe');
        self::assertSelectorTextContains('.players-spotlight-summary', 'registered');
        self::assertSelectorTextContains('.players-spotlight-stats', 'pieces in');
        self::assertSelectorTextContains('.players-spotlight-stats', 'puzzlers with a 500 time');
        self::assertSelectorNotExists('.players-spotlight-note');
        self::assertCount(3, $crawler->filter('.players-spotlight-column'));
        self::assertSelectorExists('.players-spotlight-foot a[href="/en/ladder"]');
        self::assertSelectorExists('.players-spotlight-foot a[href="/en/puzzlers/all"]');
    }

    public function testACountryIsSetAgainstTheWorld(): void
    {
        $browser = $this->browserWithNumbers();
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement("UPDATE community_scope_stats SET median_best500_seconds = 3600 WHERE scope = 'world'");
        $database->executeStatement("UPDATE community_scope_stats SET median_best500_seconds = 3300 WHERE scope = 'cz'");

        $browser->request('GET', '/en/puzzlers?scope=cz');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#players-spotlight-title', 'Puzzlers in Czechia');
        self::assertSelectorExists('.players-spotlight-emblem.fi-cz');
        self::assertSelectorTextContains('.players-spotlight-stats', '5 min faster than the world');
        self::assertSelectorTextContains('.players-spotlight-column[data-list="newest"]', 'Public players with at least one result');
        self::assertSelectorExists('.players-spotlight-foot a[href="/en/puzzlers/all?scope=cz"]');
        // The country leaderboard is for members: a guest gets the lock and the members modal
        self::assertSelectorExists('.players-spotlight-foot a[href="/en/ladder/country/cz"][data-bs-target="#membersExclusiveModal"] .ci-locked');
    }

    public function testAMemberGoesStraightToTheCountryLeaderboard(): void
    {
        $browser = $this->browserWithNumbers();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/puzzlers?scope=cz');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.players-spotlight-foot a[href="/en/ladder/country/cz"]:not([data-bs-toggle])');
    }

    public function testASmallCountryGetsAFriendlyNote(): void
    {
        $browser = $this->browserWithNumbers();
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement("UPDATE community_scope_stats SET active30d = 3, solves30d = 12, solves_prev30d = 8 WHERE scope = 'cz'");

        $browser->request('GET', '/en/puzzlers?scope=cz');

        self::assertSelectorTextContains('.players-spotlight-note', 'Small and growing');
        self::assertSelectorTextContains('.players-spotlight-note', '50% more puzzles solved');
        self::assertSelectorTextContains('.players-spotlight-note', '3 puzzlers solved a puzzle in the last 30 days');

        $database->executeStatement("UPDATE community_scope_stats SET active30d = 15 WHERE scope = 'cz'");
        $browser->request('GET', '/en/puzzlers?scope=cz');

        self::assertSelectorNotExists('.players-spotlight-note');
    }

    public function testAnEmptyCountrySaysSoInAWordInsteadOfEmptyBoxes(): void
    {
        $browser = $this->browserWithNumbers();
        $crawler = $browser->request('GET', '/en/puzzlers?scope=fj');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#players-spotlight-title', 'Puzzlers in Fiji');
        self::assertCount(3, $crawler->filter('.players-spotlight-empty'));
        self::assertSelectorTextContains('.players-spotlight-note', 'A small community.');
        self::assertSelectorTextContains('.players-spotlight-note', 'Every puzzle logged here puts Fiji on the map.');
        self::assertSelectorNotExists('.players-spotlight-spark');
    }

    public function testShowMoreRevealsRowsAlreadyInThePage(): void
    {
        $browser = $this->browserWithNumbers();
        $this->clearSpotlightNumbers();

        for ($i = 1; $i <= 12; $i++) {
            $this->seedSpotlightPlayer('Busy puzzler ' . $i, 'cz', solvesThisMonth: 20 + $i, favoritesCount: $i, solvedTotal: $i, registeredAt: sprintf('-%d days', $i));
        }

        $crawler = $browser->request('GET', '/en/puzzlers?scope=cz');
        self::assertResponseIsSuccessful();

        $active = $crawler->filter('.players-spotlight-column[data-list="active"]');
        self::assertSame('players-spotlight-more', $active->attr('data-controller'));
        self::assertCount(10, $active->filter('.players-spotlight-list > li'));
        self::assertCount(5, $active->filter('.players-spotlight-list > li.players-spotlight-extra[data-players-spotlight-more-target="extra"]'));
        self::assertCount(1, $active->filter('button.players-spotlight-more[data-action="players-spotlight-more#reveal"]'));
        self::assertCount(1, $active->filter('a.players-spotlight-full[href="/en/puzzlers/all?scope=cz&sort=active"]'));

        $first = $active->filter('.players-spotlight-list > li')->first();
        self::assertStringContainsString('Busy puzzler 12', $first->text());
        self::assertStringContainsString('32 puzzles', $first->text());
        self::assertSame('1', $first->filter('.players-person-rank')->text());

        $followed = $crawler->filter('.players-spotlight-column[data-list="followed"]');
        self::assertStringContainsString('★ 12', $followed->filter('.players-spotlight-list > li')->first()->text());
        self::assertCount(1, $followed->filter('a.players-spotlight-full[href="/en/puzzlers/all?scope=cz&sort=followed"]'));

        $newFaces = $crawler->filter('.players-spotlight-column[data-list="newest"]');
        $newest = $newFaces->filter('.players-spotlight-list > li')->first();
        self::assertStringContainsString('Busy puzzler 1', $newest->text());
        self::assertStringContainsString('joined yesterday', $newest->text());
        self::assertCount(0, $newest->filter('.players-person-rank'));
        self::assertCount(1, $newFaces->filter('a.players-spotlight-full[href="/en/puzzlers/all?scope=cz&sort=newest"]'));
    }

    public function testTheSpotlightCostsThreeStatements(): void
    {
        $browser = $this->browserWithNumbers();
        $this->startCountingQueries($browser);

        $browser->request('GET', '/en/puzzlers?scope=cz');
        self::assertResponseIsSuccessful();

        $sql = $this->executedSql($browser);
        $count = static fn (string $fragment): int => count(array_filter($sql, static fn (string $statement): bool => str_contains($statement, $fragment)));

        // The scope + world numbers, the upcoming events, the three people lists
        self::assertSame(1, $count('FROM community_scope_stats WHERE scope IN'));
        self::assertSame(1, $count('LEFT JOIN competition_series cs ON cs.id = c.series_id'));
        self::assertSame(1, $count("'active' AS list"));
    }

    private function browserWithNumbers(): KernelBrowser
    {
        $browser = self::createClient();
        $this->recalculateCommunityStats();

        return $browser;
    }
}
