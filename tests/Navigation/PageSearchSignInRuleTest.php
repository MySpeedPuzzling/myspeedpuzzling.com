<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\Navigation\MenuSurface;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Navigation\NavigationSearchIndex;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The page search list's marks (docs/features/navigation SRCH-01, SRCH+FOOT review F1 and F3):
 * - `s` (a guest is asked to sign in by the link) is exactly what a guest gets: every marked link, requested as a guest,
 *   leads to sign-in; every link of a signed-in player's audience left unmarked opens without it (What next?'s landing,
 *   Where did it go?) - so the top bar never promises a sign-in that does not happen, and never misses one;
 * - `p` / `pd` are the entry's paths in the phone panel and the desktop Menu, `b` the bars that offer it
 *   (NavigationRegistry::ON_BARS).
 */
final class PageSearchSignInRuleTest extends WebTestCase
{
    // The audiences whose pages a guest may be offered as sign-in rows (assets/navigation/nav_search.js)
    private const array SIGN_IN_AUDIENCES = ['signed_in', 'xp_player', 'xp_participant', 'achievements_shown'];

    public function testEveryMarkedLinkAsksAGuestToSignInAndNoOtherDoes(): void
    {
        $browser = self::createClient();
        $index = self::getContainer()->get(NavigationSearchIndex::class);
        $marked = [];
        $unmarked = [];

        foreach ($index->entries('en') as $entry) {
            $url = (string) $entry['u'];

            if (($entry['s'] ?? null) === 1) {
                self::assertArrayNotHasKey('x', $entry, $entry['id'] . ': an action of its own is never a sign-in row');
                $marked[$entry['id']] = $url;
            } elseif (in_array($entry['a'], self::SIGN_IN_AUDIENCES, true) && !isset($entry['x'])) {
                $unmarked[$entry['id']] = $url;
            }
        }

        self::assertGreaterThan(20, count($marked));
        self::assertArrayHasKey('change_password', $marked);
        self::assertArrayHasKey('results', $marked, 'a page of the viewer\'s own (nav_go)');

        foreach ($marked as $id => $url) {
            $browser->request('GET', $url);
            $response = $browser->getResponse();
            self::assertTrue($response->isRedirect(), sprintf('%s (%s): %d', $id, $url, $response->getStatusCode()));
            self::assertStringStartsWith('/login', (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), $id . ' leads a guest to sign in');
        }

        // They open for guests: never "Sign in to see" - the signed-in halves of Home (the Hub; a guest has their own Home)
        // and What next? (its landing is the guests' own row), and Where did it go? (not offered to guests, R2)
        self::assertSame(['home', 'what_next', 'where_did_it_go'], array_keys($unmarked));

        foreach ($unmarked as $id => $url) {
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful($id . ' opens for a guest');
        }
    }

    public function testThePathsAndTheBarsOfEveryEntry(): void
    {
        self::bootKernel();
        $index = self::getContainer()->get(NavigationSearchIndex::class);
        $registry = self::getContainer()->get(NavigationRegistry::class);

        foreach (['en', 'cs'] as $locale) {
            foreach ($index->entries($locale) as $entry) {
                $id = (string) $entry['id'];
                $guestsHalf = $entry['a'] === 'guest' && !($registry->byId($id)?->visibility->includesGuests() ?? true);
                $phone = $guestsHalf ? '' : $registry->menuPathOn(MenuSurface::Phone, $id, $locale) ?? '';
                $desk = $guestsHalf ? '' : $registry->menuPathOn(MenuSurface::Dock, $id, $locale) ?? '';

                self::assertSame($phone, $entry['p'], $locale . ' ' . $id);
                self::assertSame($desk, $entry['pd'] ?? $entry['p'], $locale . ' ' . $id . ' (desktop)');
                self::assertSame(isset($entry['pd']), $desk !== $phone, $locale . ' ' . $id . ': pd only where it differs');

                $bars = implode('', array_map(static fn (string $bar): string => ['phone' => 'p', 'desktop' => 'd', 'account' => 'a'][$bar], NavigationRegistry::ON_BARS[$id] ?? []));
                self::assertSame($bars, $entry['b'] ?? '', $locale . ' ' . $id);
            }
        }

        $entries = array_column($index->entries('en'), null, 'id');
        // The review's rows: Home is a tab on phones (no panel row), Inbox and Log out are no desktop Menu rows
        self::assertSame(['', '☰ › Home', 'pd'], [$entries['home']['p'], $entries['home']['pd'] ?? null, $entries['home']['b']]);
        self::assertSame(['☰ › Inbox', '', 'pd'], [$entries['inbox']['p'], $entries['inbox']['pd'] ?? null, $entries['inbox']['b']]);
        self::assertSame(['☰ › Log out', '', 'a'], [$entries['logout']['p'], $entries['logout']['pd'] ?? null, $entries['logout']['b']]);
        self::assertSame('☰ › Account › Settings › Password', $entries['change_password']['p']);
        self::assertArrayNotHasKey('pd', $entries['change_password']);
    }
}
