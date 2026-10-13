<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Tests\Value\InboxBadgeScriptTest;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The hrefs and accessible names a realtime push applies to every Inbox link (assets/inbox_badge.js) ride on the
 * page's first Inbox link only (inbox_link_strings(), templates/inbox/_link.html.twig - the chrome's weight, README
 * §4.12): the top bar's on every page with bars, the Menu's row on the `menu` page in the app (no bars). Exactly one
 * link carries them, and after a push every Inbox link of the page - the top bar's on phones and desktop, the Menu's row
 * in the docked column, the phone panel and the desktop overlay - opens the tab and says the name the server would
 * render for the same numbers (tests/inbox-badge-harness.mjs runs the real module on this page's HTML).
 */
final class InboxLinkStringsTest extends WebTestCase
{
    private const string MEMBER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string LINK = 'a[data-inbox-link]';
    private const string CARRIER = 'a[data-inbox-link][data-inbox-labels][data-inbox-hrefs]';

    /** Two pushes: only messages unread (Messages), then only "For you" (For you) */
    private const array PUSHES = [
        ['forYou' => 0, 'conversations' => 2, 'requests' => 0, 'at' => 1],
        ['forYou' => 3, 'conversations' => 0, 'requests' => 0, 'at' => 2],
    ];

    /**
     * @return iterable<string, array{string, null|string, string, list<string>}>
     */
    public static function pages(): iterable
    {
        // url, user agent, where the carrier sits, where the Menu is when the push arrives (tests/inbox-badge-harness.mjs)
        yield 'a page with bars' => ['/en/faq', null, '.site-header', ['as rendered', 'phone panel and desktop overlay', 'Menu ahead of the top bar']];
        yield 'the menu page on the web' => ['/en/menu', null, '.site-header', ['as rendered', 'Menu ahead of the top bar']];
        yield 'the menu page in the app' => ['/en/menu', NativeUserAgents::IOS, '#site-menu', ['as rendered']];
    }

    /**
     * @param list<string> $placements
     */
    #[DataProvider('pages')]
    public function testOneLinkCarriesTheStringsAndEveryInboxLinkUsesThem(string $url, null|string $userAgent, string $carrierIn, array $placements): void
    {
        $browser = self::createClient(server: $userAgent === null ? [] : ['HTTP_USER_AGENT' => $userAgent]);
        TestingLogin::asPlayer($browser, self::MEMBER);

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        $links = $crawler->filter(self::LINK);
        self::assertGreaterThanOrEqual($userAgent === null ? 2 : 1, $links->count(), 'The top bar\'s Inbox link and the Menu\'s Inbox row');
        self::assertCount(1, $crawler->filter('a[data-inbox-labels]'), 'The labels ride on one link');
        self::assertCount(1, $crawler->filter('a[data-inbox-hrefs]'), 'The hrefs ride on one link');
        self::assertCount(1, $crawler->filter(self::CARRIER), 'The same link carries both');

        // It is the page's first Inbox link, where it is expected
        self::assertNotNull($links->first()->attr('data-inbox-labels'), 'The first Inbox link carries the strings');
        self::assertCount(1, $crawler->filter($carrierIn . ' ' . self::CARRIER), $carrierIn);
        self::assertSame(
            array_fill(0, $links->count() - 1, null),
            $links->slice(1)->each(static fn (Crawler $link): null|string => $link->attr('data-inbox-labels') ?? $link->attr('data-inbox-hrefs')),
            'Every other Inbox link reads them from the first',
        );

        // Every Inbox link has a badge a push reaches
        foreach ($links as $link) {
            assert($link instanceof \DOMElement);
            self::assertCount(1, (new Crawler($link))->filter('.inbox-badge'), $link->getAttribute('data-nav'));
        }

        /** @var list<array<string, list<list<array{nav: string, href: string, label: string, count: string}>>>> $pages */
        $pages = InboxBadgeScriptTest::runInNode(['pages' => [['html' => (string) $browser->getResponse()->getContent(), 'pushes' => self::PUSHES]]])['pages'];
        $results = $pages[0];

        self::assertSame($placements, array_keys($results), 'Where the Menu was when the push arrived');

        $navs = $links->each(static fn (Crawler $link): string => (string) $link->attr('data-nav'));
        sort($navs);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $expected = [
            ['href' => '/en/messages', 'label' => $translator->trans('inbox.badge.name.messages', ['%count%' => 2], null, 'en'), 'count' => '2'],
            ['href' => '/en/notifications', 'label' => $translator->trans('inbox.badge.name.for_you', ['%count%' => 3], null, 'en'), 'count' => '3'],
        ];

        foreach ($results as $placement => $afterPushes) {
            foreach ($afterPushes as $index => $after) {
                $seen = array_map(static fn (array $link): string => $link['nav'], $after);
                sort($seen);
                self::assertSame($navs, $seen, $placement . ': every Inbox link of the page');

                foreach ($after as $link) {
                    self::assertSame(
                        $expected[$index],
                        ['href' => $link['href'], 'label' => $link['label'], 'count' => $link['count']],
                        $placement . ', push ' . ($index + 1) . ': ' . $link['nav'],
                    );
                }
            }
        }
    }
}
