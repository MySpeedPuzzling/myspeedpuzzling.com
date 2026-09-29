<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Locale-less auth pages (/login, /register, ...) pick their language from, in
 * order: the switcher's ?_locale=, the msp_locale cookie, a same-origin Referer,
 * Accept-Language.
 */
final class NativeAuthPageLocaleTest extends WebTestCase
{
    public function testQueryLocaleWinsAndIsRemembered(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/login?_locale=cs', server: ['HTTP_ACCEPT_LANGUAGE' => 'de']);

        self::assertResponseIsSuccessful();
        self::assertSame('cs', $this->htmlLang($browser));
        self::assertSelectorTextContains('h1', 'Přihlásit se');

        $cookie = $this->localeCookie($browser);
        self::assertNotNull($cookie);
        self::assertSame('cs', $cookie->getValue());
        self::assertSame('/', $cookie->getPath());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertGreaterThan(time() + 300 * 24 * 3600, $cookie->getExpiresTime());

        $vary = $browser->getResponse()->getVary();
        self::assertContains('Accept-Language', $vary);
        self::assertContains('Cookie', $vary);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        // The next auth page keeps the choice through the cookie
        $browser->request('GET', '/register', server: ['HTTP_ACCEPT_LANGUAGE' => 'de']);

        self::assertResponseIsSuccessful();
        self::assertSame('cs', $this->htmlLang($browser));
        self::assertNull($this->localeCookie($browser));
    }

    public function testCookieAloneSelectsLocale(): void
    {
        $browser = self::createClient();
        $browser->getCookieJar()->set(new BrowserCookie(NativeAuthPageSubscriber::LOCALE_COOKIE, 'cs'));

        $browser->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'de']);

        self::assertSame('cs', $this->htmlLang($browser));
    }

    public function testSameOriginRefererFromLocalizedPageSelectsLocale(): void
    {
        $browser = self::createClient();

        // Czech FAQ lives at /caste-dotazy - no /cs prefix, only the router knows
        $browser->request('GET', '/login', server: [
            'HTTP_ACCEPT_LANGUAGE' => 'de',
            'HTTP_REFERER' => 'http://localhost/caste-dotazy',
        ]);

        self::assertSame('cs', $this->htmlLang($browser));
        self::assertNull($this->localeCookie($browser));

        $browser->request('GET', '/login', server: [
            'HTTP_ACCEPT_LANGUAGE' => 'de',
            'HTTP_REFERER' => 'http://localhost/fr',
        ]);

        self::assertSame('fr', $this->htmlLang($browser));
    }

    public function testCrossOriginOrUnknownRefererIsIgnored(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/login', server: [
            'HTTP_ACCEPT_LANGUAGE' => 'de',
            'HTTP_REFERER' => 'https://evil.example/caste-dotazy',
        ]);

        self::assertSame('de', $this->htmlLang($browser));

        $browser->request('GET', '/login', server: [
            'HTTP_ACCEPT_LANGUAGE' => 'de',
            'HTTP_REFERER' => 'http://localhost/this-page-does-not-exist-at-all',
        ]);

        self::assertSame('de', $this->htmlLang($browser));
    }

    public function testInvalidQueryAndCookieAreIgnored(): void
    {
        $browser = self::createClient();
        $browser->getCookieJar()->set(new BrowserCookie(NativeAuthPageSubscriber::LOCALE_COOKIE, 'xx'));

        $browser->request('GET', '/login?_locale=klingon', server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);

        self::assertResponseIsSuccessful();
        self::assertSame('es', $this->htmlLang($browser));
        self::assertNull($this->localeCookie($browser));
    }

    public function testAcceptLanguageFallbackUnchanged(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'ja,en;q=0.5']);
        self::assertSame('ja', $this->htmlLang($browser));

        $browser->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'pl']);
        self::assertSame('en', $this->htmlLang($browser));
    }

    public function testPrecedenceQueryOverCookieOverReferer(): void
    {
        $browser = self::createClient();
        $browser->getCookieJar()->set(new BrowserCookie(NativeAuthPageSubscriber::LOCALE_COOKIE, 'fr'));

        $referer = ['HTTP_ACCEPT_LANGUAGE' => 'de', 'HTTP_REFERER' => 'http://localhost/caste-dotazy'];

        $browser->request('GET', '/login?_locale=es', server: $referer);
        self::assertSame('es', $this->htmlLang($browser));

        // The query choice replaced the cookie
        $browser->request('GET', '/login', server: $referer);
        self::assertSame('es', $this->htmlLang($browser));

        $browser->getCookieJar()->clear();
        $browser->request('GET', '/login', server: $referer);
        self::assertSame('cs', $this->htmlLang($browser));
    }

    public function testLanguageSwitcherKeepsReturnOnAuthPages(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login?return=%2Fen%2Fhub', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        $czechLink = $crawler->filter('a[data-locale-preference-locale-param="cs"]')->first()->attr('href');

        self::assertNotNull($czechLink);
        self::assertStringStartsWith('/login?', $czechLink);
        self::assertStringContainsString('_locale=cs', $czechLink);
        self::assertStringContainsString('return=/en/hub', $czechLink);
    }

    private function htmlLang(KernelBrowser $browser): null|string
    {
        return $browser->getCrawler()->filter('html')->attr('lang');
    }

    private function localeCookie(KernelBrowser $browser): null|Cookie
    {
        foreach ($browser->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === NativeAuthPageSubscriber::LOCALE_COOKIE) {
                return $cookie;
            }
        }

        return null;
    }
}
