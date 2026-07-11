<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomepageControllerTest extends WebTestCase
{
    public function testAnonymousUserGetsMarketingHomepageAtDomainRoot(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/');

        // The domain root is a real 200 marketing page (not a redirect):
        // required for Google's site-name system and the hreflang x-default anchor.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Speed Puzzling');
        // Live counters must be server-rendered for SEO / no-JS visitors.
        $this->assertSelectorExists('[data-controller="count-up"]');
        $this->assertSelectorExists('[data-count-up-key="pieces"]');
    }

    public function testLoggedInUserIsRedirectedFromDomainRootToHub(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/');

        $this->assertResponseRedirects('/en/hub');

        $browser->followRedirect();

        $this->assertResponseIsSuccessful();
    }

    public function testAnonymousUserCanAccessCzechHomepage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/cs');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'speed puzzling');
    }

    public function testAnonymousUserCanAccessGermanHomepage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/de');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Speed Puzzling');
    }

    public function testLoggedInUserCanAccessLocalizedHomepage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/cs');

        $this->assertResponseIsSuccessful();
    }
}
