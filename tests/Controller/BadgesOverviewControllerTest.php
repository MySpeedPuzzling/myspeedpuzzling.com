<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * While the xp-system feature flag is active, badge surfaces must be invisible
 * to everyone except admins. Delete the flag-related tests on launch day.
 */
final class BadgesOverviewControllerTest extends WebTestCase
{
    public function testAnonymousVisitorGets404WhileFlagged(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/badges');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminMemberGets404WhileFlagged(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/badges');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminSeesCatalogWhileFlagged(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/badges');

        self::assertResponseIsSuccessful();
    }

    public function testProfileBadgesSectionHiddenFromNonAdminsWhileFlagged(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('ci-medal', $content);
        self::assertStringNotContainsString('/en/badges', $content);
    }

    public function testProfileBadgesSectionVisibleToAdminWhileFlagged(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('ci-medal', $content);
        self::assertStringContainsString('/en/badges', $content);
    }
}
