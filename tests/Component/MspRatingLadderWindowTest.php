<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
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
 * The MSP Rating ladder without page numbers (docs/features/xp-levels/ux-polish/design-spec.md §4): top 100, a "⋯ N more"
 * row revealing exactly the rows it counts, the viewer's row with 2 above and 2 below, "Show more" after the last row
 * shown, the position line - driven from the page the way the browser does it.
 */
final class MspRatingLadderWindowTest extends WebTestCase
{
    use LeaderboardSeeding;
    use LiveComponentBrowserRequests;
    use QueryCountAssertions;

    private const int SEEDED = 150;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // 150 rated players above the fixture players: the viewer (rated 1.2) is #151 of 152
        $seeded = $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_05, self::SEEDED, firstSeconds: 100);
        $database = self::getContainer()->get(Connection::class);

        foreach ($seeded as $index => $playerId) {
            $this->rate($database, $playerId, 2.0 - $index * 0.001);
        }

        $this->rate($database, PlayerFixture::PLAYER_WITH_STRIPE, 1.2);
        $this->rate($database, PlayerFixture::PLAYER_REGULAR, 1.1);
    }

    public function testTheViewerFarDownSeesTheTopTheGapRowTheirRowsAndTheirPositionLine(): void
    {
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_WITH_STRIPE);
        $page = $this->client->request('GET', '/en/msp-rating');
        self::assertResponseIsSuccessful();

        $board = $page->filter('[data-live-name-value="MspRatingLadder"]');
        // Top 25 + #149 #150 above the viewer (#151) + #152 below
        self::assertSame([...range(1, 25), 149, 150, 151, 152], $this->ranks($board));
        self::assertCount(1, $board->filter('.lb-gap-row'));
        // At most 50 rows per tap, and the button names them
        self::assertStringContainsString('Show ranks 26–75 of 152', $board->filter('.lb-gap-row')->text());
        self::assertSame('#151', '#' . trim($board->filter('#my-rating-row .lb-rank')->text()));
        self::assertStringContainsString('#151 of 152', $board->filter('[data-testid="lb-position"]')->text());
        self::assertCount(0, $board->filter('.pagination'));
        self::assertCount(0, $board->filter('.lb-show-more'), 'Nothing below the last row shown');

        // The gap row reveals the next rows after the top block, until the ranking runs on without a break
        $revealed = $board;
        $shown = count($this->ranks($board));

        for ($tap = 0; $tap < 10 && $revealed->filter('.lb-gap-row')->count() > 0; $tap++) {
            $revealed = $this->root($this->browserLiveRequest($this->client, $revealed, 'revealGap'));
            self::assertGreaterThan($shown, count($this->ranks($revealed)));
            $shown = count($this->ranks($revealed));
        }

        self::assertSame(range(1, 152), $this->ranks($revealed));
        self::assertCount(0, $revealed->filter('.lb-gap-row'));
    }

    public function testShowMoreContinuesAfterTheLastRowShownAndKeepsTheRowsOnTheNextRequest(): void
    {
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_ADMIN);
        // The admin (a member) is not rated: no own rows, the list is the top 100
        $page = $this->client->request('GET', '/en/msp-rating');
        $board = $page->filter('[data-live-name-value="MspRatingLadder"]');
        self::assertSame(range(1, 25), $this->ranks($board));
        self::assertCount(0, $board->filter('[data-testid="lb-position"]'));
        self::assertCount(1, $board->filter('.lb-show-more'));

        $more = $this->root($this->browserLiveRequest($this->client, $board, 'showMore'));
        $afterMore = $this->ranks($more);
        self::assertGreaterThan(25, count($afterMore));
        self::assertSame(range(1, count($afterMore)), $afterMore);

        // A plain re-render of the same list (the browser re-sends its models) keeps every revealed row
        $again = $this->browserLiveRequest($this->client, $more);
        self::assertSame($afterMore, $this->ranks($again));
    }

    public function testAnotherListStartsFromItsTopAgainWithRanksOfThatList(): void
    {
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_WITH_STRIPE);
        $board = $this->client->request('GET', '/en/msp-rating')->filter('[data-live-name-value="MspRatingLadder"]');
        $revealed = $this->root($this->browserLiveRequest($this->client, $board, 'revealGap'));
        self::assertGreaterThan(29, count($this->ranks($revealed)));

        // Filtered by Great Britain (the viewer's country): one row, rank 1 - the list's own rank, not the world's #151
        $british = $this->browserLiveRequest($this->client, $revealed, updated: ['country' => 'gb']);
        self::assertSame([1], $this->ranks($british));
        self::assertStringContainsString('#1 of 1 in United Kingdom', $british->filter('[data-testid="lb-position"]')->text());
    }

    public function testCountryAndFavoritesFromTheUrlAreDroppedForNonMembers(): void
    {
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_REGULAR);
        $board = $this->client->request('GET', '/en/msp-rating?country=gb&onlyFavorites=1')->filter('[data-live-name-value="MspRatingLadder"]');

        // The world list: the viewer is #152 of 152, the British member is #151
        self::assertStringContainsString('#152 of 152', $board->filter('[data-testid="lb-position"]')->text());
        self::assertContains(151, $this->ranks($board));
    }

    public function testOneWindowStatementWhereverTheViewerStands(): void
    {
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_WITH_STRIPE);
        // The first request of a session stores the visitor's locale - not part of the page
        $this->client->request('GET', '/en/msp-rating');
        $this->startCountingQueries($this->client);
        $this->client->request('GET', '/en/msp-rating');
        self::assertResponseIsSuccessful();
        $farDown = $this->queryCount($this->client);
        $windowStatements = array_filter($this->executedSql($this->client), static fn (string $sql): bool => str_contains($sql, 'own_position'));
        self::assertCount(1, $windowStatements, 'One window statement for the rows, the viewer and the position line');

        // The same page for a viewer who is not on the board costs the same - nothing per row, nothing per viewer
        $this->rate(self::getContainer()->get(Connection::class), PlayerFixture::PLAYER_WITH_STRIPE, 3.0);
        $this->startCountingQueries($this->client);
        $this->client->request('GET', '/en/msp-rating');
        self::assertSame($farDown, $this->queryCount($this->client));
    }

    public function testGuestPageQueryBudget(): void
    {
        $this->startCountingQueries($this->client);
        $this->client->request('GET', '/en/msp-rating');
        self::assertResponseIsSuccessful();

        // The window statement and what the base layout needs - nothing per row
        $this->assertQueryCountAtMost($this->client, 3, 'MSP Rating page for a guest');
    }

    private function rate(Connection $database, string $playerId, float $rating): void
    {
        $database->executeStatement(
            'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :playerId, 500, :rating, NOW())
             ON CONFLICT (player_id, pieces_count) DO UPDATE SET elo_rating = EXCLUDED.elo_rating',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'rating' => $rating],
            ['rating' => ParameterType::STRING],
        );
    }

    /**
     * @return list<int>
     */
    private function ranks(Crawler $board): array
    {
        return $board->filter('tbody tr:not(.lb-gap-row) .lb-rank')->each(static fn (Crawler $rank): int => (int) trim($rank->text()));
    }

    private function root(Crawler $response): Crawler
    {
        return $response->filter('[data-live-name-value="MspRatingLadder"]');
    }
}
