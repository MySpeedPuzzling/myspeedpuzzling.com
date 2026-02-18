<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Messaging;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BlockedUsersControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/blocked-users');

        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/blocked-users');

        $this->assertResponseIsSuccessful();
    }

    public function testShowsBlockedUsers(): void
    {
        $browser = self::createClient();

        // PLAYER_REGULAR has blocked PLAYER_PRIVATE (from UserBlockFixture)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/blocked-users');

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('.list-group-item')->count());
    }

    public function testEmptyStateWhenNoBlocks(): void
    {
        $browser = self::createClient();

        // PLAYER_ADMIN has not blocked anyone
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/blocked-users');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('.list-group-item'));
    }
}
