<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DOMElement;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\Navigation\NavChromeAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

/**
 * The phone bottom bar (docs/features/navigation README §4.2, D1; implementation plan NAV-01): the five places on every
 * web page - Home · Puzzles · Add · Community · Profile - linked from the request and the profile row only (the chrome
 * budgets pin that it costs no statement). Players: the Hub, their own library, the add page (a puzzle page's Add
 * carries the puzzle), the community landing, their own profile with their avatar. Guests: the homepage, the puzzle
 * database, the Add explainer, the community landing and sign in back to the page - shared-cacheable. A direct child
 * of <body> before <main> (critical CSS reserves its height only there), hidden on pages that set hide_bottom_bar,
 * absent in the app.
 */
final class BottomNavTest extends WebTestCase
{
    private const array PLACES = ['home', 'puzzles', 'add', 'community', 'profile'];

    // README §4.2: the tab words of every locale
    private const array LABELS = [
        'en' => ['Home', 'Puzzles', 'Add', 'Community', 'Profile'],
        'cs' => ['Domů', 'Puzzle', 'Přidat', 'Komunita', 'Profil'],
        'de' => ['Start', 'Puzzles', 'Eintragen', 'Community', 'Profil'],
        'es' => ['Inicio', 'Puzzles', 'Añadir', 'Comunidad', 'Perfil'],
        'fr' => ['Accueil', 'Puzzles', 'Ajouter', 'Communauté', 'Profil'],
        'ja' => ['ホーム', 'パズル', '追加', 'コミュニティ', 'マイページ'],
    ];

    public function testGuestsGetTheFivePlacesWithSignInAndStayShared(): void
    {
        $browser = self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        self::assertSame([
            'home' => $router->generate('homepage'),
            'puzzles' => $router->generate('puzzles'),
            'add' => $router->generate('add_intro'),
            'community' => $router->generate('community'),
            'profile' => $router->generate('login', ['return' => '/en/faq']),
        ], $this->hrefs($crawler));

        // The FAQ belongs to no place: nothing is current
        self::assertSame([], $this->current($crawler));

        // A person icon, no avatar; the sign-in link is not followed (no per-page crawl variants)
        $profile = $this->tab($crawler, 'profile');
        self::assertCount(1, $profile->filter('use[href="#nav-i-person"]'));
        self::assertCount(1, $profile->filter('use[href="#nav-i-person-fill"]'));
        self::assertCount(0, $profile->filter('.lb-avatar'));
        self::assertSame('nofollow', $profile->attr('rel'));

        $response = $browser->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('public'), (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies());
    }

    public function testTheGuestsProfileTabComesBackToThePageAndThePlaceIsCurrent(): void
    {
        $browser = self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', '/en/community');
        self::assertResponseIsSuccessful();

        self::assertSame($router->generate('login', ['return' => '/en/community']), $this->tab($crawler, 'profile')->attr('href'));
        // Decision 27: "page" on the page the place links to
        self::assertSame(['community' => 'page'], $this->current($crawler));
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'));

        // The puzzle database is Puzzles' own page for guests; a puzzle page lies deeper inside the place
        $crawler = $browser->request('GET', '/en/puzzle');
        self::assertSame(['puzzles' => 'page'], $this->current($crawler));

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);
        self::assertResponseIsSuccessful();
        self::assertSame(['puzzles' => 'true'], $this->current($crawler));
        // A guest's Add is the explainer, also on a puzzle page
        self::assertSame($router->generate('add_intro'), $this->tab($crawler, 'add')->attr('href'));
    }

    public function testPlayersGetTheirHubLibraryAndProfileWithTheAvatar(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();

        self::assertSame([
            'home' => $router->generate('hub'),
            'puzzles' => $router->generate('puzzle_library', ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE]),
            'add' => $router->generate('puzzle_add'),
            'community' => $router->generate('community'),
            'profile' => $router->generate('player_profile', ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE]),
        ], $this->hrefs($crawler));
        self::assertSame(['home' => 'page'], $this->current($crawler));

        // The avatar instead of the person icon; the viewer's own pages are not followed (as the Menu rows)
        $profile = $this->tab($crawler, 'profile');
        self::assertCount(1, $profile->filter('.nav-tab-icon .lb-avatar'));
        self::assertCount(0, $profile->filter('use'));
        self::assertSame('nofollow', $profile->attr('rel'));
        self::assertSame('nofollow', $this->tab($crawler, 'puzzles')->attr('rel'));
        self::assertNull($this->tab($crawler, 'home')->attr('rel'));

        // The own profile is Profile's page
        $crawler = $browser->request('GET', $router->generate('player_profile', ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE]));
        self::assertResponseIsSuccessful();
        self::assertSame(['profile' => 'page'], $this->current($crawler));
    }

    public function testOnAPuzzlePageAddCarriesThePuzzle(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);
        self::assertResponseIsSuccessful();

        self::assertSame($router->generate('puzzle_add', ['puzzleId' => PuzzleFixture::PUZZLE_500_01]), $this->tab($crawler, 'add')->attr('href'));
        self::assertSame($router->generate('puzzle_library', ['playerId' => PlayerFixture::PLAYER_REGULAR]), $this->tab($crawler, 'puzzles')->attr('href'));
        // A puzzle page lies inside Puzzles, it is not the place's own page
        self::assertSame(['puzzles' => 'true'], $this->current($crawler));
    }

    public function testTheBarIsADirectChildOfBodyBeforeMainWithItsSprite(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $bar = $crawler->filter('body > nav#bottom-nav');
        self::assertCount(1, $bar);
        self::assertCount(1, $crawler->filter('#bottom-nav'));
        self::assertCount(1, $crawler->filterXPath('//body/nav[@id="bottom-nav"]/following-sibling::main'), 'The bar comes before <main>');
        self::assertSame('bottom-nav', $bar->attr('class'));
        self::assertSame('Main sections', $bar->attr('aria-label'));
        self::assertSame('bottom-nav tab-gestures', $bar->attr('data-controller'));
        self::assertNotNull($bar->attr('data-ptr-ignore'));
        self::assertNull($bar->attr('hidden'));

        // Each tab: data-nav-place, the tabbar data-nav id, the fixed press marker and its label (the accessible name)
        $tabs = $bar->filter('a.nav-tab');
        self::assertCount(5, $tabs);

        foreach ($tabs as $index => $tab) {
            assert($tab instanceof DOMElement);
            $place = self::PLACES[$index];
            self::assertSame($place, $tab->getAttribute('data-nav-place'));
            self::assertSame('tabbar/places/' . $place, $tab->getAttribute('data-nav'));
            self::assertSame('fixed', $tab->getAttribute('data-press'));
            self::assertSame(self::LABELS['en'][$index], new Crawler($tab)->filter('.nav-tab-label')->text());
        }

        // The Add tile has no filled twin (never selected); every icon the bar uses is a symbol of the one sprite
        self::assertCount(1, $this->tab($crawler, 'add')->filter('use'));
        self::assertCount(1, $crawler->filter('svg.nav-sprite[hidden]'));

        foreach ($bar->filter('use') as $use) {
            assert($use instanceof DOMElement);
            $id = substr($use->getAttribute('href'), 1);
            self::assertCount(1, $crawler->filter('svg.nav-sprite symbol[id="' . $id . '"]'), 'No sprite symbol ' . $id);
        }
    }

    public function testTheTabWordsOfEveryLocale(): void
    {
        $browser = self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);

        foreach (self::LABELS as $locale => $labels) {
            $crawler = $browser->request('GET', $router->generate('faq', ['_locale' => $locale]));
            self::assertResponseIsSuccessful($locale);

            self::assertSame($labels, $crawler->filter('#bottom-nav .nav-tab-label')->each(static fn (Crawler $label): string => $label->text()), $locale);
        }
    }

    /**
     * hide_bottom_bar (decision 46, NAV-08): no tab bar on phones - a class critical CSS hides under the phone query and
     * keeps no room for; never the hidden attribute, so on desktop the places stay as the rail (README §4.10 hides the
     * tab bar only)
     */
    public function testAPageThatSetsHideBottomBarRendersItHidden(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $request = Request::create('/en/faq');
        $request->attributes->set('_route', 'faq');
        $request->attributes->set('_route_params', ['_locale' => 'en']);
        $request->setLocale('en');
        $container->get(RequestStack::class)->push($request);
        $twig = $container->get(Environment::class);

        $hidden = new Crawler($twig->render('navigation/_bottom_nav.html.twig', ['hide_bottom_bar' => true]));
        self::assertCount(1, $hidden->filter('nav#bottom-nav.bottom-nav--tabbar-hidden'));
        self::assertNull($hidden->filter('nav#bottom-nav')->attr('hidden'));
        self::assertCount(5, $hidden->filter('nav#bottom-nav a.nav-tab'));

        $shown = new Crawler($twig->render('navigation/_bottom_nav.html.twig', []));
        self::assertNull($shown->filter('nav#bottom-nav')->attr('hidden'));
        self::assertCount(0, $shown->filter('.bottom-nav--tabbar-hidden'));
    }

    public function testTheAppGetsNoBar(): void
    {
        $browser = self::createClient();

        foreach (NativeUserAgents::native() as $name => [$userAgent]) {
            $crawler = $browser->request('GET', '/en/community', server: ['HTTP_USER_AGENT' => $userAgent]);
            self::assertResponseIsSuccessful();

            NavChromeAssertions::assertNoWebChrome($crawler, $name);
            self::assertCount(0, $crawler->filter('#bottom-nav, .nav-sprite, .nav-tab'), $name);
        }

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/hub', server: ['HTTP_USER_AGENT' => NativeUserAgents::IOS]);
        self::assertResponseIsSuccessful();
        NavChromeAssertions::assertNoWebChrome($crawler);
    }

    /**
     * @return array<string, null|string> place => href
     */
    private function hrefs(Crawler $crawler): array
    {
        $hrefs = [];

        foreach ($crawler->filter('#bottom-nav a.nav-tab') as $tab) {
            assert($tab instanceof DOMElement);
            $hrefs[$tab->getAttribute('data-nav-place')] = $tab->getAttribute('href');
        }

        return $hrefs;
    }

    /**
     * @return array<string, string> place => aria-current value, for the tabs that have one
     */
    private function current(Crawler $crawler): array
    {
        $current = [];

        foreach ($crawler->filter('#bottom-nav a.nav-tab[aria-current]') as $tab) {
            assert($tab instanceof DOMElement);
            $current[$tab->getAttribute('data-nav-place')] = $tab->getAttribute('aria-current');
        }

        return $current;
    }

    private function tab(Crawler $crawler, string $place): Crawler
    {
        $tab = $crawler->filter('#bottom-nav a.nav-tab[data-nav-place="' . $place . '"]');
        self::assertCount(1, $tab, $place);

        return $tab;
    }
}
