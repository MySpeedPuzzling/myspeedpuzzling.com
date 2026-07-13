<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The digest + experience-system preferences are hidden while the xp-system flag is
 * active — settings must not leak the feature to non-admins.
 */
final class DigestSettingsVisibilityTest extends WebTestCase
{
    public function testNonAdminSeesNoDigestOrXpPreferences(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('contentDigestFrequency', $content);
        self::assertStringNotContainsString('experienceSystemOptedOut', $content);
    }

    public function testAdminSeesDigestAndXpPreferences(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('contentDigestFrequency', $content);
        self::assertStringContainsString('experienceSystemOptedOut', $content);
    }
}
