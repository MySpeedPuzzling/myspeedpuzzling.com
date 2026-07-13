<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * While the xp-system feature flag is active, the XP explainer page must be invisible
 * to everyone except admins. Delete the flag-related tests on launch day.
 */
final class XpExplainerControllerTest extends WebTestCase
{
    public function testAnonymousVisitorGets404WhileFlagged(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/how-xp-works');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminGets404WhileFlagged(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/how-xp-works');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminSeesExplainerWhileFlagged(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/how-xp-works');

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('How XP &amp; Levels work', $content);
    }
}
