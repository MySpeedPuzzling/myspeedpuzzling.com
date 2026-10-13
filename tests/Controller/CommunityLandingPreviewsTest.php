<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Query\GetFastestPlayers;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\Community\CommunityPreview;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Community landing's previews (docs/features/navigation README §4.9, COM-01): the top of the solo 500-piece
 * leaderboard, the most active puzzlers this month, the next events - at most three rows each, one per series among
 * the events (decision 74). Drafts, blocks and private profiles: DraftCanaryTest, BlocklistCanaryTest,
 * PrivateProfileCanaryTest, PlayersPageCanaryTest.
 */
final class CommunityLandingPreviewsTest extends WebTestCase
{
    public function testTheLeadersAreTheTopOfTheSolo500Leaderboard(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/community');
        self::assertResponseIsSuccessful();

        $top = self::getContainer()->get(GetFastestPlayers::class)->perPiecesCount(CommunityPreview::LEADERBOARD_PIECES, CommunityPreview::LEADERS, null);
        self::assertCount(CommunityPreview::LEADERS, $top, 'The fixtures have three solo 500-piece players or more');

        $rows = $crawler->filter('.community-leaderboards .community-preview-row');
        self::assertSame(['1', '2', '3'], $rows->filter('.community-preview-rank')->each(static fn (Crawler $rank): string => trim($rank->text())));
        self::assertSame(
            array_map(static fn (SolvedPuzzle $leader): string => '/en/player-profile/' . $leader->playerId, $top),
            $rows->filter('a.lb-name')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        self::assertCount(CommunityPreview::LEADERS, $rows->filter('a[data-nav="community/community/leader"]'));
        self::assertStringContainsString('Fastest solo · 500 pieces', $crawler->filter('.community-leaderboards .community-preview-title')->text());
    }

    public function testTheMostActivePuzzlersOfTheMonthWithTheirCount(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/community');
        self::assertResponseIsSuccessful();
        self::assertSame('Nobody has logged a puzzle this month yet.', trim($crawler->filter('.community-puzzlers .community-preview-empty')->text()), 'Before the stats are computed the card says so');

        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE community_player_stats SET solves_this_month = 0, pieces_this_month = 0');
        $database->executeStatement(
            'UPDATE community_player_stats SET solves_this_month = :solves, pieces_this_month = :pieces, solved_total = GREATEST(solved_total, 1) WHERE player_id = :id',
            ['solves' => 12, 'pieces' => 12000, 'id' => PlayerFixture::PLAYER_REGULAR],
        );
        $database->executeStatement(
            'UPDATE community_player_stats SET solves_this_month = :solves, pieces_this_month = :pieces, solved_total = GREATEST(solved_total, 1) WHERE player_id = :id',
            ['solves' => 1, 'pieces' => 500, 'id' => PlayerFixture::PLAYER_ADMIN],
        );

        $crawler = $browser->request('GET', '/en/community');
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('.community-puzzlers .community-preview-row');
        self::assertCount(2, $rows, 'Only puzzlers with a puzzle this month');
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $rows->eq(0)->filter('a[data-nav="community/community/puzzler"]')->attr('href'));
        self::assertSame('12 puzzles this month', trim($rows->eq(0)->filter('.community-preview-value')->text()));
        self::assertSame('1 puzzle this month', trim($rows->eq(1)->filter('.community-preview-value')->text()));
    }

    public function testTheNextEventsAreAtMostThreeAndNeverOver(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/community');
        self::assertResponseIsSuccessful();

        $events = $crawler->filter('[data-community-events] .community-event');
        self::assertGreaterThan(0, $events->count(), 'The fixtures have upcoming events');
        self::assertLessThanOrEqual(CommunityPreview::EVENTS, $events->count());
        self::assertCount($events->count(), $events->filter('a[data-nav="community/community/event"]'));

        $content = $crawler->filter('[data-community-events]')->html();
        // EJJ #68 is a month ago, the Berlin Puzzle Cup's only edition 45 days ago
        self::assertStringNotContainsString('ejj-68-february-2026', $content);
        self::assertStringNotContainsString('berlin-puzzle-cup-2026', $content);
    }

    /**
     * Decision 74: a weekly series would fill every row - its next edition only
     */
    public function testASeriesShowsItsNextEditionOnly(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        // Both Euro Jigsaw Jam editions in the next two days
        foreach ([CompetitionSeriesFixture::EDITION_EJJ_68 => 1, CompetitionSeriesFixture::EDITION_EJJ_69 => 2] as $editionId => $days) {
            $database->executeStatement(
                'UPDATE competition SET date_from = CURRENT_DATE + CAST(:days AS INT), date_to = CURRENT_DATE + CAST(:days AS INT) WHERE id = :id',
                ['days' => $days, 'id' => $editionId],
            );
            $database->executeStatement(
                "UPDATE competition_round SET starts_at = CURRENT_DATE + CAST(:days AS INT) + TIME '12:00' WHERE competition_id = :id",
                ['days' => $days, 'id' => $editionId],
            );
        }

        $crawler = $browser->request('GET', '/en/community');
        self::assertResponseIsSuccessful();

        $series = $crawler->filter('[data-community-events] a[href^="/en/series/euro-jigsaw-jam-series/"]');
        self::assertCount(1, $series, 'One row of the series');
        self::assertSame('/en/series/euro-jigsaw-jam-series/ejj-68-february-2026', $series->attr('href'), 'Its next edition');
    }

    /**
     * Review PUZ+COM Q2: the previews are the same for every viewer except the players a signed-in viewer's blocks hide
     * (BlocklistCanaryTest) and the star _leaderboard_player puts in front of the viewer's own favourites; every guest
     * gets the same HTML (the page is shared-cacheable). The viewers here block nobody (UserBlockFixture).
     */
    public function testThePreviewsAreTheSameForEveryViewerButTheirFavouritesStar(): void
    {
        $browser = self::createClient();
        $guest = $this->previews($browser);
        $leaders = self::getContainer()->get(GetFastestPlayers::class)->window(CommunityPreview::LEADERBOARD_PIECES, null, CommunityPreview::LEADERS)->rows;
        self::assertCount(CommunityPreview::LEADERS, $leaders);
        self::assertStringNotContainsString('ci-star-filled', $guest, 'A guest has no favourites');

        // Another guest: the same HTML
        self::ensureKernelShutdown();
        $browser = self::createClient();
        self::assertSame($guest, $this->previews($browser));

        // A member following none of the leaders (made sure, not left to the fixtures): the same previews
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement("UPDATE player SET favorite_players = '[]' WHERE id = :id", ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame($guest, $this->previews($browser));

        // A player following one of the leaders: the star in front of that leader's name, nothing else differs
        $favourite = $leaders[1]->playerId === PlayerFixture::PLAYER_WITH_FAVORITES ? $leaders[2]->playerId : $leaders[1]->playerId;
        $database->executeStatement(
            'UPDATE player SET favorite_players = :favourites WHERE id = :id',
            ['favourites' => json_encode([$favourite], JSON_THROW_ON_ERROR), 'id' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', '/en/community');
        self::assertResponseIsSuccessful();

        self::assertSame(
            ['/en/player-profile/' . $favourite],
            $crawler->filter('.community-preview a.lb-name')
                ->reduce(static fn (Crawler $link): bool => $link->filter('.ci-star-filled')->count() > 0)
                ->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        $star = '<i class="ci-star-filled text-warning me-1"></i>';
        self::assertSame(1, substr_count($this->previewsOf($crawler), $star));
        self::assertSame($guest, str_replace($star, '', $this->previewsOf($crawler)));
    }

    private function previews(KernelBrowser $browser): string
    {
        $crawler = $browser->request('GET', '/en/community');
        self::assertResponseIsSuccessful();

        return $this->previewsOf($crawler);
    }

    private function previewsOf(Crawler $crawler): string
    {
        // Members see the leaders' tier icons nowhere here (show_tier off)
        return (string) preg_replace('/\s+/', ' ', implode('', $crawler->filter('.community-preview')->each(static fn (Crawler $preview): string => $preview->html())));
    }
}
