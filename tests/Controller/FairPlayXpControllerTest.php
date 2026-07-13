<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * While the xp-system feature flag is active, the fair-play page must be invisible
 * to everyone except admins. Delete the flag-related tests on launch day.
 */
final class FairPlayXpControllerTest extends WebTestCase
{
    public function testAnonymousVisitorGets404WhileFlagged(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/fair-play-xp');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminGets404WhileFlagged(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/fair-play-xp');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminSeesFairPlayPageWhileFlagged(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/fair-play-xp');

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('Fair play &amp; trust', $content);
    }
}
