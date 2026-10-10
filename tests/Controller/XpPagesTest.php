<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The XP / achievements pages, public since the launch (no feature flag): every signed-in player and, where the page
 * is public, guests too.
 */
final class XpPagesTest extends WebTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function providePublicPages(): array
    {
        return [
            'leaderboard' => ['/en/players/xp-leaderboard'],
            'leaderboard AP tab' => ['/en/players/xp-leaderboard?tab=achievement-points'],
            'achievement detail' => ['/en/achievements/puzzles_solved'],
            'achievements catalog' => ['/en/achievements'],
            'explainer' => ['/en/how-xp-works'],
            'fair play' => ['/en/fair-play-xp'],
            'share card' => ['/xp-card/' . PlayerFixture::PLAYER_ADMIN . '/launch'],
        ];
    }

    #[DataProvider('providePublicPages')]
    public function testPageRendersForAFreePlayer(string $url): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('providePublicPages')]
    public function testPageRendersForAMember(string $url): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('providePublicPages')]
    public function testPageRendersForAGuest(string $url): void
    {
        $browser = self::createClient();

        $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    /**
     * The explainer modal is a fragment for the shared modal-frame; opened any other way the achievement's page
     * answers instead of a bare <turbo-frame>
     */
    public function testAchievementInfoIsAModalFragmentAndRedirectsOtherwise(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/achievements/puzzles_solved/info', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal-frame');

        $browser->request('GET', '/en/achievements/puzzles_solved/info');
        self::assertResponseRedirects('/en/achievements/puzzles_solved');
    }

    public function testXpHistoryRendersForItsOwner(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/my/xp-history');

        self::assertResponseIsSuccessful();
    }

    public function testLaunchRevealRendersForAFreePlayer(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/my/xp-reveal');

        self::assertResponseIsSuccessful();
    }

    public function testShareCardIsAPng(): void
    {
        $browser = self::createClient();

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
}
