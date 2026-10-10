<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The fair-play page is public since the launch (no feature flag).
 */
final class FairPlayXpControllerTest extends WebTestCase
{
    public function testAnonymousVisitorSeesTheFairPlayPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/fair-play-xp');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Fair play &amp; trust', (string) $browser->getResponse()->getContent());
    }

    public function testSignedInPlayerSeesTheFairPlayPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/fair-play-xp');

        self::assertResponseIsSuccessful();
    }
}
