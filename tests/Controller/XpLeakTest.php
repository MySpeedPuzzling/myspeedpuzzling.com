<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P8.T2 — THE leak test: while the xp-system flag is active, every §1.7/§1.9 surface
 * shows ZERO XP/level/achievement traces to anonymous visitors and to a logged
 * non-admin non-member. DELETE THIS TEST ON LAUNCH DAY together with the flag
 * (documented in docs/features/feature_flags.md).
 */
final class XpLeakTest extends WebTestCase
{
    private const array TRACE_MARKERS = [
        'xp-ring',
        'xp-level-chip',
        'xp-solve-receipt',
        'xp-receipt-line',
        'xp-teaser',
        'xp-puzzle-estimate',
        'xp-reveal-invite',
        'xp-levelup-overlay',
        'badge-medallion',
        'ci-medal',
        '/en/achievements',
        '/en/players/xp-leaderboard',
        '/en/how-xp-works',
        '/en/fair-play-xp',
        '/en/my/xp-history',
        'contentDigestFrequency',
        'experienceSystemOptedOut',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function provideGatedRoutes(): array
    {
        return [
            'achievements catalog' => ['/en/achievements'],
            'achievement detail' => ['/en/achievements/puzzles_solved'],
            'xp leaderboard' => ['/en/players/xp-leaderboard'],
            'xp leaderboard all-time' => ['/en/players/xp-leaderboard?tab=all-time'],
            'xp leaderboard AP' => ['/en/players/xp-leaderboard?tab=achievement-points'],
            'explainer' => ['/en/how-xp-works'],
            'fair play' => ['/en/fair-play-xp'],
            'share card launch' => ['/xp-card/' . PlayerFixture::PLAYER_REGULAR . '/launch'],
            'share card level-up' => ['/xp-card/' . PlayerFixture::PLAYER_REGULAR . '/level-up'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideContentPages(): array
    {
        return [
            'own profile' => ['/en/player-profile/' . PlayerFixture::PLAYER_REGULAR],
            'member profile' => ['/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE],
            'own solve recap' => ['/en/time-added/' . PuzzleSolvingTimeFixture::TIME_06],
            'own tracking recap' => ['/en/tracking-added/' . PuzzleSolvingTimeFixture::TIME_46_RELAX_NO_FINISHED_AT],
            'puzzle detail' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_500_01],
            'edit profile settings' => ['/en/edit-profile'],
            'membership page' => ['/en/membership'],
            'faq (header check)' => ['/en/faq'],
            'edit time (delete dialog)' => ['/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_06],
        ];
    }

    #[DataProvider('provideGatedRoutes')]
    public function testGatedRouteIs404ForLoggedNonAdminNonMember(string $url): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('provideGatedRoutes')]
    public function testGatedRouteIs404ForAnonymous(string $url): void
    {
        $browser = self::createClient();

        $browser->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('provideContentPages')]
    public function testPageCarriesNoXpTracesForLoggedNonAdminNonMember(string $url): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        foreach (self::TRACE_MARKERS as $marker) {
            self::assertStringNotContainsString($marker, $content, "Marker \"{$marker}\" leaked on {$url}");
        }
    }

    public function testGatedActionEndpointsRejectNonAdmins(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/badges/018d0000-0000-0000-0000-00000000dead/reveal');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/my/xp-history');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/my/xp-reveal');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/my/achievement-reveals');
        self::assertResponseStatusCodeSame(404);
    }
}
