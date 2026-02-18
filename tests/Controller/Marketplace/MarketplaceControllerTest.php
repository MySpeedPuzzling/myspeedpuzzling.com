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

    public function testDisclaimerIsVisibleForAnonymousUser(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/marketplace');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert-warning');
    }

    public function testDisclaimerIsVisibleForUserWhoHasNotDismissed(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/marketplace');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert-warning');
    }

    public function testDisclaimerIsHiddenForUserWhoHasDismissed(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('GET', '/en/marketplace');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.alert-warning');
    }

    public function testDisclaimerIsHiddenAfterDismissing(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Disclaimer is visible before dismissing
        $browser->request('GET', '/en/marketplace');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert-warning');

        // Dismiss the hint
        $browser->request('POST', '/en/dismiss-hint', ['type' => 'marketplace_disclaimer']);
        $this->assertResponseStatusCodeSame(204);

        // Disclaimer is hidden after reload
        $browser->request('GET', '/en/marketplace');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.alert-warning');
    }
}
