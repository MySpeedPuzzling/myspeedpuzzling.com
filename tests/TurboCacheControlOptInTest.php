<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Finder\Finder;

/**
 * Instant Back, step 2 (docs/features/navigation README §4.13 G9, requirement GEST-15, decision 99): the browse pages opt
 * in to Turbo's restore cache with <meta name="turbo-cache-control" content="no-preview"> (the `turbo_cache_control`
 * block of base.html.twig); every other page keeps `no-cache`.
 *
 * The guard holds as written: an opted-in page template - and everything it includes, embeds or renders, resolved
 * recursively here (`{% include %}`, `include()`, `{% embed %}`, `{% use %}`, `{% import %}` / `{% from %}`,
 * `<twig:X>` / `component('X')` → the component's template) - contains no POST form (a `<form method="post">` or a
 * `form_start()` without `method: 'GET'`), no `confirm(` and no `data-turbo-confirm`: a page restored by Back may be up to
 * 10 minutes old, its one-tap actions are links (PostAction). A template name this test cannot resolve (a variable)
 * fails it. base.html.twig and the chrome it includes are not part of a page's scope; the forms they hold are listed
 * below with the reason they may stay.
 */
final class TurboCacheControlOptInTest extends WebTestCase
{
    /** The opted-in page templates (README §4.13 G9's list) */
    public const array OPTED_IN_TEMPLATES = [
        'hub.html.twig',
        'recent_activity.html.twig',
        'puzzles.html.twig',
        'puzzle/brand_hub.html.twig',
        'puzzle/brand_pieces_hub.html.twig',
        'puzzle/brands_directory.html.twig',
        'puzzle/pieces_hub.html.twig',
        'ladder.html.twig',
        'ladder_per_pieces.html.twig',
        'msp_rating_ladder/index.html.twig',
        'xp_leaderboard.html.twig',
        'events.html.twig',
        'events/archive.html.twig',
        'competition_series_detail.html.twig',
        'players/index.html.twig',
        'players/directory.html.twig',
        'marketplace/index.html.twig',
        'player_profile.html.twig',
        'player_results.html.twig',
    ];

    /**
     * Forms of base.html.twig's chrome - on every page, opted in or not - and why they may stay in a cached copy
     */
    public const array BASE_EXCLUSIONS = [
        '_footer_newsletter.html.twig' => 'Guest newsletter form: no CSRF token, signing up twice is idempotent',
        '_footer.html.twig' => 'Newsletter "Turn it on" for a signed-in player: idempotent',
        'membership/_free_trial_start_form.html.twig' => 'In #membersExclusiveModal - closed by the cache work (instant_back.js); a trial starts once per player',
        'modals/announcements/_free_trial_offer.html.twig' => 'An announcement modal: removed from the cached copy (LAUNCH review Q2), shown once per player',
    ];

    private const string TEMPLATES = __DIR__ . '/../templates/';

    public function testExactlyTheBrowsePagesOptIn(): void
    {
        $optedIn = [];

        foreach (new Finder()->files()->in(self::TEMPLATES)->name('*.twig') as $file) {
            if (preg_match('~\{%-?\s*block\s+turbo_cache_control\s*-?%\}\s*no-preview\s*\{%~', $file->getContents()) === 1) {
                $optedIn[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        sort($optedIn);
        $expected = self::OPTED_IN_TEMPLATES;
        sort($expected);

        self::assertSame($expected, $optedIn);
    }

    public function testBaseRendersTheBlockWithNoCacheByDefault(): void
    {
        $base = (string) file_get_contents(self::TEMPLATES . 'base.html.twig');

        self::assertStringContainsString('<meta name="turbo-cache-control" content="{% block turbo_cache_control %}no-cache{% endblock %}">', $base);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function optedInTemplates(): iterable
    {
        foreach (self::OPTED_IN_TEMPLATES as $template) {
            yield $template => [$template];
        }
    }

    #[DataProvider('optedInTemplates')]
    public function testAnOptedInPageHoldsNoPostFormAndNoConfirmation(string $template): void
    {
        $scope = self::scope($template);
        $hits = [];

        foreach ($scope as $name => $source) {
            foreach (self::violations($source) as $violation) {
                $hits[] = $name . ': ' . $violation;
            }
        }

        self::assertSame([], $hits, $template . ' (with ' . count($scope) . ' templates in its scope)');
        self::assertGreaterThan(1, count($scope), 'the scope resolves the includes');
    }

    /** The guard sees what it guards: a POST form, a form_start(), a confirm in an included partial */
    public function testTheGuardFindsWhatItLooksFor(): void
    {
        self::assertSame(['a POST form'], self::violations('<FORM class="x"' . "\n" . ' method="Post" action="/en/x">'));
        self::assertSame(['form_start() posts by default'], self::violations('{{ form_start(form) }}'));
        self::assertSame([], self::violations("{{ form_start(form, {method: 'GET'}) }}"));
        self::assertSame(['confirm('], self::violations('<button onclick="return confirm(\'x\')">'));
        self::assertSame(['data-turbo-confirm'], self::violations('<form method="get" data-turbo-confirm="Sure?">'));
        self::assertSame([], self::violations('<form method="get" action="/en/action/follow">'));

        $scope = self::scope('player_profile.html.twig');
        self::assertArrayHasKey('components/PlayerHeader.html.twig', $scope, '<twig:PlayerHeader> resolves to its template');
        self::assertArrayHasKey('player/_header_actions_menu.html.twig', $scope, 'include() inside a component resolves');
        self::assertArrayHasKey('comparison_entry/_subject_form.html.twig', $scope, 'nested includes resolve');
        self::assertArrayHasKey('components/Players/CountryNudge.html.twig', $scope, 'a namespaced component resolves');
        self::assertArrayNotHasKey('base.html.twig', $scope, 'the layout is not part of a page');

        self::assertArrayHasKey('puzzle/_dropdown_actions.html.twig', self::scope('puzzles.html.twig'));
        self::assertArrayHasKey('event_parts/_draft_banner.html.twig', self::scope('competition_series_detail.html.twig'));
        self::assertArrayHasKey('marketplace/_listing_card.html.twig', self::scope('marketplace/index.html.twig'));
    }

    /** The base exclusions are really base's: no opted-in page reaches them through its own scope */
    public function testTheBaseExclusionsAreBaseChromeOnly(): void
    {
        $everyScope = [];

        foreach (self::OPTED_IN_TEMPLATES as $template) {
            $everyScope += self::scope($template);
        }

        foreach (array_keys(self::BASE_EXCLUSIONS) as $excluded) {
            self::assertFileExists(self::TEMPLATES . $excluded);
            self::assertArrayNotHasKey($excluded, $everyScope, $excluded . ' is listed as base chrome but a page includes it');
        }
    }

    /**
     * @return iterable<string, array{string, null|string, string}>
     */
    public static function renderedPages(): iterable
    {
        yield 'Hub (member)' => ['/en/hub', PlayerFixture::PLAYER_REGULAR, 'no-preview'];
        yield 'Recent activity' => ['/en/recent-activity', null, 'no-preview'];
        yield 'catalogue' => ['/en/puzzle', null, 'no-preview'];
        yield 'catalogue search (member)' => ['/en/puzzle?search=puzzle', PlayerFixture::PLAYER_REGULAR, 'no-preview'];
        yield 'brand' => ['/en/puzzle/brand/ravensburger', null, 'no-preview'];
        yield 'brands' => ['/en/puzzle/brands', null, 'no-preview'];
        yield 'pieces' => ['/en/puzzle/1000-pieces', null, 'no-preview'];
        yield 'ladder' => ['/en/ladder', null, 'no-preview'];
        yield 'ladder 500 pieces' => ['/en/ladder/solo/500-pieces', null, 'no-preview'];
        yield 'MSP rating' => ['/en/msp-rating', null, 'no-preview'];
        yield 'events' => ['/en/events', null, 'no-preview'];
        yield 'events archive' => ['/en/events/archive/{lastYear}', null, 'no-preview'];
        yield 'series' => ['/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG, null, 'no-preview'];
        yield 'players' => ['/en/puzzlers', null, 'no-preview'];
        yield 'players directory' => ['/en/puzzlers/all', null, 'no-preview'];
        yield 'marketplace' => ['/en/marketplace', null, 'no-preview'];
        yield 'profile (visitor)' => ['/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_FAVORITES, 'no-preview'];
        yield 'own profile' => ['/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_REGULAR, 'no-preview'];
        yield 'results' => ['/en/player-results/' . PlayerFixture::PLAYER_REGULAR, null, 'no-preview'];
        // A sample of every other page: no copy kept
        yield 'FAQ' => ['/en/faq', null, 'no-cache'];
        yield 'homepage' => ['/', null, 'no-cache'];
        yield 'add a time (member)' => ['/en/puzzle-add', PlayerFixture::PLAYER_REGULAR, 'no-cache'];
        yield 'notifications (member)' => ['/en/notifications', PlayerFixture::PLAYER_REGULAR, 'no-cache'];
        yield 'help' => ['/en/help', null, 'no-cache'];
    }

    #[DataProvider('renderedPages')]
    public function testThePageRendersItsCacheControl(string $url, null|string $player, string $expected): void
    {
        $browser = self::createClient();

        if ($player !== null) {
            TestingLogin::asPlayer($browser, $player);
        }

        $crawler = $browser->request('GET', $this->resolve($url));

        self::assertResponseIsSuccessful();
        self::assertSame($expected, $crawler->filter('head meta[name="turbo-cache-control"]')->attr('content'));
        self::assertCount(1, $crawler->filter('head meta[name="turbo-cache-control"]'));

        // Who the page was rendered for (GEST-14): signed-in viewers only - a guest's HTML is exactly what it was
        // (assets/instant_back.js knows a guest's page by <body data-viewer-audience>)
        $viewer = $crawler->filter('head meta[name="msp-viewer"]');

        if ($player === null) {
            self::assertCount(0, $viewer);
            self::assertStringContainsString('guest', (string) $crawler->filter('body')->attr('data-viewer-audience'));
        } else {
            self::assertCount(1, $viewer);
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $viewer->attr('content'));
        }
    }

    /**
     * Staleness (decision 65): the Favorite toggles are GET links that change state - for a signed-in viewer they clear
     * the snapshots (data-clears-snapshots); a guest's links (to the sign-in) stay exactly what they were
     */
    public function testTheFavoriteTogglesClearTheSnapshotsForSignedInViewersOnly(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href*="-player-to-favorites/"], a[href*="-player-from-favorites/"]')->count());
        self::assertCount(0, $crawler->filter('[data-clears-snapshots]'));

        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();
        $toggles = $crawler->filter('a[href*="-player-to-favorites/"], a[href*="-player-from-favorites/"]');
        self::assertGreaterThan(0, $toggles->count());
        self::assertCount($toggles->count(), $crawler->filter('a[href*="-player-to-favorites/"][data-clears-snapshots], a[href*="-player-from-favorites/"][data-clears-snapshots]'));
    }

    /**
     * A page whose GET wrote says so (<meta name="msp-clears-snapshots">, assets/instant_back.js clears the copies of
     * the pages before it): the notifications page while it marks something read - not once all is read - and the
     * stopwatch page (started, stopped or reset by GET links)
     */
    public function testAPageWhoseGetWroteSaysSo(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $marking = $browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $marking->filter('head meta[name="msp-clears-snapshots"]'));

        $allRead = $browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $allRead->filter('head meta[name="msp-clears-snapshots"]'));

        $stopwatch = $browser->request('GET', '/en/stopwatch');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $stopwatch->filter('head meta[name="msp-clears-snapshots"]'));

        // A browse page wrote nothing
        $faq = $browser->request('GET', '/en/puzzle');
        self::assertCount(0, $faq->filter('head meta[name="msp-clears-snapshots"]'));
    }

    /** Two signed-in accounts get two markers; one account always the same */
    public function testTheViewerMarkerTellsAccountsApart(): void
    {
        $markers = [];

        foreach ([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR] as $player) {
            self::ensureKernelShutdown();
            $browser = self::createClient();
            TestingLogin::asPlayer($browser, $player);
            $markers[] = (string) $browser->request('GET', '/en/faq')->filter('head meta[name="msp-viewer"]')->attr('content');
        }

        self::assertNotSame($markers[0], $markers[1]);
        self::assertSame($markers[0], $markers[2]);
    }

    private function resolve(string $url): string
    {
        if (str_contains($url, '{lastYear}')) {
            /** @var ClockInterface $clock */
            $clock = self::getContainer()->get(ClockInterface::class);

            return str_replace('{lastYear}', (string) ((int) $clock->now()->format('Y') - 1), $url);
        }

        return $url;
    }

    /**
     * What the guard forbids in one template's source
     *
     * @return list<string>
     */
    public static function violations(string $source): array
    {
        $found = [];

        if (preg_match('~<form\b[^>]*\bmethod\s*=\s*["\']?post\b~is', $source) === 1) {
            $found[] = 'a POST form';
        }

        if (preg_match_all('~form_start\(([^)]*)\)~', $source, $matches) > 0) {
            foreach ($matches[1] as $arguments) {
                if (preg_match('~method\s*:\s*[\'"]get[\'"]~i', $arguments) !== 1) {
                    $found[] = 'form_start() posts by default';
                }
            }
        }

        if (str_contains($source, 'confirm(')) {
            $found[] = 'confirm(';
        }

        if (str_contains($source, 'data-turbo-confirm')) {
            $found[] = 'data-turbo-confirm';
        }

        return $found;
    }

    /**
     * A page template and everything it includes, embeds or renders (never the layout it extends)
     *
     * @return array<string, string> template name => source
     */
    public static function scope(string $template): array
    {
        $scope = [];
        $todo = [$template];

        while ($todo !== []) {
            $name = array_shift($todo);

            if (isset($scope[$name])) {
                continue;
            }

            $path = self::TEMPLATES . $name;
            self::assertFileExists($path, 'a template in the scope of an opted-in page');
            $source = (string) file_get_contents($path);
            $scope[$name] = $source;

            foreach (self::references($source, $name) as $reference) {
                if (!isset($scope[$reference])) {
                    $todo[] = $reference;
                }
            }
        }

        return $scope;
    }

    /**
     * @return list<string>
     */
    private static function references(string $source, string $name): array
    {
        $references = [];

        // Tags and functions naming a template: include / embed / use / import / from / source() / include()
        preg_match_all('~(?:\{%-?\s*(?:include|embed|use|import|from)\s+|\binclude\(\s*|\bsource\(\s*)([\'"\[])~', $source, $starts, PREG_OFFSET_CAPTURE);

        foreach ($starts[1] as [$quote, $offset]) {
            if ($quote === '[') {
                // include(['a.html.twig', 'b.html.twig']) - every name in the list
                $end = strpos($source, ']', $offset);
                self::assertNotFalse($end, $name . ': an unterminated template list');
                preg_match_all('~[\'"]([^\'"]+\.twig)[\'"]~', substr($source, $offset, $end - $offset), $names);
                array_push($references, ...$names[1]);

                continue;
            }

            if (preg_match('~\G' . preg_quote($quote, '~') . '([^\'"]+)' . preg_quote($quote, '~') . '~', $source, $match, 0, $offset) !== 1) {
                self::fail($name . ': a template name that is not a string - the guard cannot follow it');
            }

            $references[] = $match[1];
        }

        // A template named by a variable: the guard cannot follow it
        if (preg_match('~(?:\{%-?\s*(?:include|embed)\s+|\binclude\(\s*)(?![\'"\[])[a-zA-Z_]~', $source, $dynamic) === 1) {
            self::fail($name . ': "' . $dynamic[0] . '" names its template with a variable - the guard cannot follow it');
        }

        // Components: <twig:Players:Suggestions> / component('PlayerHeader') → components/Players/Suggestions.html.twig
        preg_match_all('~<twig:([A-Za-z0-9_:]+)|\bcomponent\(\s*[\'"]([A-Za-z0-9_:]+)[\'"]~', $source, $components);

        foreach (array_merge($components[1], $components[2]) as $component) {
            if ($component !== '') {
                $references[] = 'components/' . str_replace(':', '/', $component) . '.html.twig';
            }
        }

        return array_values(array_unique(array_filter(
            $references,
            static fn (string $reference): bool => $reference !== 'base.html.twig' && !str_starts_with($reference, '@'),
        )));
    }
}
