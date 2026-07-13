<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * While the xp-system feature flag is active, every XP surface must be invisible to
 * non-admins: no ring, no receipt, no estimate, no reveal endpoints.
 * The admin assertions prove the surfaces actually exist behind the gate.
 */
final class XpSurfacesTest extends WebTestCase
{
    public function testProfileShowsNoXpTracesToNonAdmins(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('xp-ring', $content);
        self::assertStringNotContainsString('xp-level-chip', $content);
        self::assertStringNotContainsString('xp-teaser', $content);
    }

    public function testProfileShowsRingAndChipToAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('xp-ring', $content);
        self::assertStringContainsString('xp-level-chip', $content);
    }

    public function testRecapShowsNoXpTracesToNonAdminOwner(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_06);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('xp-solve-receipt', $content);
        self::assertStringNotContainsString('xp-receipt-line', $content);
    }

    public function testRecapShowsReceiptRegionToAdminOwner(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // TIME_03 belongs to the admin fixture player.
        $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_03);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('xp-solve-receipt', $content);
    }

    public function testPuzzleDetailShowsNoEstimateToNonAdmins(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('xp-puzzle-estimate', $content);
    }

    public function testPuzzleDetailShowsEstimateToAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('xp-puzzle-estimate', $content);
    }

    public function testRevealEndpointIs404ForNonAdmins(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', '/en/badges/018d0000-0000-0000-0000-00000000dead/reveal');

        self::assertResponseStatusCodeSame(404);
    }

    public function testBadgeRevealsPageIs404ForNonAdmins(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/my/achievement-reveals');

        self::assertResponseStatusCodeSame(404);
    }

    public function testBadgeRevealsPageRendersForAdminMember(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/my/achievement-reveals');

        self::assertResponseIsSuccessful();
    }

    public function testMembershipPageShowsNoRevealInviteToNonAdmins(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/membership');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('xp-reveal-invite', (string) $browser->getResponse()->getContent());
    }

    public function testHeaderShowsNoRingToNonAdmins(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/faq');

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('xp-ring', $content);
    }
}
