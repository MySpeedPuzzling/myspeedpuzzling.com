<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Navigation\EmptySignedInEndpoint;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * nav_go (docs/features/navigation MENU-03, R14): the page search's link to a page of the viewer's own - the list is the
 * same for everybody, this names the viewer. A redirect to the link the viewer's Menu row has; signed-in only (a guest
 * signs in and comes back); an unknown id, a non-page entry or one the viewer may not use is a 404. Costs what an empty
 * signed-in endpoint costs: the user refresh and the profile row.
 */
final class NavigationGoControllerTest extends WebTestCase
{
    use QueryCountAssertions;
    use EmptySignedInEndpoint;

    public function testItRedirectsToTheViewersOwnPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $router = self::getContainer()->get(RouterInterface::class);

        foreach (
            [
            'results' => $router->generate('player_results', ['_locale' => 'en', 'playerId' => PlayerFixture::PLAYER_REGULAR]),
            'your_data' => $router->generate('export_puzzler_data', ['_locale' => 'en', 'playerId' => PlayerFixture::PLAYER_REGULAR]),
            'library' => $router->generate('puzzle_library', ['_locale' => 'en', 'playerId' => PlayerFixture::PLAYER_REGULAR]),
            // An anchor of a page keeps its fragment
            'sign_in_methods' => $router->generate('edit_profile', ['_locale' => 'en']) . '#sign-in-methods',
            ] as $id => $expected
        ) {
            $browser->request('GET', '/en/go/' . $id);

            self::assertResponseRedirects(null, 302, $id);
            self::assertSame($expected, $browser->getResponse()->headers->get('Location'), $id);
            self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'), $id);
        }
    }

    public function testEveryLocaleHasTheRoute(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        foreach (['cs', 'de', 'en', 'es', 'fr', 'ja'] as $locale) {
            $browser->request('GET', '/' . $locale . '/go/statistics');
            self::assertResponseRedirects(null, 302, $locale);
            self::assertStringEndsWith(PlayerFixture::PLAYER_REGULAR, (string) $browser->getResponse()->headers->get('Location'), $locale);
        }
    }

    public function testAGuestSignsInAndComesBack(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/go/results');

        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/login', $location);
        self::assertSame('/en/go/results', $this->returnOf($location));
    }

    public function testUnknownForeignOrNotAllowedEntriesAreNotFound(): void
    {
        $browser = self::createClient();
        // A player without a membership
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        // Unknown; not in the page search (an object page); not a page (the Help sheet, the language switch, Log out);
        // a role row; a member's entry for a non-member
        foreach (['no_such_entry', 'puzzle_page', 'send_feedback', 'language', 'logout', 'admin_vouchers', 'player_code'] as $id) {
            $browser->request('GET', '/en/go/' . $id);
            self::assertResponseStatusCodeSame(404, $id);
        }
    }

    public function testItCostsWhatAnEmptySignedInEndpointCosts(): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, NavigationViewer::PLAYER);

        $empty = $this->emptySignedInEndpointStatements($browser);

        $browser->request('GET', '/en/go/results');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/go/results');
        self::assertResponseRedirects();

        self::assertSame($empty, $this->executedSql($browser));
    }

    private function returnOf(string $location): null|string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return is_string($query['return'] ?? null) ? $query['return'] : null;
    }
}
