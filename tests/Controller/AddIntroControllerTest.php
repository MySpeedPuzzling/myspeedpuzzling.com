<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The guests' Add (docs/features/navigation README §4.6, ADD-05): Join, Sign in back to the add page and the tracker
 * app, in every locale; not for the index; the same shared-cacheable HTML for every guest. A signed-in player goes
 * straight to the add page (implementation plan decision 41).
 */
final class AddIntroControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    /**
     * @return iterable<string, array{string, string, null|string}>
     */
    public static function provideLocalePaths(): iterable
    {
        // path, the add page Sign in returns to, the tracker app page
        yield 'en' => ['/en/add', '/en/puzzle-add', '/en/puzzle-tracker-app'];
        yield 'cs' => ['/pridat', '/pridat-puzzle', null];
        yield 'de' => ['/de/eintragen', '/de/puzzle-hinzufuegen', null];
        yield 'es' => ['/es/anadir', '/es/agregar-puzzle', null];
        yield 'fr' => ['/fr/ajouter', '/fr/ajouter-puzzle', null];
        yield 'ja' => ['/ja/追加', '/ja/' . rawurlencode('パズル追加'), null];
    }

    #[DataProvider('provideLocalePaths')]
    public function testGuestsGetTheExplainer(string $path, string $addPage, null|string $trackerApp): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        // Shared-cacheable guest HTML: no cookie, no CSRF token in the page (the footer's newsletter form keeps its stateless one)
        self::assertSame([], $browser->getResponse()->headers->getCookies());
        self::assertCount(0, $crawler->filter('main [name="_token"], main [name$="[_token]"]'));
        self::assertCount(0, $crawler->filter('.add-intro form'));
        self::assertCount(1, $crawler->filter('.add-intro h1'));

        // Join without ?return= (registration lands on /welcome, which offers logging a puzzle)
        self::assertSame('/register', $crawler->filter('[data-add-intro="join"]')->attr('href'));
        // Sign in comes back to the add page
        $signIn = (string) $crawler->filter('[data-add-intro="sign_in"]')->attr('href');
        parse_str((string) parse_url($signIn, PHP_URL_QUERY), $query);
        self::assertSame('/login', parse_url($signIn, PHP_URL_PATH));
        self::assertSame(['return' => $addPage], $query);
        self::assertNotNull($crawler->filter('[data-add-intro="tracker_app"]')->attr('href'));

        if ($trackerApp !== null) {
            self::assertSame($trackerApp, $crawler->filter('[data-add-intro="tracker_app"]')->attr('href'));
        }
    }

    public function testSignedInPlayersGoStraightToTheAddPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/add');

        self::assertResponseRedirects('/en/puzzle-add', 302);
    }

    public function testTheGuestPageCostsNoMoreStatementsThanTheFaq(): void
    {
        $browser = self::createClient();

        // Warm up both pages first, then count the second request (as ChromeQueryBudgetTest does)
        $browser->request('GET', '/en/faq');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();
        $faq = $this->queryCount($browser);

        $browser->request('GET', '/en/add');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/add');
        self::assertResponseIsSuccessful();

        $this->assertQueryCountAtMost($browser, $faq, 'The guest Add explainer (ADD-05) vs the /en/faq guest pin');
    }
}
