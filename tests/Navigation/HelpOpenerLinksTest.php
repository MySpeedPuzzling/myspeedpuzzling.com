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
 * page itself are not openers (they load the page in another language), nor is its "Reload page" (a plain visit). A link to `feedback` that opens in modal-frame
 * answers the Help sheet too (GET feedback with the frame header), so it counts as an opener as well (review F6: the old
 * top-bar and footer Feedback links); a guest's plain link to the `feedback` page is not one.
 */
final class HelpOpenerLinksTest extends WebTestCase
{
    /**
     * @return iterable<string, array{null|string, string, int}> viewer, page, openers expected at least
     */
    public static function pages(): iterable
    {
        // The end-of-page line (HELP-04) - guests too
        yield 'guest faq' => [null, '/en/faq', 1];
        yield 'guest menu page' => [null, '/en/menu', 1];
        yield 'guest puzzle page' => [null, '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, 1];
        // The Menu's Send feedback row (MENU-01): in the docked-column slot and on the menu page
        yield 'player faq' => [PlayerFixture::PLAYER_REGULAR, '/en/faq', 1];
        yield 'player menu page' => [PlayerFixture::PLAYER_REGULAR, '/en/menu', 1];
        yield 'player hub' => [PlayerFixture::PLAYER_REGULAR, '/en/hub', 1];
        yield 'player puzzle page' => [PlayerFixture::PLAYER_REGULAR, '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, 1];
        yield 'player help page' => [PlayerFixture::PLAYER_REGULAR, '/en/help', 1];
        yield 'admin faq' => [PlayerFixture::PLAYER_ADMIN, '/en/faq', 1];
        yield 'player, Czech' => [PlayerFixture::PLAYER_REGULAR, '/napoveda', 1];
        // The top bar's ? / Feedback (NAV-03/04; the old top bar's link went with NAV-06) and the footer's Feedback link
        // (review F6) next to the Menu's row and the end-of-page line
        yield 'player faq, every opener' => [PlayerFixture::PLAYER_REGULAR, '/en/faq', 4];
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

        $helpPaths = $this->pathsOf('help');
        $feedbackPaths = $this->pathsOf('feedback');
        $openers = 0;
        $wrong = [];

        foreach ($crawler->filter('a[href]') as $link) {
            assert($link instanceof DOMElement);
            $path = rawurldecode((string) parse_url($link->getAttribute('href'), PHP_URL_PATH));
            $toHelp = in_array($path, $helpPaths, true);
            // GET feedback in modal-frame answers the Help sheet
            $toFeedbackSheet = in_array($path, $feedbackPaths, true) && $link->getAttribute('data-turbo-frame') === 'modal-frame';

            // A language switch link (locale-preference) loads the page in another language, a data-turbo="false" link is a
            // plain page visit (the help page's own "Reload page", HELP-02) - neither opens the sheet
            if ((!$toHelp && !$toFeedbackSheet) || $link->hasAttribute('data-locale-preference-locale-param') || $link->getAttribute('data-turbo') === 'false') {
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

        self::assertSame([], $wrong, 'Links opening the Help sheet without help_opener_attrs()');
        self::assertGreaterThanOrEqual($atLeast, $openers);
    }

    /**
     * @return list<string> the paths of the route in every locale
     */
    private function pathsOf(string $routeName): array
    {
        $paths = [];

        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if ($name === $routeName || str_starts_with($name, $routeName . '.')) {
                $paths[] = $route->getPath();
            }
        }

        self::assertCount(6, $paths, $routeName);

        return $paths;
    }
}
