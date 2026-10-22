<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Where am I" on the progress boards - Level (XP), Achievement Points, Karma
 * (docs/features/xp-levels/ux-polish/design-spec.md §4): the top 100 rows, a gap row that reveals exactly the rows it
 * counts, the viewer's row with two neighbours each side, "Show more" after the last row shown, a position line, shared
 * ranks within the filtered list - one window statement per board.
 */
final class ProgressBoardsWindowTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string VIEWER = PlayerFixture::PLAYER_WITH_STRIPE;

    private const int SEEDED = 150;

    // Between the 131st (1740 XP) and the 132nd (1738 XP) seeded puzzler: position 131 (0-based), rank 132 of 151
    private const int VIEWER_XP = 1739;

    public function testTheViewerFarDownSeesTheTopRowsAGapAndTheirNeighbours(): void
    {
        $browser = self::createClient();
        $seeded = $this->seedXpBoard($browser);
        TestingLogin::asPlayer($browser, self::VIEWER);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/ladder/level');

        self::assertResponseIsSuccessful();
        // The boards show 25 rows before the gap (design-spec §8.4)
        self::assertSame(
            [...array_slice($seeded, 0, 25), $seeded[129], $seeded[130], self::VIEWER, $seeded[131], $seeded[132]],
            $this->rowIds($crawler),
        );
        $gap = $crawler->filter('tr.lb-gap-row');
        self::assertCount(1, $gap);
        self::assertStringContainsString('⋯ 104 more', $gap->text());
        self::assertSame('Show ranks 26–75 of 151', trim($gap->filter('button')->text()));
        self::assertSame('table-active-player', $crawler->filter('#board-row-' . self::VIEWER)->attr('class'));
        self::assertStringContainsString('1739 XP', $crawler->filter('#board-row-' . self::VIEWER)->text());
        self::assertStringContainsString('Lv ', $crawler->filter('#board-row-' . self::VIEWER)->text());

        $position = $crawler->filter('[data-testid="lb-position"]');
        self::assertStringContainsString('#132 of 151 worldwide', $position->text());
        // The 100th row has 1802 XP
        self::assertStringContainsString('63 XP to the top 100', $position->text());
        self::assertCount(1, $position->filter('a[href="#board-row-' . self::VIEWER . '"]'), 'Jump to me');
        self::assertStringContainsString('Show 17 more', $crawler->filter('.lb-show-more')->text());
        self::assertCount(0, $crawler->filter('[data-testid="lb-not-on-board"], [data-controller="leaderboard-window"]'));

        $this->assertQueryCountAtMost($browser, 12, 'Level board');
        self::assertCount(1, $this->boardStatements($browser), 'One window statement per board');
    }

    public function testTheGapRowRevealsFiftyRowsPerTapAndShowMoreContinuesAfterTheViewer(): void
    {
        $browser = self::createClient();
        $seeded = $this->seedXpBoard($browser);
        TestingLogin::asPlayer($browser, self::VIEWER);

        $crawler = $browser->request('GET', '/en/ladder/level');
        // Buttons in GET forms (crawlers never follow them), aimed at the list's Turbo Frame
        $gapForm = $crawler->filter('tr.lb-gap-row form');
        self::assertSame('progress-board', $gapForm->attr('data-turbo-frame'));
        self::assertCount(0, $crawler->filter('tr.lb-gap-row a, .lb-show-more a'));

        $revealed = $browser->submit($gapForm->form());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, follow');
        self::assertSame([...array_slice($seeded, 0, 75), $seeded[129], $seeded[130], self::VIEWER, $seeded[131], $seeded[132]], $this->rowIds($revealed));
        self::assertSame('Show ranks 76–125 of 151', trim($revealed->filter('tr.lb-gap-row button')->text()));
        // The first new row gets the focus, the range is announced
        $announcer = $revealed->filter('[data-controller="leaderboard-window"]');
        self::assertSame('board-row-' . $seeded[25], $announcer->attr('data-leaderboard-window-focus-value'));
        self::assertSame('Showing ranks 26–75 of 151', $announcer->attr('data-leaderboard-window-announce-value'));

        // Two more taps close the gap: the ranking runs on to the viewer
        $revealed = $browser->submit($revealed->filter('tr.lb-gap-row form')->form());
        $revealed = $browser->submit($revealed->filter('tr.lb-gap-row form')->form());
        self::assertCount(0, $revealed->filter('tr.lb-gap-row'));
        self::assertSame([...array_slice($seeded, 0, 131), self::VIEWER, $seeded[131], $seeded[132]], $this->rowIds($revealed));

        $crawler = $browser->request('GET', '/en/ladder/level');
        $more = $browser->submit($crawler->filter('.lb-show-more form')->form());
        self::assertResponseIsSuccessful();
        // The gap above the viewer stays; everything after the viewer is shown now
        self::assertCount(1, $more->filter('tr.lb-gap-row'));
        self::assertSame(
            [...array_slice($seeded, 0, 25), $seeded[129], $seeded[130], self::VIEWER, ...array_slice($seeded, 131)],
            $this->rowIds($more),
        );
        self::assertCount(0, $more->filter('.lb-show-more'));
        self::assertSame('board-row-' . $seeded[133], $more->filter('[data-controller="leaderboard-window"]')->attr('data-leaderboard-window-focus-value'));
    }

    public function testAFilterIsANewListThatStartsFromTheTopAgain(): void
    {
        $browser = self::createClient();
        $this->seedXpBoard($browser);
        TestingLogin::asPlayer($browser, self::VIEWER);

        $crawler = $browser->request('GET', '/en/ladder/level?country=cz&limit=10');

        // The filter form never carries the revealed rows
        $filterForm = $crawler->filter('form[data-controller="auto-submit"]');
        self::assertCount(0, $filterForm->filter('input[name="limit"], input[name="below"]'));
        // The buttons carry the list's filters along with the next window
        $params = $crawler->filter('.lb-show-more form input')->each(static fn (Crawler $input): array => [(string) $input->attr('name'), (string) $input->attr('value')]);
        // The board is the URL itself, never a parameter
        self::assertNotContains('tab', array_column($params, 0));
        self::assertContains(['country', 'cz'], $params);
        self::assertContains(['limit', '60'], $params);
        self::assertCount(10, $this->rowIds($crawler));
    }

    public function testRanksAreSharedOnEqualValuesAndCountWithinTheCountry(): void
    {
        $browser = self::createClient();
        $seeded = $this->seedXpBoard($browser);
        $database = $browser->getContainer()->get(Connection::class);
        // Two puzzlers with the same XP share their place
        $database->executeStatement('UPDATE player SET xp_total = 1998 WHERE id = :id', ['id' => $seeded[2]]);
        $database->executeStatement('UPDATE player SET country = :country WHERE id = :id', ['country' => 'cz', 'id' => self::VIEWER]);
        TestingLogin::asPlayer($browser, self::VIEWER);

        $crawler = $browser->request('GET', '/en/ladder/level');
        self::assertSame(['1', '2', '2', '4'], array_slice($this->ranks($crawler), 0, 4));

        // Every even seeded puzzler is Czech: within Czechia the viewer (1739 XP) is behind 66 of them (2000 … 1740)
        $crawler = $browser->request('GET', '/en/ladder/level?country=cz');
        self::assertResponseIsSuccessful();
        self::assertSame('1', $this->ranks($crawler)[0]);
        self::assertSame('67', trim($crawler->filter('#board-row-' . self::VIEWER . ' td.rank')->text()));
        self::assertStringContainsString('#67 of 76 in Czechia', $crawler->filter('[data-testid="lb-position"]')->text());
    }

    public function testPrivateAndOptedOutPlayersStayOffTheBoard(): void
    {
        $browser = self::createClient();
        $seeded = $this->seedXpBoard($browser);
        $database = $browser->getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => $seeded[0]]);
        $database->executeStatement('UPDATE player SET experience_system_opted_out = true WHERE id = :id', ['id' => $seeded[1]]);
        TestingLogin::asPlayer($browser, self::VIEWER);

        $crawler = $browser->request('GET', '/en/ladder/level');

        $ids = $this->rowIds($crawler);
        self::assertNotContains($seeded[0], $ids);
        self::assertNotContains($seeded[1], $ids);
        self::assertSame($seeded[2], $ids[0]);
        self::assertStringContainsString('#130 of 149 worldwide', $crawler->filter('[data-testid="lb-position"]')->text());
    }

    public function testTheAchievementPointsBoardShowsTheAchievementsHeldInTheSameStatement(): void
    {
        $browser = self::createClient();
        $database = $browser->getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE player SET achievement_points = 0');
        $database->executeStatement('UPDATE player SET achievement_points = 40 WHERE id = :id', ['id' => self::VIEWER]);
        $database->executeStatement('DELETE FROM badge WHERE player_id = :id', ['id' => self::VIEWER]);

        foreach (['puzzles_solved', 'supporter'] as $type) {
            $database->executeStatement(
                'INSERT INTO badge (id, player_id, type, earned_at, tier) VALUES (:id, :player, :type, ' . TestClock::SQL_NOW . ', NULL)',
                ['id' => Uuid::uuid7()->toString(), 'player' => self::VIEWER, 'type' => $type],
            );
        }

        TestingLogin::asPlayer($browser, self::VIEWER);
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/ladder/achievement-points');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#board-row-' . self::VIEWER);
        self::assertStringContainsString('40 AP', $row->text());
        self::assertStringContainsString('2 achievements', $row->text());
        self::assertCount(1, $row->filter('.lb-value-achievements'));
        $this->assertQueryCountAtMost($browser, 12, 'Achievement Points board');
        self::assertCount(1, $this->boardStatements($browser));
    }

    public function testAFilteredKarmaBoardRanksTheFilteredCountAndShowsTheKarmaInTotal(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $database = $browser->getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE player SET karma = 0');
        $database->executeStatement('UPDATE player SET karma = 7 WHERE id = :id', ['id' => self::VIEWER]);
        TestingLogin::asPlayer($browser, self::VIEWER);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/ladder/karma');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#board-row-' . self::VIEWER);
        self::assertStringContainsString('7 karma', $row->text());
        self::assertCount(0, $row->filter('.lb-value-secondary'), 'Unfiltered: the value is the karma in total');
        self::assertCount(1, $row->filter('.lb-value-karma'));
        $this->assertQueryCountAtMost($browser, 12, 'Karma board');
        self::assertCount(1, $this->boardStatements($browser));

        // A kind the viewer has nothing of: not on that list (the stored total does not rank there)
        $crawler = $browser->request('GET', '/en/ladder/karma?kind=moderation');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#board-row-' . self::VIEWER));
    }

    public function testGuestsGetTheBoardsWithoutTheirOwnRowInAFewStatements(): void
    {
        $browser = self::createClient();
        $this->seedXpBoard($browser);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/ladder/level');

        self::assertResponseIsSuccessful();
        self::assertCount(25, $this->rowIds($crawler));
        self::assertCount(0, $crawler->filter('[data-testid="lb-position"], tr.lb-gap-row'));
        self::assertStringContainsString('Show 50 more', $crawler->filter('.lb-show-more')->text());
        self::assertStringContainsString('Sign in', $crawler->filter('[data-testid="lb-not-on-board"]')->text());
        $this->assertQueryCountAtMost($browser, 3, 'Level board for a guest');

        // The Achievement Points board stays behind a sign-in
        $crawler = $browser->request('GET', '/en/ladder/achievement-points');
        self::assertCount(0, $crawler->filter('table.lb-table'));
        self::assertCount(1, $crawler->filter('.xp-teaser a[href*="return="]'));
    }

    public function testAViewerNotOnTheBoardIsToldWhyAndWhatToDo(): void
    {
        $browser = self::createClient();
        $this->seedXpBoard($browser);
        $database = $browser->getContainer()->get(Connection::class);
        TestingLogin::asPlayer($browser, self::VIEWER);

        // Another country's list
        $crawler = $browser->request('GET', '/en/ladder/level?country=cz');
        self::assertStringContainsString('from Czechia only', $crawler->filter('[data-testid="lb-not-on-board"]')->text());

        // Opted out of levels and achievements: where to switch it back
        $database->executeStatement('UPDATE player SET experience_system_opted_out = true WHERE id = :id', ['id' => self::VIEWER]);
        $crawler = $browser->request('GET', '/en/ladder/level');
        $line = $crawler->filter('[data-testid="lb-not-on-board"]');
        self::assertStringContainsString('turned off levels and achievements', $line->text());
        self::assertCount(1, $line->filter('a[href="/en/edit-profile"]'));
        self::assertCount(0, $crawler->filter('#board-row-' . self::VIEWER));

        // A free player on the Achievement Points board
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/ladder/achievement-points');
        self::assertCount(1, $crawler->filter('[data-testid="lb-not-on-board"] a[href="/en/membership"]'));
    }

    public function testTheHowProgressWorksLinkBringsYouBack(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::VIEWER);

        $crawler = $browser->request('GET', '/en/ladder/karma');

        $link = $crawler->filter('a[href^="/en/how-karma-works"]');
        self::assertCount(1, $link);
        self::assertSame('nofollow', $link->attr('rel'));
        self::assertStringContainsString('return=', (string) $link->attr('href'));
        self::assertStringContainsString('return_title=', (string) $link->attr('href'));
    }

    public function testThePillsCarryTheFiltersEachBoardAccepts(): void
    {
        $browser = self::createClient();

        // A member: the country everywhere, favorites where the board has them
        TestingLogin::asPlayer($browser, self::VIEWER);
        $crawler = $browser->request('GET', '/en/ladder/level?country=cz&favorites=1');
        $pills = $this->pillHrefs($crawler);
        self::assertSame('/en/ladder/country/cz', $pills['Times']);
        self::assertSame('/en/msp-rating?country=cz&onlyFavorites=1', $pills['MSP Rating']);
        self::assertSame('/en/ladder/karma?country=cz&favorites=1', $pills['Karma']);

        // Not a member: the Times countries and the MSP Rating filters are members-only
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/ladder/level?country=cz');
        $pills = $this->pillHrefs($crawler);
        self::assertSame('/en/ladder', $pills['Times']);
        self::assertSame('/en/msp-rating', $pills['MSP Rating']);
        self::assertSame('/en/ladder/achievement-points?country=cz', $pills['Achievement Points']);

        // From a Times country page to the progress boards
        TestingLogin::asPlayer($browser, self::VIEWER);
        $crawler = $browser->request('GET', '/en/ladder/solo/500-pieces/country/de');
        self::assertSame('/en/ladder/level?country=de', $this->pillHrefs($crawler)['Level (XP)']);
    }

    /**
     * 150 puzzlers with 2000, 1998, … 1702 XP (every even one Czech, the others German), the viewer with VIEWER_XP,
     * nobody else on the board
     *
     * @return list<string> the seeded players, best first
     */
    private function seedXpBoard(KernelBrowser $browser): array
    {
        $database = $browser->getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE player SET xp_total = 0');
        $database->executeStatement('UPDATE player SET xp_total = :xp, country = :country WHERE id = :id', ['xp' => self::VIEWER_XP, 'country' => 'gb', 'id' => self::VIEWER]);

        $ids = [];

        for ($i = 0; $i < self::SEEDED; $i++) {
            $id = Uuid::uuid7()->toString();
            $database->executeStatement(
                "INSERT INTO player (id, code, name, registered_at, xp_total, level, country) VALUES (:id, :code, :name, " . TestClock::SQL_NOW . ", :xp, 10, :country)",
                [
                    'id' => $id,
                    'code' => 'lw' . $i . substr($id, -6),
                    'name' => 'Window Puzzler ' . $i,
                    'xp' => 2000 - 2 * $i,
                    'country' => $i % 2 === 0 ? 'cz' : 'de',
                ],
            );
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function rowIds(Crawler $crawler): array
    {
        return $crawler->filter('table.lb-table tbody tr[id^="board-row-"]')->each(
            static fn (Crawler $row): string => substr((string) $row->attr('id'), strlen('board-row-')),
        );
    }

    /**
     * @return list<string>
     */
    private function ranks(Crawler $crawler): array
    {
        return $crawler->filter('table.lb-table tbody tr[id^="board-row-"] td.rank')->each(
            static fn (Crawler $cell): string => trim($cell->text()),
        );
    }

    /**
     * @return array<string, string>
     */
    private function pillHrefs(Crawler $crawler): array
    {
        $hrefs = [];

        $crawler->filter('nav.leaderboards-nav a.leaderboards-nav-pill')->each(static function (Crawler $pill) use (&$hrefs): void {
            $hrefs[trim($pill->text())] = (string) $pill->attr('href');
        });

        return $hrefs;
    }

    /**
     * @return list<string>
     */
    private function boardStatements(KernelBrowser $browser): array
    {
        return array_values(array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => str_contains($sql, 'COUNT(*) OVER ()') && str_contains($sql, 'me AS ('),
        ));
    }
}
