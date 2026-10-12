<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Where did it go?" (docs/features/navigation README §8, LAUNCH-02a): a public page in every locale, not for the
 * index, shared-cacheable for guests. The skeleton holds the place of the mapping table LAUNCH-02 fills.
 */
final class WhereDidItGoControllerTest extends WebTestCase
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

        $crawler = $browser->request('GET', '/en/where-did-it-go');

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('Where did it go?', trim($crawler->filter('main h1')->text()));
        self::assertCount(1, $crawler->filter('main [data-where-did-it-go-mapping]'));

        if ($playerId === null) {
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
            // Shared-cacheable guest HTML: no cookie, no CSRF token in the page (the footer's newsletter form keeps its stateless one)
            self::assertSame([], $browser->getResponse()->headers->getCookies());
            self::assertCount(0, $crawler->filter('main [name="_token"], main [name$="[_token]"]'));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLocalePaths(): iterable
    {
        yield 'cs' => ['/kam-se-to-presunulo', 'Kam se to přesunulo?'];
        yield 'de' => ['/de/wo-ist-es-jetzt', 'Wo ist es jetzt?'];
        yield 'es' => ['/es/donde-esta-ahora', '¿Dónde está ahora?'];
        yield 'fr' => ['/fr/ou-est-passe', 'Où est-ce passé ?'];
        yield 'ja' => ['/ja/移動したページ', 'どこに移動しましたか？'];
    }

    #[DataProvider('provideLocalePaths')]
    public function testEveryLocaleHasItsPage(string $path, string $title): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame($title, trim($crawler->filter('main h1')->text()));
    }
}
