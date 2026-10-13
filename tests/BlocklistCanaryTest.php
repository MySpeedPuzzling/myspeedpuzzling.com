<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The read side has no chokepoint - every query is hand-written SQL - so nothing but a test can
 * notice a page that forgot the blocklist (docs/features/player-blocklist.md).
 *
 * For each page that lists players: another signed-in viewer must see the blocked player (or the
 * page proves nothing), and the blocker must not. **Add every new player-listing page here.**
 */
final class BlocklistCanaryTest extends WebTestCase
{
    use ClonesSolvingTimes;
    use ComparisonSeeding;

    private const string BLOCKER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string BLOCKED = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string BLOCKED_NAME = PlayerFixture::PLAYER_WITH_STRIPE_NAME;
    private const string BYSTANDER = PlayerFixture::PLAYER_ADMIN;

    #[DataProvider('providePlayerListingPages')]
    public function testBlockedPlayerIsNowhereOnThePage(string $url): void
    {
        $browser = self::createClient();

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertTrue(
            str_contains($content, self::BLOCKED) || str_contains($content, self::BLOCKED_NAME),
            'The page does not show the player to anyone - it is no canary. Pick a page/fixture that does.',
        );

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
    }

    /**
     * A page about one player's results (docs/features/puzzle-result-detail.md) does not mask a blocked player -
     * for the blocker it does not exist.
     */
    public function testResultDetailOfABlockedPlayerDoesNotExistForTheBlocker(): void
    {
        $browser = self::createClient();
        // PLAYER_WITH_STRIPE's time on PUZZLE_500_02
        $url = '/en/result/' . PuzzleSolvingTimeFixture::TIME_45_UNBOXED;

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME, (string) $browser->getResponse()->getContent());

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A player's results (navigation PROF-00, `player_results`) list one player's results - for the blocker that player
     * does not exist, like their profile.
     */
    public function testResultsPageOfABlockedPlayerDoesNotExistForTheBlocker(): void
    {
        $browser = self::createClient();
        $url = '/en/player-results/' . self::BLOCKED;

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME, (string) $browser->getResponse()->getContent());

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A co-solver's Overview (docs/features/navigation PROF-03): the pair with the blocked player is in the latest results
     * and counted in the numbers for a bystander - for the blocker neither (GetPlayerOverviewNumbers and the feed both
     * leave it out)
     */
    public function testBlockedPlayerIsNowhereOnACoSolversOverview(): void
    {
        $browser = self::createClient();
        $url = '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR;
        $pair = $this->seedTeam([PlayerFixture::PLAYER_REGULAR, self::BLOCKED]);
        $this->seedTime(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02, 1500, new DateTimeImmutable('-1 minute'), teamId: $pair);

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME, $crawler->filter('[data-testid="latest-results"]')->text(), 'No canary: the pair is not among the latest results for a bystander.');
        $pairs = (int) $crawler->filter('[data-overview-number="duo"] .pov-number-value')->text();

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
        self::assertSame($pairs - 1, (int) $crawler->filter('[data-overview-number="duo"] .pov-number-value')->text(), 'The pair is not counted for the blocker');
    }

    /**
     * A co-solver's Results (docs/features/navigation PROF-08): their pair and their team of three with the blocked player
     * are rows of the Pairs / Teams tabs, options of the pair/team filter and counted on the tabs for a bystander - for
     * the blocker none of it, whatever the view, the search or the answer (the page, the list alone for its frame, or
     * Show more's stream - `fragment=more`, page 1's rows: PROF review F6).
     * GetPlayerSolvedPuzzles leaves the results out with HiddenPlayers::sqlExcludeTeam().
     */
    #[DataProvider('provideCoSolversResults')]
    public function testBlockedPlayerIsNowhereOnACoSolversResults(string $query, string $category): void
    {
        $browser = self::createClient();
        $url = '/en/player-results/' . PlayerFixture::PLAYER_REGULAR . $query;
        $pair = $this->seedTeam([PlayerFixture::PLAYER_REGULAR, self::BLOCKED]);
        $pairTime = $this->seedTime(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02, 1500, new DateTimeImmutable('-1 minute'), teamId: $pair);
        $team = $this->seedTeam([PlayerFixture::PLAYER_REGULAR, self::BLOCKED], ['Garden Guest']);
        $teamTime = $this->seedTime(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02, 1900, new DateTimeImmutable('-2 minutes'), teamId: $team);
        // The seeded result of the tab: its row links its result detail (the row itself, not only the names on it)
        $seededTime = $category === 'duo' ? $pairTime : $teamTime;

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME, $crawler->filter('#player-results-rows')->text(), 'No canary: the bystander is not shown the result with the player.');
        self::assertStringContainsString(self::BLOCKED_NAME, $crawler->filter('select[name="team"]')->text(), 'No canary: the pair/team filter does not offer them to a bystander.');
        $shown = $this->tabCount($crawler, $category);

        // Show more's stream appends the rows of the page asked for after the ones shown - page 1: every row there is
        $stream = $url . '&fragment=more';
        $browser->request('GET', $stream);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $browser->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString(self::BLOCKED_NAME, (string) $browser->getResponse()->getContent(), 'No canary: the stream does not show the bystander the result with the player.');
        self::assertStringContainsString($seededTime, (string) $browser->getResponse()->getContent(), 'No canary: the stream does not list the result with the player for the bystander.');

        TestingLogin::asPlayer($browser, self::BLOCKER);

        foreach (['page' => [], 'frame' => ['HTTP_TURBO_FRAME' => 'player-results']] as $answer => $server) {
            $crawler = $browser->request('GET', $url, server: $server);
            self::assertResponseIsSuccessful();
            $content = (string) $browser->getResponse()->getContent();
            self::assertStringNotContainsString(self::BLOCKED, $content, "The blocker is shown a player they blocked ({$answer}).");
            self::assertStringNotContainsString(self::BLOCKED_NAME, $content, "The blocker is shown a player they blocked ({$answer}).");
            self::assertSame($shown - 1, $this->tabCount($crawler, $category), "The result is counted for the blocker ({$answer}).");
        }

        $browser->request('GET', $stream);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked (stream).');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked (stream).');
        self::assertStringNotContainsString($seededTime, $content, 'The blocker is shown the result with a player they blocked (stream).');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideCoSolversResults(): iterable
    {
        yield 'pairs tab' => ['?category=duo', 'duo'];
        yield 'teams tab' => ['?category=group', 'group'];
        yield 'pairs, every result' => ['?category=duo&view=every', 'duo'];
        // The search (PROF-05d) by the puzzle's EAN
        yield 'pairs, searched' => ['?category=duo&q=4005556123456', 'duo'];
    }

    /** "Pairs (3)" → 3 */
    private function tabCount(Crawler $crawler, string $category): int
    {
        $tab = $crawler->filter('[data-results-category="' . $category . '"]');
        self::assertCount(1, $tab, $category);
        self::assertSame(1, preg_match('/\((\d+)\)\s*$/', trim($tab->text()), $matches), $tab->text());

        return (int) $matches[1];
    }

    /**
     * The player card (navigation PROF-08, the card endpoint as the Players page uses it today; CARD-05 adds context
     * blocks): a bystander opens the blocked player's card, the blocker gets the card's neutral 404 - in the frame too,
     * where it names nobody (blocked, private and deleted read the same).
     */
    public function testTheCardOfABlockedPlayerDoesNotExistForTheBlocker(): void
    {
        $browser = self::createClient();
        $url = '/en/puzzler-card/' . self::BLOCKED;
        $frame = ['HTTP_TURBO_FRAME' => 'player-card'];

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $browser->request('GET', $url, server: $frame);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME, (string) $browser->getResponse()->getContent(), 'No canary: the card does not name the player to a bystander.');

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', $url, server: $frame);
        self::assertResponseStatusCodeSame(404);
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('players-card-unavailable', $content);
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
    }

    /**
     * The favorite puzzlers page is the owner's alone, so there is no bystander to compare with: the blocker sees the
     * follower until the block, and never after it.
     */
    public function testFollowerTheOwnerBlocksIsNowhereOnTheFavoritesPage(): void
    {
        $browser = self::createClient();
        $url = '/en/player-favorites/' . self::BLOCKER;
        $database = self::getContainer()->get(Connection::class);

        $database->executeStatement(
            'UPDATE player SET favorite_players = :favorites WHERE id = :id',
            ['favorites' => json_encode([self::BLOCKER]), 'id' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME, (string) $browser->getResponse()->getContent(), 'No canary: the follower is not shown before the block.');

        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
    }

    /**
     * The compare page (docs/features/player-comparison.md) checks visibility on every read: a shared comparison never
     * shows the blocker the player they blocked, and one who was in their line-up before the block turns into a neutral
     * "No longer available" chip - no name, no id.
     */
    public function testComparePageNeverShowsTheBlockerAPlayerTheyBlocked(): void
    {
        $browser = self::createClient();
        $url = '/en/compare?kind=solo&with=p-' . self::BLOCKER . ',p-' . self::BLOCKED;
        $database = self::getContainer()->get(Connection::class);

        foreach ([self::BLOCKER, self::BLOCKED] as $subjectId) {
            $database->executeStatement(
                'INSERT INTO comparison_subject (id, player_id, subject_player_id, added_at) VALUES (:id, :owner, :subject, NOW())',
                ['id' => Uuid::uuid7()->toString(), 'owner' => self::BLOCKER, 'subject' => $subjectId],
            );
        }

        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME, (string) $browser->getResponse()->getContent(), 'No canary: the shared comparison does not show the player to anyone.');

        TestingLogin::asPlayer($browser, self::BLOCKER);

        foreach ([$url, '/en/compare?kind=solo'] as $blockerUrl) {
            $crawler = $browser->request('GET', $blockerUrl);
            self::assertResponseIsSuccessful();
            // The layout echoes the URL the blocker typed (language links, feedback) - the page itself must not know them
            $content = $crawler->filter('[data-testid="comparison"]')->outerHtml();
            self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
            self::assertStringNotContainsString(self::BLOCKED_NAME, (string) $browser->getResponse()->getContent(), 'The blocker is shown a player they blocked.');
        }

        self::assertSelectorExists('[data-testid="comparison-chip-unavailable"]');
    }

    /**
     * A round's published official results (docs/features/competitions-management/official-results.md): the row of a
     * player the viewer blocked is not there - by the organiser's name neither - and nobody behind it moves up a place.
     */
    public function testBlockedPlayerIsNowhereInPublishedOfficialResults(): void
    {
        $browser = self::createClient();
        $url = '/en/events/results-cup/results/group-a';
        $database = self::getContainer()->get(Connection::class);

        // "Ben Steady", tied second in Group A, is the blocked player
        $database->executeStatement(
            'UPDATE competition_participant SET player_id = :playerId, connected_at = NOW() WHERE id = :id',
            ['playerId' => self::BLOCKED, 'id' => OfficialResultsFixture::PARTICIPANT_BEN],
        );
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString(self::BLOCKED, $content, 'No canary: the official results do not link the player.');
        self::assertStringContainsString('Ben Steady', $content);

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString('Ben Steady', $content, 'The blocker is shown a player they blocked.');
        self::assertSame(['1', '2', '4'], $crawler->filter('[data-official-results] tbody tr')->each(
            static fn (Crawler $row): string => (string) $row->attr('data-official-rank'),
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePlayerListingPages(): iterable
    {
        yield 'hub' => ['/en/hub'];
        yield 'puzzle detail' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_500_02];
        yield 'ladder' => ['/en/ladder'];
        yield 'ladder solo 500' => ['/en/ladder/solo/500-pieces'];
        yield 'recent activity' => ['/en/recent-activity'];
        yield 'puzzlers search' => ['/en/puzzlers?search=Sarah'];
        yield 'player search' => ['/en/player-search-autocomplete/?query=Sarah'];
        yield 'marketplace' => ['/en/marketplace'];
        yield 'feature requests' => ['/en/feature-requests'];
        // The marketplace card's seller faces (docs/features/marketplace/11-events.md) and the participants below it
        yield 'event page with the marketplace card' => ['/en/events/' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR_SLUG];
    }

    /**
     * The Community landing (docs/features/navigation COM-01) previews the top of the solo 500-piece leaderboard - the
     * blocked player is not there in the fixtures, so a one-minute solo time of theirs puts them first: a bystander sees
     * them, the blocker nowhere on the page (and nobody moves up in their place but the next leader).
     */
    public function testBlockedLeaderIsNowhereOnTheCommunityLanding(): void
    {
        $browser = self::createClient();
        // PLAYER_WITH_STRIPE's solo time on PUZZLE_500_02, as the fastest 500-piece time of all
        $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_45_UNBOXED, ['seconds_to_solve' => 60]);

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $crawler = $browser->request('GET', '/en/community');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME, $crawler->filter('.community-leaderboards .community-preview-row')->first()->text(), 'No canary: the blocked player does not lead the preview for a bystander.');

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $crawler = $browser->request('GET', '/en/community');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
        self::assertCount(3, $crawler->filter('.community-leaderboards .community-preview-row'), 'The next leader takes the place');
    }

    /**
     * The Inbox tabs (docs/features/navigation INB-01) are the viewer's own, so there is no bystander to compare with:
     * the blocker sees the player there until the block, and never after it. For you: the player edited a shared result
     * of theirs (the editor is named - a row INB-03 keeps); Messages: their marketplace conversations
     * (ConversationFixture).
     */
    #[DataProvider('provideInboxTabs')]
    public function testBlockedPlayerIsNowhereOnTheBlockersInboxTab(string $url): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        $database->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, target_solving_time_id, actor_player_id) VALUES (:id, :player, 'GroupSolvingTimeEdited', NOW(), :time, :actor)",
            ['id' => Uuid::uuid7()->toString(), 'player' => self::BLOCKER, 'time' => PuzzleSolvingTimeFixture::TIME_03, 'actor' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME, (string) $browser->getResponse()->getContent(), 'No canary: the tab does not show the player before the block.');

        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInboxTabs(): iterable
    {
        yield 'inbox for you' => ['/en/notifications'];
        yield 'inbox messages' => ['/en/messages'];
    }

    /**
     * The Hub's Favorites feed renders with the page for a player who follows somebody, with "N new" on its tab
     * (docs/features/navigation INB-04b). It is the viewer's own: the blocker follows the player, sees their times and
     * counts them as new until the block - never after it, neither the rows nor in the count.
     */
    public function testBlockedFavoriteIsNowhereOnTheBlockersHub(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        $database->executeStatement(
            "UPDATE player SET favorite_players = :favorites, favorites_seen_at = '2000-01-01 00:00:00' WHERE id = :id",
            ['favorites' => json_encode([self::BLOCKED]), 'id' => self::BLOCKER],
        );

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME, $crawler->filter('#recent-favorites-activity')->html(), 'No canary: the Favorites feed does not show the player before the block.');
        self::assertCount(1, $crawler->filter('.hub-feed-new'), 'No canary: their times are not counted as new before the block.');

        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
        self::assertCount(0, $crawler->filter('.hub-feed-new, .ra-new'), 'The times of a blocked player are counted as new.');
    }

    /**
     * The Menu (docs/features/navigation MENU-01) and its lazy numbers (menu_counts) are the viewer's own and name nobody
     * else. The blocker's "Events you organize" counts an organization the blocked player created (OrganizationFixture:
     * Riverbend, the blocker on its team) - only ever as a number.
     */
    public function testTheMenuAndItsNumbersNameNobody(): void
    {
        $browser = self::createClient();

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', '/en/menu-counts.json');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(['organised', 'review'], array_keys($data));
        self::assertIsInt($data['organised']);
        self::assertGreaterThan(0, $data['organised'], 'No canary: the blocker organises nothing');
        self::assertIsInt($data['review']);
        self::assertStringNotContainsString(self::BLOCKED, $content);
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content);

        $crawler = $browser->request('GET', '/en/menu');
        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('main #site-menu')->html();
        self::assertStringNotContainsString(self::BLOCKED, $menu, 'The Menu names a player the viewer blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $menu, 'The Menu names a player the viewer blocked.');
    }
}
