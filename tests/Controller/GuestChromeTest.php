<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * Guests' chrome (docs/features/navigation README §4.1-4.3, D1, D5, D17; implementation plan NAV-09): the same five
 * places as a player - Home = the homepage, Puzzles = the puzzle database, Add = the Add explainer, Community, Profile =
 * sign in and back to this page; no Inbox, no account menu; ? = the Help page of this page, whose sheet asks a guest to
 * sign in instead of offering the feedback box (HELP-03). Everything is derived from the URL - no CSRF token, no session,
 * no flash, nothing per visitor - so guest HTML stays shared-cacheable (`Cache-Control: public`, no cookie) and every
 * guest browser gets the same chrome.
 */
final class GuestChromeTest extends WebTestCase
{
    // The chrome: the top bar, the bottom bar, the Menu (in its slot) and its phone shell
    private const array CHROME = ['body > header', 'body > #bottom-nav', '#site-menu', '#menu-layer'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function guestPages(): iterable
    {
        yield 'hub' => ['/en/hub'];
        yield 'puzzle page' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_500_01];
        yield 'community' => ['/en/community'];
        yield 'add intro' => ['/en/add'];
        yield 'help' => ['/en/help'];
    }

    #[DataProvider('guestPages')]
    public function testAGuestsChromeIsTheFivePlacesWithoutInboxAndStaysShared(string $url): void
    {
        $browser = self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        // Shared-cacheable: public, no cookie (no session was started)
        $response = $browser->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('public'), $url . ': ' . $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies(), $url);

        // The five places (D1): Home = the homepage (D5), the puzzle database, the Add explainer (also on a puzzle page),
        // Community, Profile = sign in, back to this page
        $signIn = $router->generate('login', ['return' => $url]);
        self::assertSame([
            'home' => $router->generate('homepage'),
            'puzzles' => $router->generate('puzzles'),
            'add' => $router->generate('add_intro'),
            'community' => $router->generate('community'),
            'profile' => $signIn,
        ], $this->places($crawler));

        // The top bar: the logo is Home, + Add the explainer, Sign in the Profile tab's link (decision 23)
        $header = $crawler->filter('body > header.site-header');
        self::assertCount(1, $header);
        self::assertSame($router->generate('homepage'), $header->filter('a.site-header-logo')->attr('href'));
        self::assertSame($router->generate('add_intro'), $header->filter('a.site-header-add')->attr('href'));
        self::assertSame($signIn, $header->filter('a.site-header-signin')->attr('href'));

        // ? = Help (HELP-03: the sheet of this page; on the help page itself, the page)
        $help = $header->filter('a[data-bar-action="help"]');
        self::assertCount(1, $help);
        self::assertSame($url === '/en/help' ? $router->generate('help') : $router->generate('help', ['url' => $url]), $help->attr('href'));

        // No Inbox, no account menu - not in the bar, not in the Menu (README §4.4: Inbox is for signed-in players)
        self::assertCount(0, $crawler->filter('[data-bar-action="inbox"], a[data-inbox-link], .inbox-link, [data-bar-count]'), $url);
        self::assertCount(0, $crawler->filter('#site-menu [data-nav^="menu/inbox/"], #site-menu .menu-you'), $url);
        self::assertCount(0, $crawler->filter('.site-header-account, .nav-account-trigger, [data-viewer-signed-in]'), $url);

        // Nothing per visitor in the chrome: no form, no CSRF token (the footer's newsletter form keeps its stateless one)
        foreach (self::CHROME as $selector) {
            self::assertCount(1, $crawler->filter($selector), $url . ': ' . $selector);
            self::assertCount(0, $crawler->filter($selector)->filter('form, [name="_token"], [name$="[_token]"], [name="_csrf_token"]'), $url . ': ' . $selector);
        }
    }

    /**
     * HELP-03: a guest's help has no feedback box - "Sign in to send feedback", back to the help page of the same page
     */
    public function testAGuestsHelpOffersSignInInsteadOfTheFeedbackBox(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/help?url=' . rawurlencode('/en/community'));
        self::assertResponseIsSuccessful();

        $sheet = $crawler->filter('main [data-help-sheet]');
        self::assertCount(1, $sheet);
        self::assertCount(0, $sheet->filter('form, textarea'));
        $signIn = $sheet->filter('a[data-nav="help/help/sign_in"]');
        self::assertCount(1, $signIn);
        self::assertStringStartsWith('/login?return=', (string) $signIn->attr('href'));
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'));
    }

    /**
     * Every guest browser gets the same chrome for a URL (a shared cache serves them all): the bars and the Menu are
     * identical for an iPhone, an Android phone and a desktop browser
     */
    public function testEveryGuestBrowserGetsTheSameChrome(): void
    {
        $chrome = [];

        foreach (NativeUserAgents::web() as $name => [$userAgent]) {
            $browser = self::createClient(server: ['HTTP_USER_AGENT' => $userAgent]);
            $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);
            self::assertResponseIsSuccessful();
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), $name);

            $chrome[$name] = implode("\n", array_map(static fn (string $selector): string => $crawler->filter($selector)->outerHtml(), self::CHROME));
            self::ensureKernelShutdown();
        }

        self::assertCount(1, array_unique($chrome), 'The guest chrome differs between browsers');
    }

    /**
     * @return array<string, string> place => href of the bottom bar's tabs
     */
    private function places(Crawler $crawler): array
    {
        $places = [];

        foreach ($crawler->filter('body > #bottom-nav a[data-nav-place]') as $tab) {
            assert($tab instanceof DOMElement);
            $places[$tab->getAttribute('data-nav-place')] = $tab->getAttribute('href');
        }

        return $places;
    }
}
