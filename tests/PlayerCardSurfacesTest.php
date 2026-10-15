<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The player card on every surface that lists players (docs/features/navigation CARD-01b): each profile link in the
 * list opens the card - it carries data-player-card + aria-haspopup="dialog", server-rendered (Live Components re-apply
 * attributes JavaScript changed) - and the list says what the card's context is (data-player-card-context, from ids the
 * page already has: no statement). No triggers inside a modal (the result detail's), and guest pages stay
 * shared-cacheable (the triggers are the same for every visitor).
 *
 * The compare page's subject names (line-up chips, league table, head-to-head) open the card; its add sheet is a modal:
 * "Someone at your speed" there links the profile without a trigger (tests/Component/ComparisonSimilarSpeedTest.php).
 * The Players page lists by the Players tests (tests/Component/Players/*, PlayersDirectoryControllerTest,
 * PlayersPerCountryControllerTest).
 */
final class PlayerCardSurfacesTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    /**
     * @return iterable<string, array{null|string, string, string, null|string, null|string}>
     *     viewer, URL, list container, expected context of the container (null = none), seed
     */
    public static function surfaces(): iterable
    {
        $puzzle = 'puzzle:' . PuzzleFixture::PUZZLE_500_02;

        yield 'puzzle leaderboard' => [null, '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02, 'table[data-player-card-context="' . $puzzle . '"]', $puzzle, null];
        yield 'hub feed' => [null, '/en/hub', '.ra-list', null, null];
        yield 'recent activity' => [PlayerFixture::PLAYER_ADMIN, '/en/recent-activity', '.ra-list', null, null];
        yield 'ladder overview' => [null, '/en/ladder', 'table[data-player-card-context="leaderboard:times-500"]', 'leaderboard:times-500', null];
        yield 'ladder, solo 500' => [PlayerFixture::PLAYER_ADMIN, '/en/ladder/solo/500-pieces', 'table[data-player-card-context="leaderboard:times-500"]', 'leaderboard:times-500', null];
        yield 'ladder, solo 500 in a country' => [null, '/en/ladder/solo/500-pieces/country/cz', 'table[data-player-card-context="leaderboard:times-500:cz"]', 'leaderboard:times-500:cz', null];
        yield 'ladder, pairs (no context)' => [null, '/en/ladder/pairs/1000-pieces', '.ps-table', null, null];
        yield 'MSP Rating ladder' => [PlayerFixture::PLAYER_WITH_STRIPE, '/en/msp-rating', 'table[data-player-card-context="leaderboard:msp-500"]', 'leaderboard:msp-500', 'ratings'];
        yield 'level board' => [null, '/en/ladder/level', 'table[data-player-card-context="leaderboard:xp"]', 'leaderboard:xp', 'xp'];
        yield 'achievement points board' => [PlayerFixture::PLAYER_WITH_STRIPE, '/en/ladder/achievement-points', 'table[data-player-card-context="leaderboard:ap"]', 'leaderboard:ap', 'achievement_points'];
        yield 'karma board' => [null, '/en/ladder/karma', 'table[data-player-card-context="leaderboard:karma"]', 'leaderboard:karma', 'karma'];
        yield 'result detail page' => [null, '/en/result/' . PuzzleSolvingTimeFixture::TIME_08, '.pr-head[data-player-card-context="' . $puzzle . '"]', $puzzle, null];
        yield 'round results, official' => [null, '/en/events/results-cup/results/group-a', '[data-official-results][data-player-card-context="round:' . OfficialResultsFixture::ROUND_GROUP_A . '"]', 'round:' . OfficialResultsFixture::ROUND_GROUP_A, null];
        yield 'round results, unofficial' => [null, '/en/series/moonlight-sprint-league/season-one/results/sprint-1', '[data-player-card-context="round:' . EventsPageFixture::ROUND_SPRINT_1 . '"]', 'round:' . EventsPageFixture::ROUND_SPRINT_1, null];
        yield 'favorites' => [PlayerFixture::PLAYER_WITH_FAVORITES, '/en/player-favorites/' . PlayerFixture::PLAYER_WITH_FAVORITES, '.fp-item', null, null];
        yield 'notifications' => [PlayerFixture::PLAYER_WITH_FAVORITES, '/en/notifications', 'tr[data-player-card-context="notification:' . PuzzleSolvingTimeFixture::TIME_41 . '"]', 'notification:' . PuzzleSolvingTimeFixture::TIME_41, null];
        yield 'event participants' => [null, '/en/events/wjpc-2024', '.ev-participants', null, null];
        yield 'results co-puzzlers' => [PlayerFixture::PLAYER_ADMIN, '/en/player-results/' . PlayerFixture::PLAYER_REGULAR . '?category=duo', '.lb-player', null, null];
        yield 'your week' => [PlayerFixture::PLAYER_WITH_FAVORITES, '/en/my/activity', '#favorites', null, null];
        yield 'achievement holders' => [null, '/en/achievements/zen_puzzler', '#tier-1', null, 'holder'];
        yield 'players page directory' => [null, '/en/puzzlers/all', '.players-directory-grid', null, null];
        yield 'community landing' => [null, '/en/community', 'main', null, null];
        // QA-04 R02: the line-up of the solo comparison (you, Anna, Regular - the league table) and the subject names
        yield 'comparison line-up' => [PlayerFixture::PLAYER_WITH_STRIPE, '/en/compare?kind=solo', '[data-testid="comparison-line-up"]', null, null];
        yield 'comparison league' => [PlayerFixture::PLAYER_WITH_STRIPE, '/en/compare?kind=solo', '[data-testid="comparison-league"]', null, null];
    }

    /**
     * The compare page's modals - the add sheet, the swap modal - carry no trigger (CARD-01a), the page's names do
     */
    public function testTheComparisonsModalsHaveNoTriggers(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/compare?kind=solo');
        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('main [data-player-card]')->count());
        self::assertCount(0, $crawler->filter('.modal [data-player-card], .modal [aria-haspopup="dialog"]'));
    }

    #[DataProvider('surfaces')]
    public function testEveryPlayerLinkInTheListOpensTheCard(null|string $viewer, string $url, string $container, null|string $context, null|string $seed): void
    {
        $browser = self::createClient();

        if ($seed !== null) {
            $this->seed($seed);
        }

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        }

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        $lists = $crawler->filter($container);
        self::assertGreaterThan(0, $lists->count(), 'No list on the page: ' . $container);

        if ($context !== null) {
            self::assertSame($context, $lists->first()->attr('data-player-card-context'));
        }

        $this->assertEveryProfileLinkIsATrigger($lists);
    }

    public function testTheMostActivePuzzlersOpenTheCard(): void
    {
        self::createClient();

        $crawler = $this->createLiveComponent('MostActiveSoloPlayers', ['timespan' => 'all_time'])->render()->crawler();

        $this->assertEveryProfileLinkIsATrigger($crawler->filter('table'));
    }

    public function testTheTeamPageNamesOpenTheCard(): void
    {
        $browser = self::createClient();
        $teamId = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        self::assertIsString($teamId);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/teams/' . $teamId);

        self::assertResponseIsSuccessful();
        $this->assertEveryProfileLinkIsATrigger($crawler->filter('[data-testid="team-members"]'));
    }

    /**
     * The result detail in the modal has no triggers - names inside modals link to the profile (README §4.8, decision
     * 73); the full page has them (testEveryPlayerLinkInTheListOpensTheCard)
     */
    public function testTheResultDetailModalHasNoTriggers(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/result/' . PuzzleSolvingTimeFixture::TIME_08, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href^="/en/player-profile/"]')->count());
        self::assertCount(0, $crawler->filter('[data-player-card]'));
        self::assertCount(0, $crawler->filter('[data-player-card-context]'));
    }

    /**
     * The avatar is a second link to the same profile: out of the tab order and hidden from screen readers (the name
     * is the one stop), same context
     */
    public function testTheAvatarIsASecondHiddenLinkToTheSameProfile(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02);

        $player = $crawler->filter('table[data-player-card-context] .lb-player')->first();
        $avatar = $player->filter('a.lb-avatar-link');
        $name = $player->filter('a.lb-name');
        self::assertSame($name->attr('href'), $avatar->attr('href'));
        self::assertSame('-1', $avatar->attr('tabindex'));
        self::assertSame('true', $avatar->attr('aria-hidden'));
        self::assertNotNull($avatar->attr('data-player-card'));
        self::assertSame('dialog', $avatar->attr('aria-haspopup'));
        self::assertNull($name->attr('aria-expanded'), 'No aria-expanded: Live Components would re-apply what JavaScript changed');
    }

    /**
     * The triggers are the same for every guest: the pages stay shared-cacheable
     */
    #[DataProvider('guestPages')]
    public function testGuestPagesStayPublic(string $url): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[data-player-card]')->count());
        self::assertStringContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function guestPages(): iterable
    {
        yield 'hub' => ['/en/hub'];
        yield 'puzzle page' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_500_02];
        yield 'ladder' => ['/en/ladder/solo/500-pieces'];
    }

    private function assertEveryProfileLinkIsATrigger(Crawler $lists): void
    {
        $links = $lists->filter('a[href*="/player-profile/"]');
        self::assertGreaterThan(0, $links->count(), 'No profile link in the list - it proves nothing');

        foreach ($links as $link) {
            self::assertInstanceOf(\DOMElement::class, $link);
            $html = (string) $link->ownerDocument?->saveHTML($link);
            self::assertTrue($link->hasAttribute('data-player-card'), 'Not a card trigger: ' . $html);
            self::assertSame('dialog', $link->getAttribute('aria-haspopup'), $html);
        }
    }

    private function seed(string $seed): void
    {
        $database = self::getContainer()->get(Connection::class);

        match ($seed) {
            'ratings' => array_map(static fn (array $rating): int|string => $database->executeStatement(
                'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 500, :rating, NOW())',
                ['id' => Uuid::uuid7()->toString(), 'player' => $rating[0], 'rating' => $rating[1]],
            ), [[PlayerFixture::PLAYER_REGULAR, 0.9], [PlayerFixture::PLAYER_ADMIN, 0.8]]),
            'xp' => $database->executeStatement('UPDATE player SET xp_total = 500, level = 5 WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]),
            'achievement_points' => $database->executeStatement('UPDATE player SET achievement_points = 40 WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]),
            'karma' => $database->executeStatement('UPDATE player SET karma = 3 WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]),
            'holder' => $database->executeStatement(
                "INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :player, 'zen_puzzler', '2026-09-05 10:00:00', 1, '2026-09-05 10:00:00')",
                ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_WITH_STRIPE],
            ),
            default => self::fail('Unknown seed ' . $seed),
        };
    }
}
