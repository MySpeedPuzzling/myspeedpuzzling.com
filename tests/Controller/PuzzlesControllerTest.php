<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzlesControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPuzzlesPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPuzzlesPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle');

        $this->assertResponseIsSuccessful();
    }

    public function testPuzzlesPageWithSortByParameter(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle?sortBy=newest');

        $this->assertResponseIsSuccessful();
    }
}
