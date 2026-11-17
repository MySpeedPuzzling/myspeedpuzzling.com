<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\DeclaredAdvancementStep;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\PublishedStanding;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The read side has no chokepoint - every query is hand-written SQL - so nothing but a test can
 * notice a page that forgot the blocklist (docs/features/player-blocklist.md).
 *
 * For each page that lists players: another signed-in viewer must see the blocked player (or the
 * page proves nothing), and the blocker must not. **Add every new player-listing page here.**
 */
final class BlocklistCanaryTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use UnfinishedResultSeeding;

    private const string BLOCKER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string BLOCKER_NAME = PlayerFixture::PLAYER_WITH_FAVORITES_NAME;
    private const string BLOCKED = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string BLOCKED_NAME = PlayerFixture::PLAYER_WITH_STRIPE_NAME;
    private const string BYSTANDER = PlayerFixture::PLAYER_ADMIN;

    #[DataProvider('providePlayerListingPages')]
    public function testBlockedPlayerIsNowhereOnThePage(string $url): void
    {
        $browser = self::createClient();

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
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
     * Unfinished results are bottom rows of the puzzle page (docs/features/competitions-management/unfinished-results-plan.md):
     * the blocked player's own and a team they are in, on a puzzle they have no time of - the bystander sees both, the
     * blocker neither.
     */
    public function testBlockedPlayersUnfinishedResultsAreNowhereOnThePuzzlePage(): void
    {
        $browser = self::createClient();
        $puzzle = PuzzleFixture::PUZZLE_500_05;
        $url = '/en/puzzle/' . $puzzle;

        $this->seedUnfinishedResult(self::BLOCKED, $puzzle, 321);
        $this->seedUnfinishedResult(self::BYSTANDER, $puzzle, 432, puzzlers: [self::BYSTANDER, self::BLOCKED, 'Rita Guest']);
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString(self::BLOCKED_NAME, $content, 'No canary: the bystander does not see the unfinished result.');
        self::assertStringContainsString('321 / 500', $content);

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString('321 / 500', $content);

        // The team tab: the bystander's team with the blocked player
        foreach ([self::BYSTANDER => true, self::BLOCKER => false] as $viewer => $sees) {
            TestingLogin::asPlayer($browser, $viewer);
            $teams = $this->createLiveComponent('PuzzleTimes', ['puzzleId' => $puzzle, 'piecesCount' => 500, 'category' => 'group'], $browser);
            $teams->setRouteLocale('en');
            $html = $teams->render()->toString();
            self::assertSame($sees, str_contains($html, self::BLOCKED_NAME));
            self::assertSame($sees, str_contains($html, '432 / 500'));
        }
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
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
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
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
    }

    /**
     * The wishlist's "… has it" line and the ask-to-borrow candidates (docs/features/wishlist-availability.md) are only
     * on the list owner's own page, so the canary is the owner before and after blocking a favorite who owns a puzzle.
     */
    public function testFavoriteTheOwnerBlocksIsNoLenderOnTheirWishlist(): void
    {
        $browser = self::createClient();
        $url = '/en/wish-list/' . self::BLOCKER;
        $database = self::getContainer()->get(Connection::class);
        $now = TestClock::SQL_NOW;

        $database->executeStatement(
            "UPDATE player SET favorite_players = (favorite_players::jsonb || jsonb_build_array(:blocked::text))::json WHERE id = :blocker",
            ['blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );
        $database->executeStatement("UPDATE player SET puzzle_collection_visibility = 'public' WHERE id = :id", ['id' => self::BLOCKED]);
        $database->executeStatement(
            "INSERT INTO collection_item (id, added_at, collection_id, player_id, puzzle_id) VALUES (:id, {$now}, NULL, :owner, :puzzle)",
            ['id' => Uuid::uuid7()->toString(), 'owner' => self::BLOCKED, 'puzzle' => PuzzleFixture::PUZZLE_9000],
        );
        $database->executeStatement(
            "INSERT INTO wish_list_item (id, added_at, player_id, puzzle_id) VALUES (:id, {$now}, :player, :puzzle) ON CONFLICT DO NOTHING",
            ['id' => Uuid::uuid7()->toString(), 'player' => self::BLOCKER, 'puzzle' => PuzzleFixture::PUZZLE_9000],
        );

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME . ' has it', (string) $browser->getResponse()->getContent(), 'No canary: the favorite is not shown before the block.');

        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, {$now}, 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::BLOCKED_NAME, (string) $browser->getResponse()->getContent(), 'The blocker is offered a player they blocked to borrow from.');
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
                'INSERT INTO comparison_subject (id, player_id, subject_player_id, added_at) VALUES (:id, :owner, :subject, ' . TestClock::SQL_NOW . ')',
                ['id' => Uuid::uuid7()->toString(), 'owner' => self::BLOCKER, 'subject' => $subjectId],
            );
        }

        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
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
     * The partner card's faces on an event page (docs/features/partner-finder.md "Part B"): the blocked player's open
     * listing still counts, but they are no face for the blocker
     */
    public function testBlockedPlayerIsNoFaceOnThePartnerCard(): void
    {
        $browser = self::createClient();
        $url = '/en/events/' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG;
        $database = self::getContainer()->get(Connection::class);

        // The blocked player's listing open again; the blocker (going) not listed, so the card shows them faces
        $database->executeStatement(
            'UPDATE partner_listing SET closed_at = NULL, closed_reason = NULL WHERE id = :id',
            ['id' => PartnerFinderFixture::LISTING_STRIPE_CLOSED],
        );
        $database->executeStatement(
            "UPDATE partner_listing SET closed_at = " . TestClock::SQL_NOW . ", closed_reason = 'withdrawn' WHERE id = :id",
            ['id' => PartnerFinderFixture::LISTING_FAVORITES_TEAM],
        );
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME, $crawler->filter('[data-testid="partner-card"]')->html(), 'No canary: no face on the card.');

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="partner-card"]');
        self::assertCount(1, $card);
        self::assertCount(1, $card->filter('.pf-card-face'), 'only the other listed puzzler');
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
    }

    /**
     * The other direction on the event page (docs/features/partner-finder.md D5): the card agrees with the finder page,
     * which hides the listing of whoever blocks the viewer - so the blocked viewer gets neither the blocker's face nor a
     * count one higher than the rows they can see there
     */
    public function testBlockedViewerSeesNeitherFaceNorCountOfTheBlockerOnThePartnerCard(): void
    {
        $browser = self::createClient();
        $eventUrl = '/en/events/' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG;
        $finderUrl = $eventUrl . '/partners';
        $blockersListing = 'partner-listing-row-' . PartnerFinderFixture::LISTING_FAVORITES_TEAM;

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        // Two open listings: PLAYER_REGULAR's pair and the blocker's team
        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $crawler = $browser->request('GET', $eventUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKER_NAME, $crawler->filter('[data-testid="partner-card"]')->html(), 'No canary: the blocker is no face on the card.');
        self::assertSame('2', trim($crawler->filter('.pf-find-count')->text()), 'No canary: the count is not shown.');

        TestingLogin::asPlayer($browser, self::BLOCKED);
        $crawler = $browser->request('GET', $eventUrl);
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="partner-card"]');
        self::assertCount(1, $card);
        self::assertStringNotContainsString(self::BLOCKER_NAME, $card->html(), 'The blocked viewer is shown the face of who blocks them.');
        self::assertCount(1, $card->filter('.pf-card-face'), 'only the other listed puzzler');
        self::assertSame('1', trim($crawler->filter('.pf-find-count')->text()), 'The count tells the blocked viewer about a listing they cannot see.');

        $browser->request('GET', $finderUrl);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString($blockersListing, $content, 'The blocked viewer is shown the listing of who blocks them.');
        self::assertStringContainsString('partner-listing-row-' . PartnerFinderFixture::LISTING_REGULAR_PAIR, $content);
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
            'UPDATE competition_participant SET player_id = :playerId, connected_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['playerId' => self::BLOCKED, 'id' => OfficialResultsFixture::PARTICIPANT_BEN],
        );
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
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
     * A `pick` round's published official results (competition toolkit A5): the blocked player's row with its chosen
     * puzzles is not there either - the chips ride on the row the blocklist drops (OfficialEntrantsView).
     */
    public function testBlockedPlayerIsNowhereInAPickRoundsPublishedOfficialResults(): void
    {
        $browser = self::createClient();
        $url = '/en/events/results-cup/results/group-b';
        $database = self::getContainer()->get(Connection::class);
        $roundPuzzleId = $database->fetchOne('SELECT id FROM competition_round_puzzle WHERE round_id = :round', ['round' => OfficialResultsFixture::ROUND_GROUP_B]);

        // "Hugo Slow", second in Group B - a pick-one round where everybody chose its one puzzle - is the blocked player
        $database->executeStatement(
            "UPDATE competition_round SET puzzle_format = 'pick', puzzles_to_solve = 1, results_published_at = " . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => OfficialResultsFixture::ROUND_GROUP_B],
        );
        $database->executeStatement(
            'UPDATE competition_participant_round SET result_puzzles = :puzzles WHERE round_id = :round',
            ['puzzles' => json_encode([['roundPuzzleId' => $roundPuzzleId, 'done' => true]], JSON_THROW_ON_ERROR), 'round' => OfficialResultsFixture::ROUND_GROUP_B],
        );
        $database->executeStatement(
            'UPDATE competition_participant SET player_id = :playerId, connected_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['playerId' => self::BLOCKED, 'id' => OfficialResultsFixture::PARTICIPANT_HUGO],
        );
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString(self::BLOCKED, $content, 'No canary: the official results do not link the player.');
        self::assertStringContainsString('Hugo Slow', $content);
        self::assertCount(3, $crawler->filter('[data-official-results] [data-official-entry-puzzles]'), 'No canary: the rows show no chosen puzzles.');

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString('Hugo Slow', $content, 'The blocker is shown a player they blocked.');
        self::assertSame(['1', '3'], $crawler->filter('[data-official-results] tbody tr')->each(
            static fn (Crawler $row): string => (string) $row->attr('data-official-rank'),
        ));
        self::assertCount(2, $crawler->filter('[data-official-results] [data-official-entry-puzzles]'));
    }

    /**
     * The public standings (competition toolkit A7): the blocked player's row - solo, or a pair with them - is not there
     * for the blocker, and everybody else keeps the standing's places (ranked over every entrant, hidden rows dropped
     * after that, like the round page). Group B (not published) counts for nobody.
     */
    public function testBlockedPlayerIsNowhereInAPublicStanding(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $cup = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        PublishedStanding::insert($database, $cup, 'overall-solo', 'Overall solo', 'solo', [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B]);
        PublishedStanding::insert($database, $cup, 'overall-pairs', 'Overall pairs', 'duo', [OfficialResultsFixture::ROUND_PAIRS]);
        $database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_PAIRS]);
        // "Ben Steady", tied second in Group A and half of the Puzzle Sharks, is the blocked player
        $database->executeStatement(
            'UPDATE competition_participant SET player_id = :playerId, connected_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['playerId' => self::BLOCKED, 'id' => OfficialResultsFixture::PARTICIPANT_BEN],
        );
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );
        $solo = '/en/events/results-cup/standings/overall-solo';
        $pairs = '/en/events/results-cup/standings/overall-pairs';

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $crawler = $browser->request('GET', $solo);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString(self::BLOCKED, $content, 'No canary: the standing does not link the player.');
        self::assertStringContainsString('Ben Steady', $content);
        self::assertSame(['1', '2', '2', '4'], $crawler->filter('[data-standing-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-standing-rank')));
        $browser->request('GET', $pairs);
        self::assertStringContainsString('Puzzle Sharks', (string) $browser->getResponse()->getContent(), 'No canary: the pairs standing does not list the pair.');

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $crawler = $browser->request('GET', $solo);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString('Ben Steady', $content, 'The blocker is shown a player they blocked.');
        self::assertSame(['1', '2', '4'], $crawler->filter('[data-standing-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-standing-rank')));
        $browser->request('GET', $pairs);
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('Puzzle Sharks', $content, 'The blocker is shown a pair with a player they blocked.');
        self::assertStringNotContainsString('Ben Steady', $content);
    }

    /**
     * The public bracket (competition toolkit A9): the blocked player's qualified row - solo, or a pair with them - is
     * not in a round's box for the blocker; everybody else keeps their official place.
     */
    public function testBlockedPlayerIsNowhereInTheBracket(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $cup = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        DeclaredAdvancementStep::insert($database, $cup, [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B], [OfficialResultsFixture::ROUND_FINAL], qualifyCount: 2);
        DeclaredAdvancementStep::insert($database, $cup, [OfficialResultsFixture::ROUND_PAIRS], [OfficialResultsFixture::ROUND_PAIRS_FINAL]);
        $database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_PAIRS]);
        // "Ben Steady", qualified second in Group A and half of the qualified Puzzle Sharks, is the blocked player
        $database->executeStatement(
            'UPDATE competition_participant SET player_id = :playerId, connected_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['playerId' => self::BLOCKED, 'id' => OfficialResultsFixture::PARTICIPANT_BEN],
        );
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );
        $url = '/en/events/results-cup/bracket';
        $ranks = static fn (Crawler $crawler): array => $crawler->filter('[data-bracket-entry]')->each(static fn (Crawler $entry): string => (string) $entry->attr('data-bracket-entry-rank'));

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString(self::BLOCKED, $content, 'No canary: the bracket does not link the player.');
        self::assertStringContainsString('Ben Steady', $content);
        self::assertStringContainsString('Puzzle Sharks', $content, 'No canary: the bracket does not list the pair.');
        self::assertSame(['1', '2', '1', '2'], $ranks($crawler));

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString('Ben Steady', $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString('Puzzle Sharks', $content, 'The blocker is shown a pair with a player they blocked.');
        // Anna (Group A) and the Edge Hunters keep their places (Puzzle Sharks above them are gone)
        self::assertSame(['1', '2'], $ranks($crawler));
    }

    /**
     * The public competitor pages (competition toolkit B4, docs/features/competitions-management/competitor-pages.md):
     * the page of a player the viewer blocked - their person page, their round entry, a line-up with them - is not
     * there for the blocker; a table page and its state JSON keep the round but show "—" instead of them.
     */
    public function testABlockedPlayersCompetitorPageIsNotThereAndATableMasksThem(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $cup = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        $database->executeStatement('UPDATE competition SET competitor_pages = true WHERE id = :id', ['id' => $cup]);
        // "Ben Steady" (table 2 of Group A, half of the Puzzle Sharks) is the blocked player
        $database->executeStatement(
            'UPDATE competition_participant SET player_id = :playerId, connected_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['playerId' => self::BLOCKED, 'id' => OfficialResultsFixture::PARTICIPANT_BEN],
        );
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );
        $pages = [
            '/en/competitor/' . $cup . '/p/' . OfficialResultsFixture::PARTICIPANT_BEN,
            '/en/competitor/' . $cup . '/e/' . OfficialResultsFixture::ENTRY_A_BEN,
            '/en/competitor/' . $cup . '/g/' . CompetitionLineupId::of($cup, [OfficialResultsFixture::PARTICIPANT_ANNA, OfficialResultsFixture::PARTICIPANT_BEN]),
            '/competitor-state/' . $cup . '/p/' . OfficialResultsFixture::PARTICIPANT_BEN,
        ];
        $tables = ['/en/competitor/' . $cup . '/t/2', '/competitor-state/' . $cup . '/t/2'];

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        foreach ([...$pages, ...$tables] as $url) {
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertStringContainsString('Ben Steady', (string) $browser->getResponse()->getContent(), 'No canary: ' . $url);
        }

        TestingLogin::asPlayer($browser, self::BLOCKER);
        foreach ($pages as $url) {
            $browser->request('GET', $url);
            self::assertResponseStatusCodeSame(404, 'The blocker is shown a player they blocked: ' . $url);
        }
        foreach ($tables as $url) {
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $content = (string) $browser->getResponse()->getContent();
            self::assertStringNotContainsString('Ben Steady', $content, 'The blocker is shown a player they blocked: ' . $url);
            self::assertStringNotContainsString(self::BLOCKED, $content, $url);
            self::assertStringNotContainsString(self::BLOCKED_NAME, $content, $url);
        }
    }

    /**
     * **Documented exemption: competition displays** (timer screens and stream overlays, toolkit
     * D-displays-overlays.md §9.4, R-review-and-plan.md §2.3). A display has no viewer - an anonymous capability URL
     * on a hall screen or a stream, read by the stateless firewall - so a viewer's blocklist cannot apply: the blocker
     * opening the link sees the same rows as everybody, organiser names only, no profile link or id. Pinned here so the
     * exemption is a decision, not an oversight.
     */
    public function testCompetitionDisplaysHaveNoViewerAndSoNoBlocklist(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            'UPDATE competition_participant SET player_id = :playerId, connected_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['playerId' => self::BLOCKED, 'id' => OfficialResultsFixture::PARTICIPANT_BEN],
        );
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );
        $token = 'DispBLock1111111111111';
        $database->insert('competition_display', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'kind' => 'screen',
            'name' => 'Hall',
            'token' => $token,
            'locale' => 'en',
            'design' => '{"schema": 1, "elements": [{"id": "board001", "type": "leaderboard", "hidden": false, "when": {"phases": ["idle"]}, "props": {"rows": 10, "source": "published"}}]}',
            'created_at' => TestClock::FROZEN_NOW,
            'updated_at' => TestClock::FROZEN_NOW,
            'follow_mode' => 'pinned',
            'pinned_round_id' => OfficialResultsFixture::ROUND_GROUP_A,
            'uses_results' => true,
        ], ['uses_results' => 'boolean']);

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', '/display/' . $token . '/state');
        $browser->request('GET', '/display/' . $token . '/state');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('Ben Steady', $content, 'A display shows every row, whoever opened its link.');
        self::assertStringNotContainsString(self::BLOCKED, $content, 'A display never carries a player id.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'A display never carries a profile name.');
    }

    /**
     * The MSP Rating ladder (docs/features/msp-rating-history.md): its rows and "This week on the ladder" - the strip's
     * candidates are cached for everybody and the blocked player is the climber there, so the strip must drop them.
     */
    public function testBlockedPlayerIsNowhereOnTheMspRatingLadder(): void
    {
        $browser = self::createClient();
        self::getContainer()->set(ClockInterface::class, new MockClock(new DateTimeImmutable('2026-06-21 12:00', new DateTimeZone('UTC'))));
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        foreach ([self::BLOCKED => [1.6, 1.2], self::BYSTANDER => [1.5, 1.5], self::BLOCKER => [1.4, 1.4]] as $playerId => [$today, $weekAgo]) {
            $connection->executeStatement(
                'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 500, :rating, ' . TestClock::SQL_NOW . ')',
                ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'rating' => $today],
            );
            $connection->executeStatement(
                'INSERT INTO player_rating_snapshot (id, player_id, pieces_count, snapshot_date, elo_rating, computed_at) VALUES (gen_random_uuid(), :player, 500, :day, :rating, ' . TestClock::SQL_NOW . ')',
                ['player' => $playerId, 'day' => '2026-06-14', 'rating' => $weekAgo],
            );
        }

        $connection->executeStatement(
            "INSERT INTO player_rating_entry (player_id, pieces_count, puzzle_id, portfolio, seconds, solved_at, percentile, difficulty_weight, decay, points, in_top, computed_at)
             VALUES (:player, 500, :puzzle, 'first_attempt', 3000, '2026-06-18', 90.0, 1.0, 1.0, 0.9, true, " . TestClock::SQL_NOW . ")",
            ['player' => self::BLOCKED, 'puzzle' => PuzzleFixture::PUZZLE_500_01],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $crawler = $browser->request('GET', '/en/msp-rating');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BLOCKED_NAME, $crawler->filter('.msp-this-week')->text(), 'The climber tile is no canary.');
        self::assertStringContainsString(self::BLOCKED_NAME, $crawler->filter('.lb-table')->text(), 'The ladder row is no canary.');

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', '/en/msp-rating');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
    }

    /**
     * The partner finder (docs/features/partner-finder.md D5): blocks hide both ways - the blocker does not see the
     * blocked player's listing, and the blocked player does not see the blocker's.
     */
    public function testPartnerFinderHidesListingsBothWays(): void
    {
        $browser = self::createClient();
        $url = '/en/events/' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG . '/partners';
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            "INSERT INTO partner_listing (id, competition_id, player_id, group_size, looking_for, co_member_ids, strengths, custom_strengths, languages, created_at, updated_at)
             VALUES (:id, :competition, :player, 1, '[\"pair\"]', '[]', '[]', '[]', '[\"en\"]', " . TestClock::SQL_NOW . ", " . TestClock::SQL_NOW . ")",
            ['id' => Uuid::uuid7()->toString(), 'competition' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, 'player' => self::BLOCKED],
        );
        $connection->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );
        $blockersListing = 'partner-listing-row-' . PartnerFinderFixture::LISTING_FAVORITES_TEAM;

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString(self::BLOCKED_NAME, $content, 'The page does not show the listing to anyone - it is no canary.');
        self::assertStringContainsString($blockersListing, $content);

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');

        TestingLogin::asPlayer($browser, self::BLOCKED);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($blockersListing, (string) $browser->getResponse()->getContent(), 'The blocked player is shown the listing of who blocks them.');
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
}
