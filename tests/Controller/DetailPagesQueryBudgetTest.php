<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\EventDetailFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The series, edition and event pages cost a fixed number of statements (docs/features/events-page/detail-pages-plan.md
 * 1.10), measured on the foundation's skeleton and pinned exactly - a higher number is a bug to explain, not a new
 * number. The second request is counted (the first warms the caches). Signed in adds what every signed-in request loads
 * (account, profile, unread conversations, unread notifications) and the permissions statement the voters share.
 *
 * Series: the series (sections flag inside), its occurrences in one statement, the going counts of the coming ones
 * (none without any); signed in + the viewer's going/follow rows + permissions.
 * Edition and event: the event, rounds with their puzzles, visibility, puzzles outside the rounds, difficulty, statuses,
 * results per round (public, a slugged round), attendance with the follow flag, offers, participants.
 */
final class DetailPagesQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string HARBOR = '/en/series/harbor-jigsaw-nights';
    private const string SUMMIT = '/en/series/summit-puzzle-league';
    private const string SEASON_ONE = '/en/series/moonlight-sprint-league/season-one';
    private const string HILLTOP = '/en/events/hilltop-puzzle-weekend';

    /**
     * @return iterable<string, array{string, null|string, int}>
     */
    public static function providePages(): iterable
    {
        // The series, its occurrences, the going counts (2026-10-08: 3, the old page 4)
        yield 'series, guest' => [self::HARBOR, null, 3];
        // + the viewer's going/follow rows, the permissions statement and the signed-in overhead (4)
        yield 'series, player' => [self::HARBOR, PlayerFixture::PLAYER_REGULAR, 9];
        yield 'series, organiser' => [self::HARBOR, PlayerFixture::PLAYER_ADMIN, 9];
        // Nothing coming: no going counts
        yield 'series without editions, guest' => [self::SUMMIT, null, 2];
        yield 'series without editions, player' => [self::SUMMIT, PlayerFixture::PLAYER_REGULAR, 8];
        // The old edition page + results per round
        yield 'edition, guest' => [self::SEASON_ONE, null, 12];
        // + the signed-in overhead (4), statuses, attendance and the permissions statement
        yield 'edition, player' => [self::SEASON_ONE, PlayerFixture::PLAYER_REGULAR, 19];
        yield 'edition, organiser' => [self::SEASON_ONE, PlayerFixture::PLAYER_ADMIN, 19];
        // Below the old wjpc-2024 page (16): round puzzles are no longer read a second time; the marketplace card's one
        yield 'event, guest' => [self::HILLTOP, null, 12];
        yield 'event, player' => [self::HILLTOP, PlayerFixture::PLAYER_REGULAR, 19];
        yield 'event, organiser' => [self::HILLTOP, PlayerFixture::PLAYER_ADMIN, 19];
    }

    #[DataProvider('providePages')]
    public function testThePageCost(string $url, null|string $playerId, int $statements): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        self::assertSame($statements, $this->measure($browser, $url), ($playerId ?? 'guest') . ' ' . $url);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideMaintainerPages(): iterable
    {
        // A non-admin organiser: maintainer of both series (Season One is edited through its series), creator of the
        // one-time event - the same statements as an admin, the permissions statement answers both
        yield 'series, maintainer' => [self::HARBOR, 9];
        yield 'edition, series maintainer' => [self::SEASON_ONE, 19];
        yield 'event, creator' => [self::HILLTOP, 19];
    }

    #[DataProvider('provideMaintainerPages')]
    public function testThePageCostForANonAdminOrganiser(string $url, int $statements): void
    {
        $browser = self::createClient();

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $organiser = PlayerFixture::PLAYER_WITH_FAVORITES;

        foreach ([EventsPageFixture::SERIES_HARBOR_NIGHTS, EventsPageFixture::SERIES_SPRINT_LEAGUE] as $seriesId) {
            $connection->executeStatement(
                'INSERT INTO competition_series_maintainer (competition_series_id, player_id) VALUES (:series, :player)',
                ['series' => $seriesId, 'player' => $organiser],
            );
        }

        $connection->executeStatement(
            'UPDATE competition SET added_by_player_id = :player WHERE id = :id',
            ['player' => $organiser, 'id' => EventDetailFixture::COMPETITION_HILLTOP_WEEKEND],
        );

        TestingLogin::asPlayer($browser, $organiser);

        self::assertSame($statements, $this->measure($browser, $url), 'maintainer ' . $url);
        self::assertCount(1, $browser->getCrawler()->filter('.ev-detail-actions .ev-manage'), 'the organiser gets the header menu');
    }

    public function testTenMoreEditionsAddNoStatementToTheSeriesPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $before = $this->measure($browser, self::HARBOR);

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);

        for ($i = 1; $i <= 10; $i++) {
            $editionId = Uuid::uuid7()->toString();
            $connection->executeStatement(
                "INSERT INTO competition (id, name, slug, series_id, is_online, created_at)
                 VALUES (:id, :name, :slug, :series, true, NOW())",
                ['id' => $editionId, 'name' => 'Budget session ' . $i, 'slug' => 'budget-session-' . $i, 'series' => EventsPageFixture::SERIES_HARBOR_NIGHTS],
            );

            // Two rounds a month apart - two sessions each
            foreach ([20, 50] as $days) {
                $connection->executeStatement(
                    "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, slug)
                     VALUES (:id, :competition, :name, 60, NOW() + make_interval(days => :days), :slug)",
                    ['id' => Uuid::uuid7()->toString(), 'competition' => $editionId, 'name' => 'Round ' . $days, 'days' => $days + $i, 'slug' => 'round-' . $days],
                );
            }
        }

        self::assertSame($before, $this->measure($browser, self::HARBOR), 'ten more editions must not add statements');
        self::assertGreaterThanOrEqual(20, $browser->getCrawler()->filter('[data-series-edition]')->count(), 'every session is listed');
    }

    public function testSixMoreRoundsWithPuzzlesAddNoStatementToTheEditionPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $before = $this->measure($browser, self::SEASON_ONE);

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $puzzles = [PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_1000_03, PuzzleFixture::PUZZLE_1500_01, PuzzleFixture::PUZZLE_1500_02, PuzzleFixture::PUZZLE_2000];

        foreach ($puzzles as $i => $puzzleId) {
            $roundId = Uuid::uuid7()->toString();
            $connection->executeStatement(
                "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, slug, timezone)
                 VALUES (:id, :competition, :name, 60, NOW() + make_interval(days => :days), :slug, 'America/New_York')",
                ['id' => $roundId, 'competition' => EventsPageFixture::EDITION_SPRINT_SEASON, 'name' => 'Extra ' . $i, 'days' => 80 + 30 * $i, 'slug' => 'extra-' . $i],
            );
            $connection->executeStatement(
                'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id) VALUES (:id, :round, :puzzle)',
                ['id' => Uuid::uuid7()->toString(), 'round' => $roundId, 'puzzle' => $puzzleId],
            );
        }

        self::assertSame($before, $this->measure($browser, self::SEASON_ONE), 'six more rounds with puzzles must not add statements');
        self::assertCount(10, $browser->getCrawler()->filter('.ev-round'));
    }

    private function measure(KernelBrowser $browser, string $url): int
    {
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }
}
