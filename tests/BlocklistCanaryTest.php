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
     * The MSP Rating ladder (docs/features/msp-rating-history.md): its rows and "This week on the ladder" - the strip's
     * candidates are cached for everybody and the blocked player is the climber there, so the strip must drop them.
     */
    public function testBlockedPlayerIsNowhereOnTheMspRatingLadder(): void
    {
        $browser = self::createClient();
        self::getContainer()->set(ClockInterface::class, new MockClock(new DateTimeImmutable('2026-06-21 12:00', new DateTimeZone('UTC'))));
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        foreach ([self::BLOCKED => [1.6, 1.2], self::BYSTANDER => [1.5, 1.5], self::BLOCKER => [1.4, 1.4]] as $playerId => [$today, $weekAgo]) {
            $connection->executeStatement(
                'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 500, :rating, NOW())',
                ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'rating' => $today],
            );
            $connection->executeStatement(
                'INSERT INTO player_rating_snapshot (id, player_id, pieces_count, snapshot_date, elo_rating, computed_at) VALUES (gen_random_uuid(), :player, 500, :day, :rating, NOW())',
                ['player' => $playerId, 'day' => '2026-06-14', 'rating' => $weekAgo],
            );
        }

        $connection->executeStatement(
            "INSERT INTO player_rating_entry (player_id, pieces_count, puzzle_id, portfolio, seconds, solved_at, percentile, difficulty_weight, decay, points, in_top, computed_at)
             VALUES (:player, 500, :puzzle, 'first_attempt', 3000, '2026-06-18', 90.0, 1.0, 1.0, 0.9, true, NOW())",
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
