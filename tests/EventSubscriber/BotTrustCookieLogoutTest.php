<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use SpeedPuzzling\Web\Services\BotTrustCookieSigner;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Signing out ends the browser's bot-blocker trust: the logout response of the `main` firewall expires __bb_trust.
 */
final class BotTrustCookieLogoutTest extends WebTestCase
{
    public function testLogoutResponseExpiresTheTrustCookie(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->getCookieJar()->set(new BrowserCookie(BotTrustCookieSigner::COOKIE_NAME, 'some-trust-value'));

        $browser->request('GET', '/logout');

        self::assertResponseRedirects('/');

        $cleared = array_values(array_filter(
            $browser->getResponse()->headers->getCookies(),
            static fn (Cookie $cookie): bool => $cookie->getName() === BotTrustCookieSigner::COOKIE_NAME,
        ));
        self::assertCount(1, $cleared);
        self::assertTrue($cleared[0]->isCleared());
        self::assertSame('/', $cleared[0]->getPath());
        self::assertTrue($cleared[0]->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cleared[0]->getSameSite());
    }
}
