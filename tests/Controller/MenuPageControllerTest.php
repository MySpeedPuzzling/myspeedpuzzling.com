<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\Navigation\NavChromeAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Menu as a page (docs/features/navigation README §4.12, MENU-02a): the fallback of the Menu panel in every
 * locale, not for the index, shared-cacheable for guests. Skeleton: heading and intro - MENU-01 adds the rows (and
 * this test then compares them with the panel's per role).
 */
final class MenuPageControllerTest extends WebTestCase
{
    /**
     * @return iterable<string, array{null|string}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => [null];
        yield 'member' => [PlayerFixture::PLAYER_WITH_STRIPE];
    }

    #[DataProvider('provideViewers')]
    public function testThePage(null|string $playerId): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $browser->request('GET', '/en/menu');

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('Menu', trim($crawler->filter('main .menu-page h1')->text()));
        // The Menu partial is the page's content and its one #site-menu (MENU-01): phone rows, phone order
        self::assertCount(1, $crawler->filter('#site-menu'));
        self::assertCount(1, $crawler->filter('main #site-menu[data-surface="page"]'));
        self::assertGreaterThan(20, $crawler->filter('main #site-menu li[id^="menu-row-"]')->count());

        if ($playerId === null) {
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
            // Shared-cacheable guest HTML: no cookie, no CSRF token in the page (the footer's newsletter form keeps its stateless one)
            self::assertSame([], $browser->getResponse()->headers->getCookies());
            self::assertCount(0, $crawler->filter('main [name="_token"], main [name$="[_token]"]'));
        }
    }

    /**
     * The native app's Menu screen (NAV-14, review F26): no web chrome - so no slot copy either - but the page still
     * renders the Menu as its content
     *
     * @return iterable<string, array{string}>
     */
    public static function provideNativeUserAgents(): iterable
    {
        foreach (NativeUserAgents::native() as $name => [$userAgent]) {
            yield $name => [$userAgent];
        }
    }

    #[DataProvider('provideNativeUserAgents')]
    public function testTheNativeAppGetsTheMenuAsThePage(string $userAgent): void
    {
        $browser = self::createClient(server: ['HTTP_USER_AGENT' => $userAgent]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/menu');

        self::assertResponseIsSuccessful();
        NavChromeAssertions::assertNoWebChrome($crawler, 'native app');
        self::assertCount(1, $crawler->filter('main #site-menu'));
        self::assertCount(1, $crawler->filter('#site-menu'));

        // Every other page of the app: no Menu at all (the app has its own navigation)
        $crawler = $browser->request('GET', '/en/faq');
        self::assertCount(0, $crawler->filter('#site-menu'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLocalePaths(): iterable
    {
        yield 'cs' => ['/menu', 'Menu'];
        yield 'de' => ['/de/menue', 'Menü'];
        yield 'es' => ['/es/menu', 'Menú'];
        yield 'fr' => ['/fr/menu', 'Menu'];
        yield 'ja' => ['/ja/メニュー', 'メニュー'];
    }

    #[DataProvider('provideLocalePaths')]
    public function testEveryLocaleHasItsPage(string $path, string $title): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame($title, trim($crawler->filter('main .menu-page h1')->text()));
    }
}
