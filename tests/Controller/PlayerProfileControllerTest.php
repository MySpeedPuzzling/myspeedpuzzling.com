<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlayerProfileControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
    }

    public function testUnknownPlayerReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-profile/' . Uuid::uuid7()->toString());

        $this->assertResponseStatusCodeSame(404);
    }
}
