<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Marketplace;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MarketplaceControllerTest extends WebTestCase
{
    public function testPageLoadsForAnonymousUser(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/marketplace');

        $this->assertResponseIsSuccessful();
    }

    public function testPageLoadsForAuthenticatedUser(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/marketplace');

        $this->assertResponseIsSuccessful();
    }

    public function testPageContainsMarketplaceContent(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/marketplace');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Marketplace');
    }
}
