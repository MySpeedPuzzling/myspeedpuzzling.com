<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
    }
}
