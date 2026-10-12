<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Navigation instrumentation (docs/features/navigation P0-03): every link and button of the site header and footer
 * carries a well-formed data-nav="<surface>/<place>/<item>" - assets/nav_instrumentation.js counts nothing else, so a
 * link without one is a link nobody can measure. The page's own <header>/<footer> elements are not chrome.
 */
final class NavInstrumentationMarkupTest extends WebTestCase
{
    private const string CHROME = 'body > header a, body > header button, body > footer a, body > footer button';

    /**
     * @return iterable<string, array{null|string, bool, list<string>}>
     */
    public static function viewers(): iterable
    {
        // Guests: register / sign in, the newsletter form, popular searches
        yield 'guest' => [null, false, ['toolbar/tools/sign_in', 'toolbar/tools/register', 'footer/newsletter/subscribe', 'footer/newsletter/privacy', 'footer/popular_brands/cobble_hill', 'footer/popular_world_championship/wjpc_2026', 'footer/credits/jan']];
        yield 'member' => [PlayerFixture::PLAYER_WITH_STRIPE, false, ['account/toggle/open', 'account/links/logout', 'toolbar/tools/messages', 'toolbar/tools/notifications', 'hamburger/places/hub', 'footer/language/de']];
        // The admin key with every queue
        yield 'admin' => [PlayerFixture::PLAYER_ADMIN, false, ['admin_key/toggle/open', 'admin_key/queues/change_requests', 'admin_key/queues/duplicate_results', 'topbar/language/de']];
        // A player who switched the newsletter off gets the "Turn it on" button
        yield 'player with the newsletter off' => [PlayerFixture::PLAYER_REGULAR, true, ['footer/newsletter/turn_on']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('viewers')]
    public function testEveryChromeLinkAndButtonCarriesAWellFormedNavId(null|string $playerId, bool $newsletterOff, array $expected): void
    {
        $browser = self::createClient();

        if ($newsletterOff && $playerId !== null) {
            $browser->getContainer()->get(Connection::class)->executeStatement(
                'UPDATE player SET newsletter_enabled = false WHERE id = :id',
                ['id' => $playerId],
            );
        }

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $elements = $crawler->filter(self::CHROME);
        self::assertGreaterThan(40, $elements->count());

        $missing = [];
        $navIds = [];

        foreach ($elements as $element) {
            assert($element instanceof DOMElement);
            $value = $element->getAttribute('data-nav');

            if (strlen($value) > 100 || preg_match(NavClickController::NAV_PATTERN, $value) !== 1) {
                $missing[] = sprintf('<%s> "%s" data-nav="%s"', $element->tagName, trim((string) preg_replace('/\s+/', ' ', $element->textContent)), $value);
            }

            $navIds[] = $value;
        }

        self::assertSame([], $missing, 'Chrome links and buttons without a well-formed data-nav');

        foreach ($expected as $navId) {
            self::assertContains($navId, $navIds);
        }

        // The listener only matches links and buttons; nothing else - <html> least of all - carries data-nav
        self::assertCount(0, $crawler->filter('[data-nav]:not(a):not(button)'));
        self::assertNull($crawler->filter('html')->attr('data-nav'));
    }

    /**
     * One id per destination: two elements share an id only when they are the same destination (the phone and the
     * desktop logo) - the beacon's layout field tells the tiers apart.
     */
    public function testNavIdsNameOneDestinationEach(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/faq');

        $hrefsById = [];

        foreach ($crawler->filter(self::CHROME) as $element) {
            assert($element instanceof DOMElement);
            $hrefsById[$element->getAttribute('data-nav')][] = $element->tagName . ' ' . $element->getAttribute('href');
        }

        foreach ($hrefsById as $navId => $targets) {
            self::assertCount(1, array_unique($targets), $navId . ' names different destinations: ' . implode(', ', $targets));
        }

        self::assertSame(['/', '/'], array_map(
            static fn (string $target): string => substr($target, 2),
            $hrefsById['navbar/logo/home'] ?? [],
        ), 'The phone and the desktop logo');
    }

    /**
     * The toolbar's messages link is re-rendered by a Mercure stream (MercureNotifier) - it keeps its id there.
     */
    public function testTheStreamedMessagesBadgeKeepsItsNavId(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $crawler = new Crawler($twig->render('messaging/_unread_badge.html.twig', ['count' => 3]));

        self::assertSame('toolbar/tools/messages', $crawler->filter('a')->attr('data-nav'));
    }
}
