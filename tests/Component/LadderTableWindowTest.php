<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\LeaderboardSeeding;
use SpeedPuzzling\Web\Tests\LiveComponentBrowserRequests;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Times boards as "where am I" boards (docs/features/xp-levels/ux-polish/design-spec.md §4, §8.4): the full board
 * /ladder/{type}/{pieces} (top 25, the gap row, the viewer's rows, "Show more", the position line) and the /ladder
 * overview's top 10 with the viewer's own row after a "⋯" - each in one window statement.
 *
 * Fixture solo 500-piece bests: admin 20:00, player3 22:30, the regular player 28:20, player4 33:20 (player2 is private).
 */
final class LadderTableWindowTest extends WebTestCase
{
    use LeaderboardSeeding;
    use LiveComponentBrowserRequests;
    use QueryCountAssertions;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testTheViewerFarDownSeesTheTopTheGapRowTheirRowsAndThePositionLine(): void
    {
        // 60 solvers faster than every fixture time: the regular player is #63 of 64
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_500_05, 60, firstSeconds: 100);
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_REGULAR);

        $board = $this->board($this->client->request('GET', '/en/ladder/solo/500-pieces'));
        self::assertResponseIsSuccessful();

        self::assertSame([...range(1, 25), 61, 62, 63, 64], $this->ranks($board));
        self::assertCount(1, $board->filter('.lb-gap-row'));
        self::assertStringContainsString('Show ranks 26–60 of 64', $board->filter('.lb-gap-row')->text());
        self::assertSame('63', trim($board->filter('#leaderboard-me .lb-rank')->text()));
        self::assertCount(1, $board->filter('#leaderboard-me.table-active-player'));
        self::assertStringContainsString('63', $board->filter('[data-testid="lb-position"]')->text());
        self::assertStringContainsString('64', $board->filter('[data-testid="lb-position"]')->text());
        self::assertCount(0, $board->filter('[data-testid="lb-not-on-board"]'));

        $revealed = $board;

        for ($tap = 0; $tap < 10 && $revealed->filter('.lb-gap-row')->count() > 0; $tap++) {
            $revealed = $this->board($this->browserLiveRequest($this->client, $revealed, 'revealGap'));
        }

        self::assertSame(range(1, 64), $this->ranks($revealed));

        // Focus moves to the first new row (#26) and a polite live region announces the rows
        $announcer = $revealed->filter('[data-controller="leaderboard-window"]');
        self::assertCount(1, $announcer);
        $focusId = (string) $announcer->attr('data-leaderboard-window-focus-value');
        self::assertSame('26', trim($revealed->filter('#' . $focusId . ' .lb-rank')->text()));
    }

    public function testShowMoreContinuesAfterTheLastRowShown(): void
    {
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_500_05, 60, firstSeconds: 100);

        $board = $this->board($this->client->request('GET', '/en/ladder/solo/500-pieces'));
        self::assertSame(range(1, 25), $this->ranks($board));
        self::assertCount(1, $board->filter('.lb-show-more'));

        $more = $this->board($this->browserLiveRequest($this->client, $board, 'showMore'));
        $ranks = $this->ranks($more);
        self::assertGreaterThan(25, count($ranks));
        self::assertSame(range(1, count($ranks)), $ranks);
    }

    public function testEqualBestTimesShareTheRank(): void
    {
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_500_05, 3, firstSeconds: 100, secondsBetween: 0);

        $board = $this->board($this->client->request('GET', '/en/ladder/solo/500-pieces'));

        self::assertSame([1, 1, 1, 4, 5, 6, 7], $this->ranks($board));
    }

    public function testAMemberSeesRanksWithinTheirCountry(): void
    {
        // The seeded solvers have no country - in Czechia the admin leads, the regular player is second
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_500_05, 30, firstSeconds: 100);
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_ADMIN);

        $board = $this->board($this->client->request('GET', '/en/ladder/solo/500-pieces/country/cz'));
        self::assertResponseIsSuccessful();

        self::assertSame([1, 2], $this->ranks($board));
        self::assertStringContainsString('Czechia', $board->filter('[data-testid="lb-position"]')->text());
    }

    /**
     * @return iterable<string, array{null|string, string, string}>
     */
    public static function provideViewersWithoutARow(): iterable
    {
        yield 'guest' => [null, '/en/ladder/solo/500-pieces', 'See where you stand on this board'];
        yield 'private profile' => [PlayerFixture::PLAYER_PRIVATE, '/en/ladder/solo/500-pieces', 'Your profile is private'];
        yield 'no team time yet' => [PlayerFixture::PLAYER_REGULAR, '/en/ladder/groups/500-pieces', 'Add a team time of a 500-piece puzzle'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideViewersWithoutARow')]
    public function testAViewerWithoutARowIsToldWhyAndWhatToDo(null|string $playerId, string $path, string $expected): void
    {
        if ($playerId !== null) {
            TestingLogin::asPlayer($this->client, $playerId);
        }

        $board = $this->board($this->client->request('GET', $path));

        self::assertCount(0, $board->filter('[data-testid="lb-position"]'));
        self::assertStringContainsString($expected, $board->filter('[data-testid="lb-not-on-board"]')->text());
    }

    public function testTheFullBoardCostsTheSameWhereverTheViewerStands(): void
    {
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_REGULAR);
        // The first request of a session stores the visitor's locale - not part of the page
        $this->client->request('GET', '/en/ladder/solo/500-pieces');

        $this->startCountingQueries($this->client);
        $this->client->request('GET', '/en/ladder/solo/500-pieces');
        $nearTheTop = $this->queryCount($this->client);
        $windowStatements = array_filter($this->executedSql($this->client), static fn (string $sql): bool => str_contains($sql, 'own_position'));
        self::assertCount(1, $windowStatements, 'One window statement for the rows, the viewer and the position line');

        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_500_05, 60, firstSeconds: 100);
        $this->startCountingQueries($this->client);
        $this->client->request('GET', '/en/ladder/solo/500-pieces');
        self::assertSame($nearTheTop, $this->queryCount($this->client), 'The viewer far down costs nothing more');
    }

    public function testTheOverviewShowsTheViewersRowAfterTheTopTenAndLinksToTheBoardAroundThem(): void
    {
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_REGULAR);
        $this->client->request('GET', '/en/ladder');

        // Inside the top 10: no extra row, no link
        $this->startCountingQueries($this->client);
        $page = $this->client->request('GET', '/en/ladder');
        $inside = $this->queryCount($this->client);
        $solo500 = $page->filter('[data-live-name-value="LadderTable"]')->first();
        self::assertCount(0, $solo500->filter('.lb-gap-row'));
        self::assertCount(0, $solo500->filter('[data-testid="ladder-around-me"]'));

        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_500_05, 20, firstSeconds: 100);
        $this->startCountingQueries($this->client);
        $page = $this->client->request('GET', '/en/ladder');
        self::assertSame($inside, $this->queryCount($this->client), 'The own row joins the table\'s statement');

        $solo500 = $page->filter('[data-live-name-value="LadderTable"]')->first();
        self::assertSame([...range(1, 10), 23], $this->ranks($solo500));
        self::assertCount(1, $solo500->filter('.lb-gap-row'));
        self::assertCount(1, $solo500->filter('tr.table-active-player'));
        self::assertSame(
            '/en/ladder/solo/500-pieces#leaderboard-me',
            $solo500->filter('[data-testid="ladder-around-me"]')->attr('href'),
        );
    }

    private function board(Crawler $page): Crawler
    {
        $board = $page->filter('[data-live-name-value="LadderTable"]');
        self::assertCount(1, $board);

        return $board;
    }

    /**
     * @return list<int>
     */
    private function ranks(Crawler $board): array
    {
        return $board->filter('tbody tr:not(.lb-gap-row) .lb-rank')->each(static fn (Crawler $rank): int => (int) trim($rank->text()));
    }
}
