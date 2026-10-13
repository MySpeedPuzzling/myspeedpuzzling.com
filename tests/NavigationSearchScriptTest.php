<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Navigation\NavigationSearchIndex;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * "Pages & settings" in the page search (docs/features/navigation README §4.12 "Search", MENU-03): the real
 * nav-search.json of every locale (NavigationSearchIndex) searched by assets/navigation/nav_search.js under node
 * (tests/nav-search-harness.mjs) - the first result of typical queries per locale, the audiences (a guest never gets a
 * member's page; moderator and admin entries come only from the page of those viewers), the kana and apostrophe layer
 * and the zero-results report.
 */
final class NavigationSearchScriptTest extends WebTestCase
{
    private const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    // A signed-in member who organises events and sees XP (no karma, no private profile)
    private const array MEMBER_ORGANISER = ['everyone', 'signed_in', 'member', 'member_or_referral', 'xp_visible', 'xp_player', 'xp_participant', 'achievements_shown', 'organiser'];
    private const array PLAYER = ['everyone', 'signed_in', 'xp_visible', 'xp_player', 'xp_participant', 'achievements_shown'];
    private const array GUEST = ['everyone', 'guest'];

    /** @var null|array{cases: array<string, array{ids: list<string>, tiers: list<int>, signIn: list<string>}>, rules: array<string, mixed>} */
    private static null|array $results = null;

    /** @var list<string> the admin page's own <body data-viewer-audience> */
    private static array $adminAudiences = [];

    /**
     * locale => [query, the expected first entry]; at least 8 per locale (MENU-03 Verify), signed in as a member who
     * organises events
     *
     * @return array<string, array<string, string>>
     */
    private static function firstResults(): array
    {
        return [
            'en' => [
                'export' => 'your_data',
                'password' => 'change_password',
                'ranking' => 'leaderboards',
                'my results' => 'results',
                'premium' => 'membership',
                'organiser' => 'organized_events',
                'duplicates' => 'review_results',
                'edit profile' => 'settings',
                'wish' => 'wishlist',
                'stopwatch' => 'stopwatch',
                // An apostrophe never matters: "country’s" = "country's" = "countrys"
                'country’s' => 'country_ranking',
                'find players' => 'puzzlers',
                // One Password result for everybody (review F4): its synonyms say "add" and "set"
                'add a password' => 'change_password',
                'set a password' => 'change_password',
            ],
            'cs' => [
                'export' => 'your_data',
                'heslo' => 'change_password',
                'pořadí' => 'leaderboards',
                'moje výsledky' => 'results',
                'prémium' => 'membership',
                'pořadatel' => 'organized_events',
                'duplicity' => 'review_results',
                'upravit profil' => 'settings',
                'přání' => 'wishlist',
                'stopky' => 'stopwatch',
                'přidat heslo' => 'change_password',
            ],
            'de' => [
                'export' => 'your_data',
                'passwort' => 'change_password',
                'rangliste' => 'leaderboards',
                'meine ergebnisse' => 'results',
                'premium' => 'membership',
                'organisator' => 'organized_events',
                'duplikate' => 'review_results',
                'profil bearbeiten' => 'settings',
                // Wunschliste before "Ideen & Feature-Vorschläge", whose synonyms say Wünsche
                'Wunsch' => 'wishlist',
                'stoppuhr' => 'stopwatch',
                'passwort hinzufügen' => 'change_password',
            ],
            'es' => [
                'exportar' => 'your_data',
                'contraseña' => 'change_password',
                'clasificación' => 'leaderboards',
                'mis resultados' => 'results',
                'premium' => 'membership',
                'mis eventos' => 'organized_events',
                'duplicados' => 'review_results',
                'editar perfil' => 'settings',
                'deseos' => 'wishlist',
                'cronómetro' => 'stopwatch',
                'añadir una contraseña' => 'change_password',
            ],
            'fr' => [
                'exporter' => 'your_data',
                'mot de passe' => 'change_password',
                'classement' => 'leaderboards',
                'mes résultats' => 'results',
                'premium' => 'membership',
                'mes événements' => 'organized_events',
                'doublons' => 'review_results',
                'modifier le profil' => 'settings',
                'souhaits' => 'wishlist',
                // An apostrophe never matters: "d’activité" finds "Calendrier d'activité"
                'd’activité' => 'calendar',
                'ajouter un mot de passe' => 'change_password',
            ],
            'ja' => [
                'エクスポート' => 'your_data',
                'パスワード' => 'change_password',
                'ランキング' => 'leaderboards',
                '記録' => 'results',
                'プレミアム' => 'membership',
                '主催' => 'organized_events',
                '重複' => 'review_results',
                'プロフィール編集' => 'settings',
                'ウィッシュ' => 'wishlist',
                // Katakana typed as hiragana or in half-width forms
                'ぱすわーど' => 'change_password',
                'ﾊﾟｽﾜｰﾄﾞ' => 'change_password',
                'パスワードを追加' => 'change_password',
            ],
        ];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideFirstResults(): iterable
    {
        foreach (self::firstResults() as $locale => $queries) {
            foreach ($queries as $query => $id) {
                yield $locale . ' ' . $query => [$locale, $query, $id];
            }
        }
    }

    #[DataProvider('provideFirstResults')]
    public function testTheExpectedPageComesFirst(string $locale, string $query, string $id): void
    {
        $case = $this->case('first ' . $locale . ' ' . $query);

        self::assertNotSame([], $case['ids'], sprintf('%s "%s": nothing found', $locale, $query));
        self::assertSame($id, $case['ids'][0], sprintf('%s "%s": %s', $locale, $query, implode(', ', array_slice($case['ids'], 0, 5))));
    }

    public function testEveryLocaleHasAtLeastEightQueries(): void
    {
        foreach (self::firstResults() as $locale => $queries) {
            self::assertGreaterThanOrEqual(8, count($queries), $locale);
        }

        self::assertSame(self::LOCALES, array_keys(self::firstResults()));
    }

    /**
     * Every entry is found by its own label in its own locale - never shadowed by an entry another audience sees
     */
    public function testEveryEntryIsFoundByItsLabel(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach ($this->index()->entries($locale) as $entry) {
                $case = $this->case('label ' . $locale . ' ' . $entry['id'] . ' ' . $entry['a']);

                self::assertContains($entry['id'], $case['ids'], sprintf('%s: "%s" does not find %s', $locale, $entry['l'], $entry['id']));
            }
        }
    }

    /**
     * Each viewer sees only what they may open: a guest never gets a signed-in or member page, a player never a
     * member's; the public list holds no moderator / admin entry at all
     */
    public function testEachViewerGetsOnlyTheirEntries(): void
    {
        $registry = self::getContainer()->get(NavigationRegistry::class);

        foreach (['guest' => self::GUEST, 'player' => self::PLAYER] as $viewer => $audiences) {
            foreach (self::LOCALES as $locale) {
                $ids = $this->case($viewer . ' everything ' . $locale)['ids'];
                self::assertGreaterThan(20, count($ids), $viewer . ' ' . $locale);

                foreach ($ids as $id) {
                    $entry = $registry->byId($id);
                    self::assertNotNull($entry);

                    if ($viewer === 'guest') {
                        // ... or the guests' own half of it (What next?: its landing - SRCH+FOOT review F1)
                        self::assertTrue($entry->visibility->includesGuests() || $entry->target->guestRoute !== null, sprintf('%s: a guest gets %s (%s)', $locale, $id, $entry->visibility->value));
                    }

                    self::assertNotSame('member', $entry->visibility->value, sprintf('%s: a %s gets the member page %s', $locale, $viewer, $id));
                }
            }
        }

        self::assertSame([], $this->case('guest password')['ids']);
        self::assertSame(['player_code'], $this->case('member player code')['ids']);
        self::assertSame([], $this->case('player player code')['ids']);
        // The guests' Hub link stays findable (R11), signed-in players have it as Home
        self::assertSame('hub_guest', $this->case('guest hub')['ids'][0]);
        self::assertNotContains('hub_guest', $this->case('member hub')['ids']);
        // Top bar for guests (SRCH-01): a signed-in page offered as "Sign in to …" - one Password result (review F4: no
        // separate "Add a password" row, its words are change_password's synonyms)
        $signIn = $this->case('guest password, sign in')['signIn'];
        self::assertSame('change_password', $signIn[0] ?? null);
        self::assertNotContains('set_password', $signIn);
    }

    public function testThePublicListHoldsNoModeratorOrAdminEntry(): void
    {
        foreach (self::LOCALES as $locale) {
            $json = $this->index()->json($locale);

            foreach ($this->index()->entries($locale) as $entry) {
                self::assertNotContains($entry['a'], ['moderator', 'time_verifier', 'admin'], $locale . ' ' . $entry['id']);
                self::assertDoesNotMatchRegularExpression('~^(mod|admin)_~', (string) $entry['id'], $locale);
            }

            self::assertStringNotContainsString('/admin/', $json, $locale);
        }
    }

    /**
     * Moderator and admin entries reach only their pages (#nav-search-private) - and are found from there, with the
     * audiences that page really renders (<body data-viewer-audience> holds no role audience - review F1)
     */
    public function testAnAdminFindsTheAdminEntriesOfTheirPage(): void
    {
        $results = $this->results();

        self::assertSame('admin_vouchers', $results['cases']['admin vouchers']['ids'][0] ?? null);
        self::assertSame('mod_time_verification', $results['cases']['admin time verification']['ids'][0] ?? null);
        self::assertNotContains('admin_vouchers', $results['cases']['member vouchers']['ids']);
    }

    public function testKatakanaAndHiraganaAreOneScript(): void
    {
        $rules = $this->results()['rules'];

        self::assertSame(['ぱすわーど', 'ゔぁいおりん ゝゞ', 'Hello ひらがな ー'], $rules['hiragana']);
        self::assertSame(['ぱすわーど', 'ぱすわーど', 'ぱすわーど'], $rules['foldedKana']);
        self::assertSame([['password'], ['password'], ['password'], ['password'], ['password']], $rules['kanaFinds']);
    }

    public function testApostrophesNeverMatter(): void
    {
        self::assertSame(['wheres', 'wheres', 'wheres', ['wally'], ['wally'], ['wally']], $this->results()['rules']['apostrophes']);
    }

    public function testTheRankingAndTheAudiences(): void
    {
        $rules = $this->results()['rules'];

        // "wunsch" starts the label "Wunschliste" (wishlist) and a synonym of "Ideen" - the label wins although Ideen
        // comes first in the Menu; "liste" is a substring of the compound
        self::assertSame([['wishlist', 'ideas'], ['wishlist'], ['wishlist'], 0, 1, 2, 3], $rules['ranking']);
        self::assertSame([[], ['members'], ['everyone', 'guest'], ['everyone', 'signed_in', 'member']], $rules['audience']);
        self::assertSame([[], [], []], $rules['nothing']);
        self::assertSame(['Account › Settings', 'Account', '', ''], $rules['location']);
    }

    /**
     * SRCH+FOOT review F3: the top bar's line names this screen's Menu - [phone, desktop guest, desktop signed in] -
     * and nothing for an entry a bar of that screen offers (the tabs on phones, the Inbox tool, the account menu)
     */
    public function testThePathLineOfEachScreen(): void
    {
        $rules = $this->results()['rules'];

        self::assertSame([
            'home' => ['', '', ''],
            'inbox' => ['', '', ''],
            'settings' => ['☰ Menu › Account', '☰ Menu › Account', ''],
            'logout' => ['☰ Menu', '', ''],
            'password' => ['☰ Menu › Account › Settings', '☰ Menu › Account › Settings', '☰ Menu › Account › Settings'],
            'hub_guest' => ['', '☰ Menu › Home', '☰ Menu › Home'],
        ], $rules['screenPaths']);
        self::assertSame([true, false, false, true, false], $rules['onBar']);
    }

    /**
     * SRCH+FOOT review F1/Q6: a guest is offered a page through sign-in only when its link asks for it (`s`), for a
     * signed-in player's audience (the XP pages while XP is visible to guests) - [guest, guest with XP visible]
     */
    public function testWhatAGuestIsOfferedThroughSignIn(): void
    {
        self::assertSame([
            'marked signed_in' => [true, true],
            'unmarked signed_in' => [false, false],
            'marked xp_player' => [false, true],
            'marked achievements_shown' => [false, true],
            'marked member' => [false, false],
            'marked karma_holder' => [false, false],
            'marked with an action' => [false, false],
            'marked, the guest has its own half' => [false, false],
        ], $this->results()['rules']['signInOffers']);

        // The real lists (top bar, guest): What next? is the guests' own row, Where did it go? never theirs, Send feedback
        // the Help sheet's plain row, the XP pages through sign-in
        foreach (self::LOCALES as $locale) {
            $whatNext = $this->case('guest what next ' . $locale);
            self::assertSame('what_next', $whatNext['ids'][0] ?? null, $locale);
            self::assertNotContains('what_next', $whatNext['signIn'], $locale);
            self::assertNotContains('where_did_it_go', $this->case('guest where did it go ' . $locale)['ids'], $locale);
            $feedback = $this->case('guest feedback ' . $locale);
            self::assertContains('send_feedback', $feedback['ids'], $locale);
            self::assertNotContains('send_feedback', $feedback['signIn'], $locale);
            self::assertContains('level_xp', $this->case('guest level ' . $locale)['signIn'], $locale);
            self::assertNotContains('level_xp', $this->case('guest level, XP hidden ' . $locale)['ids'], $locale);
        }

        // Q1: the old footer's "What should I solve next?" finds What next?
        self::assertSame('what_next', $this->case('guest what should I solve next')['ids'][0] ?? null);
    }

    public function testTheZeroResultsReport(): void
    {
        $rules = $this->results()['rules'];

        self::assertSame(['unicode query', 40], $rules['zeroQuery']);
        self::assertSame([
            'reported' => true,
            'short' => false,
            'pageHas' => false,
            'serverHas' => false,
            'again' => false,
            'cap' => false,
        ], $rules['zero']);
    }

    /**
     * Review F2: nav-search.json that cannot be loaded (a 503 during a deploy, offline, a captive portal's HTML page) with
     * a query typed before and after the answer: one fetch per focus or keystroke after a failure - never by itself -
     * and update() runs once per keystroke (the old code looped forever on a non-2xx answer, and refetched in a loop
     * after a network error)
     */
    public function testAListThatCannotBeLoadedNeverLoops(): void
    {
        $lists = $this->results()['rules']['lists'];
        self::assertIsArray($lists);

        foreach (['status503', 'networkError', 'notJson'] as $failure) {
            self::assertSame([
                'beforeAnswer' => ['fetches' => 1, 'updates' => 2],
                'typed' => ['fetches' => 2, 'updates' => 4],
                'idle' => ['fetches' => 2, 'updates' => 4],
                'searched' => 0,
                'kept' => false,
            ], $lists[$failure], $failure);
        }

        // A list that arrives: fetched once, the field re-renders with it once, then searches it on every keystroke
        self::assertSame(['fetches' => 1, 'updates' => 4, 'searched' => 3], $lists['loaded']);
    }

    /**
     * Review F10: during a blue-green rollout the old deploy may answer the new deploy's ?v= with its own list - it is
     * used, but kept only as long as its max-age (60 s), then the right list is asked for again; this deploy's own list
     * stays for the whole tab
     */
    public function testAListOfAnotherDeployIsKeptBriefly(): void
    {
        $lists = $this->results()['rules']['lists'];
        self::assertIsArray($lists);

        self::assertSame([
            'used' => true,
            'beforeExpiry' => true,
            'atExpiry' => false,
            'fetches' => 2,
            'refetchedVersion' => 'new',
            'thisDeployKept' => true,
        ], $lists['otherVersion']);
        self::assertSame(['abc123', null, 'z'], $lists['askedVersion']);
    }

    /**
     * @return array{ids: list<string>, tiers: list<int>, signIn: list<string>}
     */
    private function case(string $name): array
    {
        $cases = $this->results()['cases'];
        self::assertArrayHasKey($name, $cases);

        return $cases[$name];
    }

    private function index(): NavigationSearchIndex
    {
        return self::getContainer()->get(NavigationSearchIndex::class);
    }

    /**
     * @return array{cases: array<string, array{ids: list<string>, tiers: list<int>, signIn: list<string>}>, rules: array<string, mixed>}
     */
    private function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

        // The admin's page carries the private entries (the public list never does)
        self::ensureKernelShutdown();
        $browser = self::createClient();
        NavigationViewer::signIn($browser, NavigationViewer::ADMIN);
        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();
        $private = $crawler->filter('#site-menu > script#nav-search-private');
        self::assertCount(1, $private);
        $privateEntries = json_decode($private->text(), true, flags: JSON_THROW_ON_ERROR);
        // The audiences the admin's page really renders: the browser filters by these, never by role audiences
        $adminAudiences = preg_split('/\s+/', trim((string) $crawler->filter('body')->attr('data-viewer-audience')), flags: PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($adminAudiences);
        self::assertContains('signed_in', $adminAudiences);
        self::$adminAudiences = $adminAudiences;

        $lists = [];
        $cases = [];

        foreach (self::LOCALES as $locale) {
            $lists[$locale] = json_decode($this->index()->json($locale), true, flags: JSON_THROW_ON_ERROR);

            foreach ($this->index()->entries($locale) as $entry) {
                $cases[] = ['name' => 'label ' . $locale . ' ' . $entry['id'] . ' ' . $entry['a'], 'locale' => $locale, 'query' => $entry['l'], 'audiences' => [$entry['a'], 'everyone']];
            }

            $cases[] = ['name' => 'guest everything ' . $locale, 'locale' => $locale, 'query' => '', 'audiences' => self::GUEST, 'all' => true];
            $cases[] = ['name' => 'player everything ' . $locale, 'locale' => $locale, 'query' => '', 'audiences' => self::PLAYER, 'all' => true];
        }

        foreach (self::firstResults() as $locale => $queries) {
            foreach (array_keys($queries) as $query) {
                $cases[] = ['name' => 'first ' . $locale . ' ' . $query, 'locale' => $locale, 'query' => (string) $query, 'audiences' => self::MEMBER_ORGANISER];
            }
        }

        $cases[] = ['name' => 'guest password', 'locale' => 'en', 'query' => 'password', 'audiences' => self::GUEST];
        $cases[] = ['name' => 'guest password, sign in', 'locale' => 'en', 'query' => 'password', 'audiences' => self::GUEST, 'guestSignIn' => true];
        $cases[] = ['name' => 'member player code', 'locale' => 'en', 'query' => 'player code', 'audiences' => self::MEMBER_ORGANISER];
        $cases[] = ['name' => 'player player code', 'locale' => 'en', 'query' => 'player code', 'audiences' => self::PLAYER];
        $cases[] = ['name' => 'guest hub', 'locale' => 'en', 'query' => 'hub', 'audiences' => self::GUEST];
        $cases[] = ['name' => 'member hub', 'locale' => 'en', 'query' => 'hub', 'audiences' => self::MEMBER_ORGANISER];
        $cases[] = ['name' => 'admin vouchers', 'locale' => 'en', 'query' => 'vouchers', 'audiences' => self::$adminAudiences, 'withPrivate' => true];
        $cases[] = ['name' => 'admin time verification', 'locale' => 'en', 'query' => 'time verification', 'audiences' => self::$adminAudiences, 'withPrivate' => true];
        $cases[] = ['name' => 'member vouchers', 'locale' => 'en', 'query' => 'vouchers', 'audiences' => self::MEMBER_ORGANISER];
        $cases[] = ['name' => 'guest what should I solve next', 'locale' => 'en', 'query' => 'what should I solve next', 'audiences' => self::GUEST, 'guestSignIn' => true];

        // SRCH+FOOT review F1/Q6, typed by each locale's labels: the top bar of a guest who sees XP (and one who does not)
        foreach (self::LOCALES as $locale) {
            $labels = array_column($this->index()->entries($locale), 'l', 'id');
            $label = static fn (string $id): string => (string) ($labels[$id] ?? $id);
            $xpGuest = [...self::GUEST, 'xp_visible'];

            foreach (['what next' => 'what_next', 'where did it go' => 'where_did_it_go', 'feedback' => 'send_feedback', 'level' => 'level_xp'] as $name => $id) {
                $cases[] = ['name' => 'guest ' . $name . ' ' . $locale, 'locale' => $locale, 'query' => $label($id), 'audiences' => $xpGuest, 'guestSignIn' => true];
            }

            $cases[] = ['name' => 'guest level, XP hidden ' . $locale, 'locale' => $locale, 'query' => $label('level_xp'), 'audiences' => self::GUEST, 'guestSignIn' => true];
        }

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/nav-search-harness.mjs']);
        $process->setInput(json_encode(['lists' => $lists, 'private' => $privateEntries, 'cases' => $cases], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array{cases: array<string, array{ids: list<string>, tiers: list<int>, signIn: list<string>}>, rules: array<string, mixed>} $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
