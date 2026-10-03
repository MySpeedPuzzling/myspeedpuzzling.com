<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Players page and its directory list people from precomputed numbers (docs/features/players-page/README.md), so
 * the blocklist and private-profile canaries need the community stats rebuilt first - this class does that, then
 * pushes the player under test to the top of every list so the page cannot pass by not showing them anyway.
 *
 * - Blocks: the blocked player is everywhere for a bystander and nowhere for the blocker (whole page).
 * - Private profiles: people lists are public-only for everybody - the friend on the allow list included - so nobody is
 *   ranked differently for different viewers. The player card follows the profile: only the friend may open it.
 */
final class PlayersPageCanaryTest extends WebTestCase
{
    private const string BLOCKER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string BLOCKED = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string BLOCKED_NAME = PlayerFixture::PLAYER_WITH_STRIPE_NAME;
    private const string BYSTANDER = PlayerFixture::PLAYER_ADMIN;

    private const string PRIVATE_OWNER = PlayerFixture::PLAYER_PRIVATE;
    private const string PRIVATE_OWNER_NAME = 'Jane Smith';
    // PrivateProfileViewerFixture: PLAYER_PRIVATE allows PLAYER_WITH_FAVORITES only
    private const string FRIEND = PlayerFixture::PLAYER_WITH_FAVORITES;

    /** The sections that list people (the viewer's own favorites and the search are personal and covered elsewhere) */
    private const array PEOPLE_SECTIONS = ['.players-spotlight', '.players-week', '.players-suggestions', '.players-directory'];

    #[DataProvider('provideBlockUrls')]
    public function testTheBlockedPlayerIsNowhereForTheBlocker(string $url): void
    {
        $browser = self::createClient();
        $this->rebuildWithOnTop(self::BLOCKED);
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        self::assertStringContainsString(self::BLOCKED_NAME, $this->get($browser, $url), 'No canary: the page does not show the player to a bystander.');

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $content = $this->get($browser, $url);
        self::assertStringNotContainsString(self::BLOCKED, $content, 'The blocker is shown a player they blocked.');
        self::assertStringNotContainsString(self::BLOCKED_NAME, $content, 'The blocker is shown a player they blocked.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBlockUrls(): iterable
    {
        yield 'players page, world' => ['/en/puzzlers'];
        yield 'players page, her country' => ['/en/puzzlers?scope=gb'];
        yield 'directory, world' => ['/en/puzzlers/all'];
        yield 'directory, most followed' => ['/en/puzzlers/all?scope=gb&sort=followed'];
        yield 'country page' => ['/en/players-from-country/gb'];
    }

    public function testThePlayerCardOfABlockedPlayerDoesNotExistForTheBlocker(): void
    {
        $browser = self::createClient();
        $this->rebuildWithOnTop(self::BLOCKED);
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );

        TestingLogin::asPlayer($browser, self::BYSTANDER);
        self::assertStringContainsString(self::BLOCKED_NAME, $this->get($browser, '/en/puzzler-card/' . self::BLOCKED));

        TestingLogin::asPlayer($browser, self::BLOCKER);
        $browser->request('GET', '/en/puzzler-card/' . self::BLOCKED);
        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('providePrivateUrls')]
    public function testThePrivatePlayerIsInNoPeopleListForAnybody(string $url): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        // Proof the lists would show her: public, she tops them
        $database->executeStatement('UPDATE player SET is_private = false WHERE id = :id', ['id' => self::PRIVATE_OWNER]);
        $this->rebuildWithOnTop(self::PRIVATE_OWNER);
        self::assertStringContainsString(self::PRIVATE_OWNER_NAME, $this->peopleSections($browser, $url), 'No canary: the lists do not show her even while public.');

        $database->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => self::PRIVATE_OWNER]);
        $this->rebuildWithOnTop(self::PRIVATE_OWNER);

        self::assertStringNotContainsString(self::PRIVATE_OWNER_NAME, $this->peopleSections($browser, $url), 'A guest is shown a private player.');

        foreach ([PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN, self::FRIEND] as $viewerId) {
            TestingLogin::asPlayer($browser, $viewerId);
            $sections = $this->peopleSections($browser, $url);
            self::assertStringNotContainsString(self::PRIVATE_OWNER_NAME, $sections, "A private player is listed for {$viewerId}.");
            self::assertStringNotContainsString(self::PRIVATE_OWNER, $sections, "A private player is listed for {$viewerId}.");
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePrivateUrls(): iterable
    {
        yield 'players page, world' => ['/en/puzzlers'];
        yield 'players page, her country' => ['/en/puzzlers?scope=us'];
        yield 'directory, world' => ['/en/puzzlers/all'];
        yield 'directory, most followed' => ['/en/puzzlers/all?scope=us&sort=followed'];
        yield 'country page' => ['/en/players-from-country/us'];
    }

    public function testOnlyTheFriendOpensTheCardOfAPrivatePlayer(): void
    {
        $browser = self::createClient();
        $this->rebuildWithOnTop(self::PRIVATE_OWNER);
        $url = '/en/puzzler-card/' . self::PRIVATE_OWNER;

        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        foreach ([PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN] as $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            $browser->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringContainsString(self::PRIVATE_OWNER_NAME, $this->get($browser, $url));
    }

    /**
     * Rebuilds the community stats and puts the player first in every people list - most active this month, most
     * followed, on a roll, newest - so a page that "passes" cannot do so by just not showing them.
     */
    private function rebuildWithOnTop(string $playerId): void
    {
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());

        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            'UPDATE community_player_stats
             SET solves7d = 99, pieces7d = 99000, solves_this_month = 99, pieces_this_month = 99000,
                 solves30d = 99, favorites_count = 999, solved_total = GREATEST(solved_total, 1), last_solved_at = NOW()
             WHERE player_id = :id',
            ['id' => $playerId],
        );
        $database->executeStatement('UPDATE player SET registered_at = NOW() WHERE id = :id', ['id' => $playerId]);
    }

    private function get(KernelBrowser $browser, string $url): string
    {
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return (string) $browser->getResponse()->getContent();
    }

    private function peopleSections(KernelBrowser $browser, string $url): string
    {
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        $html = '';

        foreach (self::PEOPLE_SECTIONS as $selector) {
            foreach ($crawler->filter($selector) as $node) {
                $html .= $node->ownerDocument?->saveHTML($node) ?? '';
            }
        }

        return $html;
    }
}
