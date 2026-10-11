<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The digest and experience-system preferences are in every player's settings since the launch (no feature flag).
 */
final class DigestSettingsVisibilityTest extends WebTestCase
{
    public function testEveryPlayerSeesTheDigestAndXpPreferences(): void
    {
        foreach ([PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_REGULAR] as $playerId) {
            $browser = self::createClient();
            TestingLogin::asPlayer($browser, $playerId);

            $browser->request('GET', '/en/edit-profile');

            self::assertResponseIsSuccessful();
            $content = (string) $browser->getResponse()->getContent();
            self::assertStringContainsString('experienceSystemOptedOut', $content);
            self::ensureKernelShutdown();
        }
    }
}
