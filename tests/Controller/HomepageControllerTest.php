<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomepageControllerTest extends WebTestCase
{
    public function testAnonymousUserGetsCrossroadsPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/');

        // The domain root is a real 200 language-crossroads page (not a redirect):
        // required for Google's site-name system and the hreflang x-default anchor.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'MySpeedPuzzling');
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/');
        $browser->followRedirect();

        $this->assertResponseIsSuccessful();
    }

    public function testAnonymousUserCanAccessEnglishHomepage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/home');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessEnglishHomepage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/home');

        $this->assertResponseIsSuccessful();
    }
}
