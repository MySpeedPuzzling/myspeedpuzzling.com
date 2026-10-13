<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Navigation\NavigationSearchIndex;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The words of the page search (docs/features/navigation README §4.12 "Search", MENU-03): every entry the search lists
 * has its synonyms (nav.syn.<id>, `|`-separated, incl. today's old labels) and the search's own texts (nav.search.*) in
 * all 6 locales - never "rival", never "suspicious" (or a translation of it), not even hidden; and a moderator's or an
 * admin's entries reach only their own pages (#nav-search-private).
 */
final class NavigationSearchIndexTest extends WebTestCase
{
    private const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    // README §4.12 (the registry test's FORBIDDEN_SYNONYMS)
    private const string FORBIDDEN = '/rival|suspicious|verdächtig|podezřel|sospechos|suspect|不審/iu';

    // The same in every locale on purpose
    private const array SAME_AS_ENGLISH = ['nav.search.path_separator'];

    // Today's labels the README names (§4.12 "Synonyms"): each must find its page in English
    private const array OLD_LABELS = [
        'Hub' => 'home',
        'Puzzle database' => 'all_puzzles',
        'Players' => 'puzzlers',
        'Puzzle library' => 'library',
        'Favorite puzzlers' => 'favorites',
        'Activity calendar' => 'calendar',
        'My XP history' => 'level_xp',
        'Export data' => 'your_data',
        'Edit profile' => 'settings',
        'Latest Puzzle Times' => 'latest_times',
        'Scanning' => 'multiscan',
    ];

    public function testEveryListedEntryHasItsSynonymsInEveryLocale(): void
    {
        $translator = $this->translator();
        $listed = 0;

        foreach (self::getContainer()->get(NavigationRegistry::class)->all() as $entry) {
            if (!$entry->isInPageSearch()) {
                self::assertNull($entry->synonyms, $entry->id . ': only the entries the page search lists have synonyms');

                continue;
            }

            $listed++;
            self::assertSame(NavigationRegistry::SYNONYMS_PREFIX . $entry->id, $entry->synonyms);

            foreach (self::LOCALES as $locale) {
                self::assertTrue($translator->getCatalogue($locale)->defines($entry->synonyms), sprintf('%s: %s is missing', $locale, $entry->synonyms));

                foreach (explode('|', $translator->trans($entry->synonyms, [], null, $locale)) as $synonym) {
                    self::assertNotSame('', $synonym, sprintf('%s %s: an empty synonym', $locale, $entry->synonyms));
                    self::assertSame(trim($synonym), $synonym, sprintf('%s %s: "%s"', $locale, $entry->synonyms, $synonym));
                }
            }
        }

        self::assertGreaterThan(90, $listed);
    }

    /**
     * The README's list of today's labels: each one is a key of its page in English (as a legacy label or a synonym)
     */
    public function testTodaysLabelsAreSearchKeysOfTheirPages(): void
    {
        $translator = $this->translator();
        $registry = self::getContainer()->get(NavigationRegistry::class);

        foreach (self::OLD_LABELS as $label => $id) {
            $entry = $registry->byId($id);
            self::assertNotNull($entry, $id);

            $texts = array_map(static fn (string $key): string => $translator->trans($key, [], null, 'en'), $entry->legacyLabels);
            array_push($texts, ...explode('|', $translator->trans((string) $entry->synonyms, [], null, 'en')));
            $texts = array_map(mb_strtolower(...), $texts);

            self::assertContains(mb_strtolower($label), $texts, sprintf('"%s" is not a key of %s', $label, $id));
        }
    }

    /**
     * Grep of every synonym list (translations/messages.*.yml, nav.syn.*) and every legacy label the search uses
     */
    public function testNoSynonymSaysRivalOrSuspicious(): void
    {
        $values = 0;

        foreach (self::LOCALES as $locale) {
            foreach ($this->navKeys($locale, 'syn') as $key => $value) {
                $values++;
                self::assertDoesNotMatchRegularExpression(self::FORBIDDEN, $value, sprintf('%s nav.syn.%s', $locale, $key));
            }
        }

        self::assertGreaterThan(500, $values);
    }

    public function testNoStaleSynonymsAndNothingLeftInEnglish(): void
    {
        $registry = self::getContainer()->get(NavigationRegistry::class);
        $english = ['syn' => $this->navKeys('en', 'syn'), 'search' => $this->navKeys('en', 'search')];

        foreach (array_keys($english['syn']) as $id) {
            self::assertTrue($registry->byId($id)?->isInPageSearch() ?? false, sprintf('nav.syn.%s belongs to no entry the page search lists', $id));
        }

        self::assertSame(['pages_settings', 'sign_in_to_see', 'more', 'path_separator'], array_keys($english['search']));

        foreach (array_diff(self::LOCALES, ['en']) as $locale) {
            foreach ($english as $group => $keys) {
                $translated = $this->navKeys($locale, $group);
                self::assertSame(array_keys($keys), array_keys($translated), $locale . ' nav.' . $group);

                foreach ($keys as $key => $value) {
                    if (in_array('nav.' . $group . '.' . $key, self::SAME_AS_ENGLISH, true)) {
                        continue;
                    }

                    self::assertNotSame($value, $translated[$key], sprintf('%s nav.%s.%s is still English', $locale, $group, $key));
                }
            }
        }
    }

    /**
     * Outside debug the version names the deploy's container build (a new list ships only with a deploy), the locale and
     * the year (the archive entry), and a built list is cached under it
     */
    public function testOutsideDebugTheVersionNamesTheBuildAndTheListIsCached(): void
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $index = $container->get(NavigationSearchIndex::class);
        $buildId = $container->getParameter('container.build_id');
        self::assertIsString($buildId);
        self::assertNotSame('', $buildId);

        $year = $container->get(ClockInterface::class)->now()->format('Y');
        // FORMAT 2: the list carries each entry's desktop path, bars and sign-in mark (SRCH+FOOT review F1, F3)
        self::assertSame(substr(hash('xxh128', '2|' . $buildId . '|de|' . $year), 0, 12), $index->version('de'));
        self::assertNotSame($index->version('en'), $index->version('de'));

        $json = $index->json('de');
        self::assertSame($json, $index->json('de'));
        self::assertTrue($container->get('cache.app')->hasItem('nav_search_index.de.' . $index->version('de')));
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame($index->version('de'), $data['v'] ?? null);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => [NavigationViewer::GUEST, []];
        yield 'member' => [NavigationViewer::MEMBER, []];
        yield 'moderator' => [NavigationViewer::MODERATOR, ['mod_change_requests', 'mod_merge_requests', 'mod_puzzle_approvals', 'mod_time_verification']];
        yield 'admin' => [NavigationViewer::ADMIN, [
            'mod_change_requests', 'mod_merge_requests', 'mod_puzzle_approvals', 'mod_time_verification',
            'admin_competition_approvals', 'admin_duplicate_results', 'admin_oauth2_requests', 'admin_moderation',
            'admin_vouchers', 'admin_referrals', 'admin_email_audit', 'admin_moderators', 'admin_free_trial', 'admin_api_usage',
        ]];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideViewers')]
    public function testModeratorAndAdminEntriesReachOnlyTheirPages(string $role, array $expected): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $script = $crawler->filter('script#nav-search-private');

        if ($expected === []) {
            self::assertCount(0, $script);

            return;
        }

        // Inside the page's one #site-menu, first (it moves with the Menu)
        self::assertCount(1, $crawler->filter('#site-menu > script#nav-search-private:first-child'));
        self::assertSame('application/json', $script->attr('type'));

        /** @var list<array<string, int|string>> $entries */
        $entries = json_decode($script->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expected, array_column($entries, 'id'));

        foreach ($entries as $entry) {
            self::assertContains($entry['a'], ['moderator', 'time_verifier', 'admin']);
            self::assertStringStartsWith('/admin/', (string) $entry['u']);
        }

        // Escaped for a <script> element: no tag can end it early
        $source = $this->scriptSource((string) $browser->getResponse()->getContent());
        self::assertNotSame('', $source);
        self::assertStringNotContainsString('<', $source);
    }

    private function scriptSource(string $html): string
    {
        $marker = 'id="nav-search-private">';
        $start = strpos($html, $marker);
        self::assertIsInt($start);
        $start += strlen($marker);
        $end = strpos($html, '</script>', $start);
        self::assertIsInt($end);

        return substr($html, $start, $end - $start);
    }

    /**
     * The nav.<group>.* keys of a locale as written in its file (insertion order)
     *
     * @return array<string, string>
     */
    private function navKeys(string $locale, string $group): array
    {
        $data = Yaml::parseFile(dirname(__DIR__, 2) . '/translations/messages.' . $locale . '.yml');
        self::assertIsArray($data);
        $nav = $data['nav'] ?? null;
        self::assertIsArray($nav);
        $keys = $nav[$group] ?? null;
        self::assertIsArray($keys, $locale . ' nav.' . $group);

        /** @var array<string, string> $keys */
        return $keys;
    }

    private function translator(): TranslatorInterface&TranslatorBagInterface
    {
        return self::getContainer()->get(TranslatorInterface::class);
    }
}
