<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P5 pages: all 404 for non-admins while the xp-system flag is active; admins see
 * the real pages. Delete the flag-related assertions on launch day.
 */
final class XpPagesTest extends WebTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function provideGatedPages(): array
    {
        return [
            'leaderboard' => ['/en/players/xp-leaderboard'],
            'leaderboard all-time tab' => ['/en/players/xp-leaderboard?tab=all-time'],
            'leaderboard AP tab' => ['/en/players/xp-leaderboard?tab=achievement-points'],
            'achievement detail' => ['/en/achievements/puzzles_solved'],
            'achievements catalog' => ['/en/achievements'],
            'explainer' => ['/en/how-xp-works'],
            'fair play' => ['/en/fair-play-xp'],
            'share card' => ['/xp-card/' . PlayerFixture::PLAYER_ADMIN . '/launch'],
        ];
    }

    #[DataProvider('provideGatedPages')]
    public function testPageIs404ForNonAdmins(string $url): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('provideGatedPages')]
    public function testPageRendersForAdmin(string $url): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    public function testXpHistoryIs404ForNonAdmins(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/my/xp-history');

        self::assertResponseStatusCodeSame(404);
    }

    public function testXpHistoryRendersForAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/my/xp-history');

        self::assertResponseIsSuccessful();
    }

    public function testLaunchRevealIs404ForNonAdmins(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/my/xp-reveal');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLaunchRevealRendersForAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/my/xp-reveal');

        self::assertResponseIsSuccessful();
    }

    public function testShareCardReturnsPngForAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/xp-card/' . PlayerFixture::PLAYER_ADMIN . '/level-up');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
    }

    public function testInvalidAchievementTypeIs404(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/achievements/not_a_type');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLegacyBadgesUrlRedirectsPermanently(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/badges');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/en/achievements');
    }

    public function testAnonymousGets404OnGatedPages(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/players/xp-leaderboard');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/xp-card/' . PlayerFixture::PLAYER_ADMIN . '/launch');
        self::assertResponseStatusCodeSame(404);
    }
}
