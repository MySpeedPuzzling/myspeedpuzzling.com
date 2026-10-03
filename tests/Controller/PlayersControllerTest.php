<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlayersControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzlers');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzlers');

        $this->assertResponseIsSuccessful();
    }

    public function testThePageOpensOnTheWorld(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.players-scope-option.is-active', 'World');
        self::assertSelectorExists('meta[name="robots"][content="index, follow"]');
    }

    public function testAScopeOpensThatCountryAsANoindexVariant(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzlers?scope=cz');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.players-scope-option.is-active', 'Czechia');
        self::assertSelectorExists('meta[name="robots"][content="noindex, follow"]');
    }

    public function testAnUnknownScopeIsTheWorld(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzlers?scope=xx');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.players-scope-option.is-active', 'World');
    }

    public function testASignedInPlayerGetsTheirCountryOneTapAway(): void
    {
        $browser = self::createClient();
        // John Doe lives in Czechia
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.players-scope-switch a[href="/en/puzzlers?scope=cz"]');
    }
}
