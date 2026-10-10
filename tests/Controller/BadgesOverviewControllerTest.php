<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The achievements catalogue is public since the launch (no feature flag) - guests, free players and members alike.
 */
final class BadgesOverviewControllerTest extends WebTestCase
{
    public function testAnonymousVisitorSeesTheCatalogue(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
    }

    public function testFreePlayerSeesTheCatalogue(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
    }

    public function testMemberSeesTheCatalogue(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
    }
}
