<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * What the player profile shows, per profile and viewer - captured from main before the profile's performance work
 * (rank pool by index, pairs + teams in one statement, chart counts in one statement) and pinned so none of it moves
 * a rank, a count, a row or a name. The expected extracts live next to this file (PlayerProfileRenderCharacterization/
 * <case>.json); they were written by running this test with PROFILE_CHARACTERIZATION_WRITE=1 on the old code, and a
 * change to them is a change of what players see - review it like one.
 *
 * The ratings world below puts players the ladder leaves out (private, opted out) above the ranked ones, a tie among
 * them, and a stored day the header chip's move compares with. The recent activity is left out on purpose (its query
 * changes on its own).
 */
final class PlayerProfileRenderCharacterizationTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string SNAPSHOT_DIRECTORY = __DIR__ . '/PlayerProfileRenderCharacterization';

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function provideCases(): iterable
    {
        yield 'regular, guest' => [PlayerFixture::PLAYER_REGULAR, null];
        yield 'regular, signed-in other' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_FAVORITES];
        yield 'regular, owner' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_REGULAR];
        yield 'regular, member' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE];
        // PLAYER_REGULAR blocks PLAYER_PRIVATE (UserBlockFixture): the blocked one sees the blocker's page normally
        yield 'regular, viewed by the player it blocks' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE];
        yield 'with favorites, guest' => [PlayerFixture::PLAYER_WITH_FAVORITES, null];
        yield 'with favorites, member' => [PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'with favorites, owner' => [PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_FAVORITES];
        yield 'member, owner' => [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'opted out, guest' => [PlayerFixture::PLAYER_ADMIN, null];
        yield 'private, guest' => [PlayerFixture::PLAYER_PRIVATE, null];
        yield 'private, owner' => [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_PRIVATE];
        // PLAYER_PRIVATE lets PLAYER_WITH_FAVORITES see her (PrivateProfileViewerFixture)
        yield 'private, allow-listed viewer' => [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES];
    }

    #[DataProvider('provideCases')]
    public function testTheProfileShowsWhatItShowedBefore(string $profileId, null|string $viewerId): void
    {
        $browser = self::createClient();
        self::ratingsWorld();

        if ($viewerId !== null) {
            TestingLogin::asPlayer($browser, $viewerId);
        }

        $crawler = $browser->request('GET', '/en/player-profile/' . $profileId);
        self::assertResponseIsSuccessful();

        $extract = self::pageExtract($crawler);

        // The pair and team tabs of the results, as a tap on them re-renders the component
        if ($crawler->filter('[data-live-name-value="PlayerSolvedPuzzles"]')->count() > 0) {
            foreach (['duo', 'group'] as $category) {
                $component = $this->createLiveComponent('PlayerSolvedPuzzles', ['playerId' => $profileId], $browser);
                $component->setRouteLocale('en');
                $component->call('changeResultsCategory', ['category' => $category]);
                $extract['results_' . $category] = self::resultsExtract($component->render()->crawler());
            }
        }

        $this->assertSnapshot($this->dataName(), $extract);
    }

    public function testThePlayerWhoBlocksSomebodyDoesNotFindTheirProfile(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE);

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowMoreKeepsTheFurtherRows(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $component = $this->createLiveComponent('PlayerSolvedPuzzles', ['playerId' => PlayerFixture::PLAYER_REGULAR], $browser);
        $component->setRouteLocale('en');
        $before = self::resultsExtract($component->render()->crawler());
        $component->call('showMore');
        $after = self::resultsExtract($component->render()->crawler());

        // The fixtures hold fewer rows than a page: "Show more" changes nothing, the rows stay as they were
        self::assertSame($before['rows'], array_slice($after['rows'], 0, count($before['rows'])));
        $this->assertSnapshot('show more', $after);
    }

    /**
     * @return array<string, mixed>
     */
    private static function pageExtract(Crawler $crawler): array
    {
        $chart = $crawler->filter('[data-live-name-value="Chart:PlayerAverageTimeChart"]');
        $results = $crawler->filter('[data-live-name-value="PlayerSolvedPuzzles"]');

        return [
            'h1' => self::texts($crawler->filter('h1')),
            'chips' => self::texts($crawler->filter('.player-chip')),
            'private_alert' => self::texts($crawler->filter('.alert-secondary')->reduce(static fn (Crawler $alert): bool => $alert->filter('.bi-incognito')->count() > 0)),
            'standing' => self::texts($crawler->filter('.profile-standing .card')),
            'rating_history_frame' => $crawler->filter('turbo-frame#msp-rating-history')->count(),
            'chart' => $chart->count() === 0 ? null : [
                'heading' => self::texts($chart->filter('h2')),
                // The two lists as sets: equal counts come in whatever order the database's aggregate gives (they swapped between
                // runs on one database), the order itself is PlayerAverageTimeChartTest's. The heading above pins the default
                'pieces' => self::sorted($chart->filter('select[data-model="pieces"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'))),
                'brands' => self::sorted($chart->filter('select[data-model="brand"] option')->each(static fn (Crawler $option): string => $option->attr('value') . ' = ' . self::text($option))),
                'locked' => $chart->filter('.player-chart-placeholder')->count(),
                'canvas' => $chart->filter('canvas')->count(),
            ],
            'results' => $results->count() === 0 ? null : self::resultsExtract($results),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function resultsExtract(Crawler $results): array
    {
        return [
            'tabs' => $results->filter('[data-live-action-param="changeResultsCategory"]')->each(
                static fn (Crawler $button): string => self::text($button) . ($button->attr('disabled') !== null ? ' [disabled]' : '') . (str_contains((string) $button->attr('class'), 'active') ? ' [active]' : ''),
            ),
            'brands' => $results->filter('select[data-model="manufacturer"] option')->each(static fn (Crawler $option): string => self::text($option)),
            'teams' => $results->filter('select[data-model="team"] option')->each(static fn (Crawler $option): string => self::text($option)),
            'rows' => $results->filter('.ps-table tbody tr')->each(static fn (Crawler $row): string => implode(' | ', array_filter([
                trim((string) $row->attr('class')),
                self::text($row),
                $row->filter('.diff-corner')->count() > 0 ? 'corner' : '',
                $row->filter('[data-bs-toggle="dropdown"]')->count() > 0 ? 'actions' : '',
                implode(',', $row->filter('a[href*="/puzzle/"]')->each(static fn (Crawler $link): string => (string) $link->attr('href'))),
            ], static fn (string $part): bool => $part !== ''))),
            'shown' => self::texts($results->filter('[aria-live="polite"]')),
        ];
    }

    /**
     * @return list<string>
     */
    private static function texts(Crawler $nodes): array
    {
        return array_values($nodes->each(static fn (Crawler $node): string => self::text($node)));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }

    private static function text(Crawler $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->text('', false)));
    }

    /**
     * @param array<string, mixed> $actual
     */
    private function assertSnapshot(string $case, array $actual): void
    {
        $file = self::SNAPSHOT_DIRECTORY . '/' . preg_replace('/[^a-z0-9]+/', '-', strtolower($case)) . '.json';
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        if (getenv('PROFILE_CHARACTERIZATION_WRITE') === '1') {
            if (is_dir(self::SNAPSHOT_DIRECTORY) === false) {
                mkdir(self::SNAPSHOT_DIRECTORY);
            }

            file_put_contents($file, $json);
            self::markTestIncomplete('Snapshot written: ' . $file);
        }

        self::assertFileExists($file);
        self::assertSame(file_get_contents($file), $json, $case);
    }

    /**
     * 500 pieces today: Private 1.9 (left out: private), Admin 1.8 (left out: opted out), Regular 1.5 = With stripe 1.5
     * (a tie), With favorites 1.2. 1000 pieces: Private 1.3, Regular 1.1, With favorites 1.0.
     * Stored 7 Oct (the day the header chip's move compares with on 14 Oct): Private 1.95, Admin 1.85, With favorites 1.7,
     * Regular 1.6, With stripe 1.3; an older day 1 Oct the move must not pick.
     */
    public static function ratingsWorld(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_ADMIN]);

        $today = [
            [PlayerFixture::PLAYER_PRIVATE, 500, 1.9],
            [PlayerFixture::PLAYER_ADMIN, 500, 1.8],
            [PlayerFixture::PLAYER_REGULAR, 500, 1.5],
            [PlayerFixture::PLAYER_WITH_STRIPE, 500, 1.5],
            [PlayerFixture::PLAYER_WITH_FAVORITES, 500, 1.2],
            [PlayerFixture::PLAYER_PRIVATE, 1000, 1.3],
            [PlayerFixture::PLAYER_REGULAR, 1000, 1.1],
            [PlayerFixture::PLAYER_WITH_FAVORITES, 1000, 1.0],
        ];

        foreach ($today as [$playerId, $pieces, $rating]) {
            $connection->executeStatement(
                'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, :pieces, :rating, ' . TestClock::SQL_NOW . ')',
                ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'pieces' => $pieces, 'rating' => $rating],
            );
        }

        $stored = [
            '2026-10-07' => [PlayerFixture::PLAYER_PRIVATE => 1.95, PlayerFixture::PLAYER_ADMIN => 1.85, PlayerFixture::PLAYER_WITH_FAVORITES => 1.7, PlayerFixture::PLAYER_REGULAR => 1.6, PlayerFixture::PLAYER_WITH_STRIPE => 1.3],
            '2026-10-01' => [PlayerFixture::PLAYER_WITH_STRIPE => 2.5, PlayerFixture::PLAYER_REGULAR => 1.0],
        ];

        foreach ($stored as $day => $ratings) {
            foreach ($ratings as $playerId => $rating) {
                $connection->executeStatement(
                    'INSERT INTO player_rating_snapshot (id, player_id, pieces_count, snapshot_date, elo_rating, elo_rank, computed_at) VALUES (:id, :player, 500, :day, :rating, 1, ' . TestClock::SQL_NOW . ')',
                    ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'day' => $day, 'rating' => $rating],
                );
            }
        }
    }
}
