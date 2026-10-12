<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * The desktop account menu - the top bar's avatar ▾ (docs/features/navigation README §4.3; implementation plan NAV-05,
 * decision 24): a short Bootstrap dropdown for signed-in players - name + #code · View profile · Settings · Membership
 * with its status · Language (a <details> with the current page in the other languages) · Log out (outside Turbo).
 * Static links from the profile row (ChromeQueryBudgetTest pins that the chrome costs no statement), no lazy frame, no
 * role links (those and their queue counts are the Menu's Your roles, MENU-01), never a .more-menu.
 */
final class AccountMenuTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function signedInViewers(): iterable
    {
        yield 'member' => [NavigationViewer::MEMBER];
        yield 'moderator' => [NavigationViewer::MODERATOR];
        yield 'admin' => [NavigationViewer::ADMIN];
    }

    #[DataProvider('signedInViewers')]
    public function testTheAccountMenu(string $role): void
    {
        $browser = self::createClient();
        $playerId = NavigationViewer::signIn($browser, $role);
        self::assertNotNull($playerId);
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $account = $crawler->filter('body > header.site-header .site-header-account');
        self::assertCount(1, $account, $role);
        self::assertStringContainsString('dropdown', (string) $account->attr('class'));

        // The trigger: a Bootstrap dropdown toggle around the avatar, named, a top-bar tool
        $trigger = $account->filter('button.nav-account-trigger');
        self::assertCount(1, $trigger);
        self::assertSame('dropdown', $trigger->attr('data-bs-toggle'));
        self::assertSame('outside', $trigger->attr('data-bs-auto-close'), 'Opening Language keeps the menu open');
        self::assertSame('false', $trigger->attr('aria-expanded'));
        self::assertSame('Account menu', $trigger->attr('aria-label'));
        self::assertSame('topbar/profile/account', $trigger->attr('data-nav'));
        self::assertCount(1, $trigger->filter('.lb-avatar'));

        // name + #code from the profile row
        $player = $this->player($playerId);
        $head = $account->filter('.dropdown-menu .nav-account-head');
        self::assertCount(1, $head);
        self::assertSame('#' . strtoupper($player['code']), trim($head->filter('.nav-account-code')->text()));
        self::assertSame($player['name'], trim($head->filter('.nav-account-name')->text()));

        // View profile · Settings · Membership (+ status) · Language · Log out - and nothing else: no role links
        $links = $account->filter('.dropdown-menu a');
        $navIds = $links->each(static fn (Crawler $link): string => (string) $link->attr('data-nav'));
        self::assertSame([
            'account/links/view_profile',
            'account/links/settings',
            'account/links/membership',
            'account/language/de',
            'account/language/cs',
            'account/language/fr',
            'account/language/es',
            'account/language/ja',
            'account/links/logout',
        ], $navIds);

        $hrefs = array_combine($navIds, $links->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSame($router->generate('player_profile', ['playerId' => $playerId]), $hrefs['account/links/view_profile']);
        self::assertSame($router->generate('edit_profile'), $hrefs['account/links/settings']);
        self::assertSame($router->generate('membership'), $hrefs['account/links/membership']);
        self::assertSame($router->generate('logout'), $hrefs['account/links/logout']);
        self::assertSame($router->generate('faq', ['_locale' => 'de']), $hrefs['account/language/de']);
        self::assertSame($router->generate('faq', ['_locale' => 'ja']), $hrefs['account/language/ja']);

        foreach ($hrefs as $navId => $href) {
            self::assertStringNotContainsString('/admin', $href, $role . ': ' . $navId . ' - role links live in the Menu (MENU-01)');
        }

        self::assertSame(['View profile', 'Settings', 'Log out'], [
            trim($links->eq(0)->text()),
            trim($links->eq(1)->text()),
            trim($links->last()->text()),
        ]);

        // Log out leaves Turbo: the session ends, the next page loads anew
        self::assertSame('false', $links->last()->attr('data-turbo'));

        // Membership carries the status line of the Menu's You card (the same partial)
        $status = trim($account->filter('.nav-account-membership .nav-account-status')->text());
        self::assertNotSame('', $status);
        self::assertSame(trim($crawler->filter('#site-menu .menu-you-status')->text()), $status);

        // Language: a <details> (decision 24) naming the current language, the page in the other five - the switch the
        // footer shares (_language_menu.html.twig, data-nav account/language/<locale>)
        $language = $account->filter('details.nav-account-language');
        self::assertCount(1, $language);
        self::assertSame('English', trim($language->filter('summary .nav-account-status')->text()));
        self::assertSame(['de', 'cs', 'fr', 'es', 'ja'], $language->filter('a[data-locale-preference-locale-param]')->each(static fn (Crawler $link): string => (string) $link->attr('data-locale-preference-locale-param')));

        // Role links stay in the Menu (Your roles) for those who hold them
        if ($role !== NavigationViewer::MEMBER) {
            self::assertGreaterThan(0, $crawler->filter('#site-menu a[href^="/admin"]')->count(), $role . ': the Menu holds the role links');
        }

        // Chrome markup rules (implementation plan §3.2): never a .more-menu, no heading, no form
        self::assertCount(0, $account->filter('.more-menu, h1, h2, h3, h4, h5, h6, form'));

        foreach ($account->filter('a, button') as $element) {
            assert($element instanceof DOMElement);
            self::assertMatchesRegularExpression('~^(account/links|account/language|topbar/profile)/[a-z0-9_]+$~', $element->getAttribute('data-nav'));
        }
    }

    public function testTheCurrentPagesRowIsMarked(): void
    {
        $browser = self::createClient();
        $playerId = NavigationViewer::signIn($browser, NavigationViewer::MEMBER);
        self::assertNotNull($playerId);
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', $router->generate('player_profile', ['playerId' => $playerId]));
        self::assertResponseIsSuccessful();
        self::assertSame(['account/links/view_profile'], $crawler->filter('.site-header .nav-account-menu [aria-current="page"]')->each(static fn (Crawler $link): string => (string) $link->attr('data-nav')));

        $crawler = $browser->request('GET', $router->generate('edit_profile'));
        self::assertResponseIsSuccessful();
        self::assertSame(['account/links/settings'], $crawler->filter('.site-header .nav-account-menu [aria-current="page"]')->each(static fn (Crawler $link): string => (string) $link->attr('data-nav')));

        // Somebody else's profile is not yours
        $crawler = $browser->request('GET', $router->generate('player_profile', ['playerId' => NavigationViewer::playerId(NavigationViewer::ADMIN)]));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.site-header .nav-account-menu [aria-current]'));
    }

    public function testGuestsGetNoAccountMenu(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.site-header-account, .nav-account-trigger, .nav-account-menu'));
    }

    /**
     * @return array{name: string, code: string}
     */
    private function player(string $playerId): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative('SELECT name, code FROM player WHERE id = :id', ['id' => $playerId]);
        self::assertIsArray($row);
        self::assertIsString($row['name']);
        self::assertIsString($row['code']);

        return ['name' => $row['name'], 'code' => $row['code']];
    }
}
