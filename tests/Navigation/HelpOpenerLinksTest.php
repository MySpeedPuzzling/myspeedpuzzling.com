<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every opener of the Help sheet is help_opener_attrs() (implementation plan §4 HELP-01b, §7, review F6): a link to
 * `help` that opens the sheet in modal-frame with its own scrim (data-modal-scrim="own" - the only modal without
 * Bootstrap's backdrop, released after a send) and that crawlers never follow. A link to `help` without it would open
 * the sheet on Bootstrap's backdrop, which the sent state cannot release (HELP-05). Language switch links on the help
 * page itself are not openers (they load the page in another language).
 */
final class HelpOpenerLinksTest extends WebTestCase
{
    /**
     * @return iterable<string, array{null|string, string, int}> viewer, page, openers expected at least
     */
    public static function pages(): iterable
    {
        yield 'guest faq' => [null, '/en/faq', 0];
        yield 'guest menu page' => [null, '/en/menu', 0];
        // The Menu's Send feedback row (MENU-01): in the docked-column slot and on the menu page
        yield 'player faq' => [PlayerFixture::PLAYER_REGULAR, '/en/faq', 1];
        yield 'player menu page' => [PlayerFixture::PLAYER_REGULAR, '/en/menu', 1];
        yield 'player hub' => [PlayerFixture::PLAYER_REGULAR, '/en/hub', 1];
        yield 'player puzzle page' => [PlayerFixture::PLAYER_REGULAR, '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, 1];
        yield 'player help page' => [PlayerFixture::PLAYER_REGULAR, '/en/help', 1];
        yield 'admin faq' => [PlayerFixture::PLAYER_ADMIN, '/en/faq', 1];
        yield 'player, Czech' => [PlayerFixture::PLAYER_REGULAR, '/napoveda', 1];
    }

    #[DataProvider('pages')]
    public function testEveryLinkToHelpIsAnOpener(null|string $playerId, string $url, int $atLeast): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        $helpPaths = $this->helpPaths();
        $openers = 0;
        $wrong = [];

        foreach ($crawler->filter('a[href]') as $link) {
            assert($link instanceof DOMElement);
            $path = rawurldecode((string) parse_url($link->getAttribute('href'), PHP_URL_PATH));

            // A language switch link (locale-preference) loads the page in another language - not an opener
            if (!in_array($path, $helpPaths, true) || $link->hasAttribute('data-locale-preference-locale-param')) {
                continue;
            }

            $openers++;
            $element = new Crawler($link);

            if (
                $link->getAttribute('data-modal-scrim') !== 'own'
                || $link->getAttribute('data-turbo-frame') !== 'modal-frame'
                || !in_array('nofollow', explode(' ', $link->getAttribute('rel')), true)
            ) {
                $wrong[] = $element->outerHtml();
            }
        }

        self::assertSame([], $wrong, 'Links to `help` without help_opener_attrs()');
        self::assertGreaterThanOrEqual($atLeast, $openers);
    }

    /**
     * @return list<string> the paths of `help` in every locale
     */
    private function helpPaths(): array
    {
        $paths = [];

        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if ($name === 'help' || str_starts_with($name, 'help.')) {
                $paths[] = $route->getPath();
            }
        }

        self::assertCount(6, $paths);

        return $paths;
    }
}
