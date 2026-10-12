<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Controller\NavigationSearchIndexController;
use SpeedPuzzling\Web\Navigation\NavigationSearchIndex;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The page search's "Pages & settings" list (route nav_search_index, docs/features/navigation README §4.12 "Search",
 * MENU-03): one public JSON per locale - the same for a guest and a signed-in player (the `stateless` firewall: no
 * session, no cookie, no statement), cached for a day under its version. The browser filters it by the page's
 * audiences; moderator and admin entries are never in it.
 */
final class NavigationSearchIndexControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const array LOCALES = ['cs', 'de', 'en', 'es', 'fr', 'ja'];

    public function testTheListHasItsShape(): void
    {
        $browser = self::createClient();
        $data = $this->fetch($browser, '/en/nav-search.json');

        self::assertSame(['v', 'groups', 'entries'], array_keys($data));
        self::assertIsString($data['v']);
        self::assertMatchesRegularExpression('~^[0-9a-f]{12}$~', $data['v']);
        self::assertIsArray($data['groups']);
        self::assertSame('Account', $data['groups']['account'] ?? null);
        self::assertIsArray($data['entries']);
        self::assertGreaterThan(80, count($data['entries']));

        /** @var list<array<string, int|string>> $entries */
        $entries = $data['entries'];
        $byId = [];

        foreach ($entries as $entry) {
            foreach (['id', 'l', 'g', 'p', 'u', 'a', 'i', 'o', 'k'] as $field) {
                self::assertArrayHasKey($field, $entry, json_encode($entry, JSON_THROW_ON_ERROR));
            }

            $byId[$entry['id'] . '|' . $entry['a']] = $entry;
        }

        // A setting found by search: its label, where it lives in the Menu, its link and its keys (folded, label first)
        $password = $byId['change_password|signed_in'];
        self::assertSame('Password', $password['l']);
        self::assertSame('☰ › Account › Settings › Password', $password['p']);
        self::assertSame('account', $password['g']);
        self::assertSame($this->router()->generate('change_account_password', ['_locale' => 'en']), $password['u']);
        self::assertIsString($password['k']);
        self::assertSame('password', explode("\n", $password['k'])[0]);
        self::assertContains('forgot password', explode("\n", $password['k']));

        // A page of the viewer's own goes through nav_go (the list is the same for everybody, R14)
        self::assertSame('/en/go/your_data', $byId['your_data|signed_in']['u']);
        self::assertSame('Export your data · delete account', $byId['your_data|signed_in']['d']);
        self::assertContains('export data', explode("\n", (string) $byId['your_data|signed_in']['k']));
        // An entry with a guests' route: one half for signed-in players, one for guests
        self::assertSame('/en/hub', $byId['home|signed_in']['u']);
        self::assertSame('/', $byId['home|guest']['u']);
        // What the link does besides navigating
        self::assertSame('help', $byId['send_feedback|signed_in']['x']);
        self::assertSame('/en/menu#nav-language', $byId['language|everyone']['u']);
        self::assertSame('language', $byId['language|everyone']['x']);
        self::assertSame('logout', $byId['logout|signed_in']['x']);
        // The guests' Hub link stays findable (R11, NAV-10)
        self::assertSame('/en/hub', $byId['hub_guest|guest']['u']);

        // In the Menu's order: a search-only entry right after its parent
        $ids = array_column($entries, 'id');
        self::assertSame(array_search('settings', $ids, true) + 1, array_search('change_password', $ids, true));
        $orders = array_column($entries, 'o');
        $sorted = $orders;
        sort($sorted);
        self::assertSame($sorted, $orders);
    }

    public function testEveryLocaleHasItsList(): void
    {
        $browser = self::createClient();

        foreach (self::LOCALES as $locale) {
            $data = $this->fetch($browser, '/' . $locale . '/nav-search.json');
            /** @var list<array<string, int|string>> $entries */
            $entries = $data['entries'];
            self::assertStringStartsWith('/', (string) $entries[0]['u'], $locale);
        }

        $de = $this->fetch($browser, '/de/nav-search.json');
        self::assertSame('Konto', is_array($de['groups']) ? $de['groups']['account'] : null);

        $browser->request('GET', '/xx/nav-search.json');
        self::assertResponseStatusCodeSame(404);
    }

    public function testItIsPublicWithoutSessionCookieOrStatement(): void
    {
        $browser = self::createClient();
        $url = $this->versionedUrl('en');

        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->queryCount($browser), "nav-search.json ran:\n" . implode("\n", $this->executedSql($browser)));

        $this->assertPublicDayLong($browser);
        self::assertSame('application/json', $browser->getResponse()->headers->get('Content-Type'));
    }

    public function testASignedInRequestGetsTheSamePublicList(): void
    {
        $browser = self::createClient();
        $guest = $this->fetch($browser, '/en/nav-search.json');

        NavigationViewer::signIn($browser, NavigationViewer::ADMIN);
        $browser->request('GET', $this->versionedUrl('en'));
        self::assertResponseIsSuccessful();
        $this->assertPublicDayLong($browser);

        self::assertSame($guest, json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('admin_', (string) $browser->getResponse()->getContent());
    }

    public function testAnotherDeploysVersionIsCachedBriefly(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/nav-search.json?v=000000000000');

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=60', $cacheControl);
    }

    /**
     * The page names the list with its version (`?v=`) and never inlines it
     */
    public function testThePageLinksTheVersionedList(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', $this->router()->generate('faq', ['_locale' => 'de']));
        self::assertResponseIsSuccessful();

        $url = $this->versionedUrl('de');
        self::assertSame($url, $crawler->filter('[data-live-name-value="GlobalSearch"]')->first()->attr('data-nav-search-url-value'));
        self::assertStringNotContainsString('"entries":', (string) $browser->getResponse()->getContent());
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get(RouterInterface::class);
    }

    private function versionedUrl(string $locale): string
    {
        return '/' . $locale . '/nav-search.json?v=' . self::getContainer()->get(NavigationSearchIndex::class)->version($locale);
    }

    private function assertPublicDayLong(KernelBrowser $browser): void
    {
        $response = $browser->getResponse();
        $cacheControl = (string) $response->headers->get('Cache-Control');

        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=' . NavigationSearchIndexController::MAX_AGE, $cacheControl);
        self::assertStringNotContainsString('private', $cacheControl);
        self::assertSame([], $response->headers->getCookies());
        self::assertFalse($response->headers->has('Set-Cookie'));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(KernelBrowser $browser, string $url): array
    {
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
