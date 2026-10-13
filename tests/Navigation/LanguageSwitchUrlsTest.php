<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * language_switch_urls() (decision 36, MENU-01) is the one rule of the language switch: the top bar's account menu
 * (NAV-05) and the footer's language menus, the Menu's Language row and the auth pages' header (NAV-08) show the same five links - the current page in the other languages,
 * filters kept, ?return= dropped (it would send a visitor back to the old language), except on the locale-less auth
 * pages, which keep their destination across the switch and read ?_locale=.
 */
final class LanguageSwitchUrlsTest extends WebTestCase
{
    public function testEverySwitchShowsTheCurrentPageInTheOtherLanguages(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle?pieces=500&return=/en/hub&return_title=Hub');
        self::assertResponseIsSuccessful();

        $router = self::getContainer()->get(RouterInterface::class);
        $expected = [];

        foreach (['de', 'cs', 'fr', 'es', 'ja'] as $locale) {
            $expected[$locale] = $router->generate('puzzles', ['_locale' => $locale, 'pieces' => '500']);
        }

        self::assertSame($expected, $this->links($crawler, '#site-menu #nav-language'));
        // The top bar's switch is the account menu's Language (NAV-05) since the old topbar went (NAV-06)
        self::assertSame($expected, $this->links($crawler, '.site-header .nav-account-language [data-controller="locale-preference"]'));
        self::assertSame($expected, $this->links($crawler, 'footer.site-footer .dropdown-menu[data-controller="locale-preference"]'));
        self::assertStringContainsString('pieces=500', $expected['cs']);
    }

    public function testAnAuthPageKeepsItsDestinationAcrossTheSwitch(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login?return=/en/faq');
        self::assertResponseIsSuccessful();

        $links = $this->links($crawler, 'footer.site-footer .dropdown-menu[data-controller="locale-preference"]');
        self::assertCount(5, $links);

        foreach ($links as $locale => $url) {
            self::assertStringStartsWith('/login?', $url);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame(['return' => '/en/faq', '_locale' => $locale], $query);
        }

        // NAV-08: an auth page has no Menu (decision 25) - its header's language switch (_auth_header.html.twig) is the
        // same switch, first on the page
        self::assertCount(0, $crawler->filter('#site-menu'));
        self::assertSame($links, $this->links($crawler, 'body > header.site-header--auth .site-header-languages'));
    }

    /**
     * @return array<string, string> locale => href
     */
    private function links(Crawler $crawler, string $container): array
    {
        $links = [];

        foreach ($crawler->filter($container)->first()->filter('a[data-locale-preference-locale-param]') as $link) {
            $anchor = new Crawler($link);
            $links[(string) $anchor->attr('data-locale-preference-locale-param')] = (string) $anchor->attr('href');
        }

        return $links;
    }
}
