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
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
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
            "UPDATE partner_listing SET closed_at = NOW(), closed_reason = 'withdrawn' WHERE id = :id",
            ['id' => PartnerFinderFixture::LISTING_FAVORITES_TEAM],
        );
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
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
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
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
             VALUES (:id, :competition, :player, 1, '[\"pair\"]', '[]', '[]', '[]', '[\"en\"]', NOW(), NOW())",
            ['id' => Uuid::uuid7()->toString(), 'competition' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, 'player' => self::BLOCKED],
        );
        $connection->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
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
