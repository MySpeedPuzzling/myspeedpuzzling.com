<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The XP explainer is public since the launch (no feature flag).
 */
final class XpExplainerControllerTest extends WebTestCase
{
    public function testAnonymousVisitorSeesTheExplainer(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/how-xp-works');

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('How XP &amp; Levels work', $content);
        self::assertStringNotContainsString('Achievements give XP', $content, 'Achievements grant Achievement Points only');
    }

    public function testSignedInPlayerSeesTheExplainer(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/how-xp-works');

        self::assertResponseIsSuccessful();
    }
}
