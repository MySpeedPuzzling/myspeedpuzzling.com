<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "Pages & settings" list of the page search (docs/features/navigation README §4.12 "Search", MENU-03): every Menu
 * row and search-only entry of NavigationRegistry in one locale, with its audience, Menu path and search keys - built
 * from the registry and the translator, no statement.
 *
 * - nav-search.json (NavigationSearchIndexController) is the same for everybody, so it is public and cached for a day:
 *   the browser filters it by the page's `<body data-viewer-audience>`. Entries that need the viewer's player id link
 *   `nav_go` (R14), which redirects to the viewer's own page. Moderator, time verifier and admin entries are not in
 *   it: privateJson() renders them into the page of those viewers only (`#nav-search-private`).
 * - Each entry: `id`, `l` label, `d` description, `g` its Menu group, `p` its path in the phone panel ("☰ › Account ›
 *   Your data"; empty when the panel has no row for it - the tabs), `pd` its path in the desktop Menu when that differs
 *   (empty when it has none - the Inbox tool, Log out in the account menu), `b` the bars that always offer it
 *   (NavigationRegistry::ON_BARS: `p` phone, `d` desktop, `a` the desktop account menu of a signed-in player - no Menu
 *   path is shown for it there, SRCH+FOOT review F3), `u` URL, `a` audience (a NavVisibility value, or `signed_in` /
 *   `guest` for the two halves of an entry with a guests' route), `s` 1 when following `u` asks a guest to sign in first
 *   (the route wants a signed-in visitor - #[IsGranted] -, or nav_go: the viewer's own page - SRCH+FOOT review F1), `i`
 *   icon, `o` its place in the Menu (ties of the ranking), `x` what the link does besides navigating
 *   (`help` = the Help sheet, `language` = the Menu's language switch, `logout`) and `k` the search keys: the label,
 *   the synonyms (nav.syn.<id>), the legacy labels and the English label, each folded once by SearchText::fold() -
 *   the browser folds only what is typed (assets/search_fold.js, its twin; assets/navigation/nav_search.js), one per
 *   line, the label first.
 * - Version (`?v=` on the page, `v` in the list): a hash of the deploy's container build (a deploy is the only way the
 *   list changes - translations, routes and the registry ship with the code), the locale and the year (the archive's
 *   year is in the list); in debug a hash of when its sources (the registry, the en and the locale's translations)
 *   changed on disk. Built lists are cached in cache.app under the locale and that version - never in debug.
 *
 * Plain service, no state between requests (FrankenPHP worker mode).
 */
final readonly class NavigationSearchIndex
{
    // Bump when the shape of the list changes: every version changes with it
    private const int FORMAT = 2;
    private const string CACHE_PREFIX = 'nav_search_index.';
    private const int CACHE_TTL = 7 * 86400;
    private const string EXTRA_HELP = 'help';
    private const string EXTRA_LANGUAGE = 'language';
    private const string EXTRA_LOGOUT = 'logout';

    /** NavigationRegistry::ON_BARS in the list's `b` */
    private const array BAR_FLAGS = [
        NavigationRegistry::BAR_PHONE => 'p',
        NavigationRegistry::BAR_DESKTOP => 'd',
        NavigationRegistry::BAR_ACCOUNT => 'a',
    ];

    public function __construct(
        private NavigationRegistry $registry,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private NavigationSignInRule $signInRule,
        private ClockInterface $clock,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
        private ContainerBagInterface $parameters,
        #[Autowire('%kernel.debug%')]
        private bool $debug,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * The `?v=` of the page's nav-search.json URL and the list's `v`.
     */
    public function version(string $locale): string
    {
        if ($this->debug) {
            // The list's sources as they are on disk - building the list on every page view would cost ~4 ms
            $stamps = array_map(
                static fn (string $file): string => is_file($file) ? (string) filemtime($file) : '-',
                [$this->projectDir . '/src/Navigation/NavigationRegistry.php', $this->projectDir . '/translations/messages.en.yml', $this->projectDir . '/translations/messages.' . $locale . '.yml'],
            );

            return self::hash(self::FORMAT . '|debug|' . implode('|', $stamps) . '|' . $locale . '|' . $this->clock->now()->format('Y'));
        }

        return self::hash(self::FORMAT . '|' . $this->buildId() . '|' . $locale . '|' . $this->clock->now()->format('Y'));
    }

    /**
     * nav-search.json for the locale.
     */
    public function json(string $locale): string
    {
        $version = $this->version($locale);

        if ($this->debug) {
            return $this->encode($locale, $version);
        }

        return $this->cache->get(self::CACHE_PREFIX . $locale . '.' . $version, function (ItemInterface $item) use ($locale, $version): string {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->encode($locale, $version);
        });
    }

    /**
     * The role entries this viewer may open (moderator, time verifier, admin rows) as a JSON list of entries - safe to
     * put into a `<script type="application/json">`; null when there are none (everybody else).
     */
    public function privateJson(string $locale, NavigationViewer $viewer): null|string
    {
        if ($viewer->isGuest()) {
            return null;
        }

        $entries = [];
        $orders = $this->orders();

        foreach ($this->registry->all() as $entry) {
            $route = $entry->target->route;

            if (!$entry->isInPrivatePageSearch() || !$viewer->sees($entry->visibility) || $route === null) {
                continue;
            }

            $entries[] = $this->inLocale($locale, fn (): array => $this->entry($entry, $locale, $entry->visibility->value, $this->url($route, $entry), null, $orders));
        }

        if ($entries === []) {
            return null;
        }

        return json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    /**
     * The public list's entries for the locale, in Menu order (tests read them).
     *
     * @return list<array<string, int|string>>
     */
    public function entries(string $locale): array
    {
        return $this->payload($locale)['entries'];
    }

    private function encode(string $locale, string $version): string
    {
        return json_encode(['v' => $version, ...$this->payload($locale)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{groups: array<string, string>, entries: list<array<string, int|string>>}
     */
    private function payload(string $locale): array
    {
        $orders = $this->orders();
        $entries = [];
        $groups = [];

        foreach ($this->registry->all() as $entry) {
            if (!$entry->isInPageSearch()) {
                continue;
            }

            foreach ($this->inLocale($locale, fn (): array => $this->variants($entry)) as [$audience, $url, $extra]) {
                $entries[] = $this->entry($entry, $locale, $audience, $url, $extra, $orders);
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a['o'] <=> $b['o']);

        foreach (MenuGroup::cases() as $group) {
            $groups[$group->value] = $this->registry->groupTitle($group, $locale);
        }

        return ['groups' => $groups, 'entries' => $entries];
    }

    /**
     * The audience and link of an entry; an entry with a guests' route is two entries - one for signed-in viewers,
     * one for guests - so the list stays the same for everybody.
     *
     * @return list<array{string, string, null|string}>
     */
    private function variants(NavEntry $entry): array
    {
        $target = $entry->target;
        $audience = $entry->visibility->value;

        return match (true) {
            $entry->kind === RowKind::Language => [[$audience, $this->urlGenerator->generate('menu') . '#nav-language', self::EXTRA_LANGUAGE]],
            $entry->kind === RowKind::HelpSheet => [[$audience, $this->urlGenerator->generate('help'), self::EXTRA_HELP]],
            $entry->kind === RowKind::LogOut => [[$audience, $this->urlGenerator->generate('logout'), self::EXTRA_LOGOUT]],
            // A page of the viewer's own: the list cannot name the viewer, nav_go does (R14)
            $target->needsViewer() => [[$audience, $this->urlGenerator->generate('nav_go', ['entryId' => $entry->id]), null]],
            $target->route === null => [],
            $target->guestRoute !== null => [
                [NavVisibility::SignedIn->value, $this->url($target->route, $entry), null],
                [NavVisibility::GuestOnly->value, $this->url($target->guestRoute, $entry, withParameters: false), null],
            ],
            default => [[$audience, $this->url($target->route, $entry), null]],
        };
    }

    private function url(string $route, NavEntry $entry, bool $withParameters = true): string
    {
        $parameters = $withParameters ? $entry->target->params : [];

        if ($withParameters) {
            foreach ($entry->target->dynamicParams as $name => $source) {
                // Only the current year is not about the viewer (needsViewer() entries go through nav_go)
                if ($source === RouteParam::CurrentYear) {
                    $parameters[$name] = (int) $this->clock->now()->format('Y');
                }
            }
        }

        $url = $this->urlGenerator->generate($route, $parameters);

        return $withParameters && $entry->target->fragment !== null ? $url . '#' . $entry->target->fragment : $url;
    }

    /**
     * @param array<string, int> $orders
     * @return array<string, int|string>
     */
    private function entry(NavEntry $entry, string $locale, string $audience, string $url, null|string $extra, array $orders): array
    {
        $label = $this->registry->label($entry, $locale);
        $separator = $this->translator->trans('nav.search.path_separator', [], null, $locale);

        $data = [
            'id' => $entry->id,
            'l' => $label,
        ];

        if ($entry->description !== null) {
            $data['d'] = $this->translator->trans($entry->description, [], null, $locale);
        }

        $data['g'] = $this->group($entry)->value ?? '';
        // A guests' half of a page whose Menu row is for players only (What next?): no Menu of theirs holds it
        $inGuestsMenu = $audience !== NavVisibility::GuestOnly->value || $entry->visibility->includesGuests();
        $phonePath = $inGuestsMenu ? $this->registry->menuPathOn(MenuSurface::Phone, $entry->id, $locale, $separator) ?? '' : '';
        $deskPath = $inGuestsMenu ? $this->registry->menuPathOn(MenuSurface::Dock, $entry->id, $locale, $separator) ?? '' : '';
        $data['p'] = $phonePath;

        if ($deskPath !== $phonePath) {
            $data['pd'] = $deskPath;
        }

        $bars = implode('', array_map(static fn (string $bar): string => self::BAR_FLAGS[$bar], NavigationRegistry::ON_BARS[$entry->id] ?? []));

        if ($bars !== '') {
            $data['b'] = $bars;
        }

        $data['u'] = $url;
        $data['a'] = $audience;

        if ($extra === null && $this->asksGuestsToSignIn($entry, $audience)) {
            $data['s'] = 1;
        }

        $data['i'] = $entry->icon;
        $data['o'] = $orders[$entry->id] ?? PHP_INT_MAX;

        if ($extra !== null) {
            $data['x'] = $extra;
        }

        $data['k'] = implode("\n", $this->keys($entry, $locale, $label));

        return $data;
    }

    /**
     * Does following the entry's link ask a guest to sign in first (NavigationSignInRule)? Only for audiences a guest is
     * not in; a page that opens for guests never is (What next?'s landing, Where did it go?). TopBarPageSearchTest
     * requests every such link as a guest.
     */
    private function asksGuestsToSignIn(NavEntry $entry, string $audience): bool
    {
        $visibility = NavVisibility::tryFrom($audience);

        if ($visibility === null || $visibility->includesGuests()) {
            return false;
        }

        return $this->signInRule->asksGuestsToSignIn($entry);
    }

    /**
     * The folded search keys: the label first, then the synonyms, the legacy labels and the English label - each once.
     *
     * @return list<string>
     */
    private function keys(NavEntry $entry, string $locale, string $label): array
    {
        $texts = [$label];

        if ($entry->synonyms !== null) {
            array_push($texts, ...explode(';', $this->translator->trans($entry->synonyms, [], null, $locale)));
        }

        foreach ($entry->legacyLabels as $legacyLabel) {
            $texts[] = $this->translator->trans($legacyLabel, [], null, $locale);
        }

        $texts[] = $this->registry->label($entry, 'en');
        $keys = [];

        foreach ($texts as $text) {
            $folded = SearchText::fold($text);

            if ($folded !== '' && !in_array($folded, $keys, true)) {
                $keys[] = $folded;
            }
        }

        return $keys;
    }

    /**
     * The Menu group an entry is shown in (a search-only entry: its parent's).
     */
    private function group(NavEntry $entry, int $depth = 0): null|MenuGroup
    {
        if ($entry->isSearchOnly()) {
            $parent = $entry->phone->parent !== null ? $this->registry->byId($entry->phone->parent) : null;

            return $parent !== null && $depth < 5 ? $this->group($parent, $depth + 1) : null;
        }

        return $entry->phone->group ?? $entry->dock->group;
    }

    /**
     * Every listed entry's place in the Menu, for the ties of the ranking: phone order (group, position); a dock-only row
     * where its group stands on phones; a search-only entry right after its parent, in registry order.
     *
     * @return array<string, int>
     */
    private function orders(): array
    {
        $orders = [];
        $children = [];

        foreach ($this->registry->all() as $entry) {
            $placement = $entry->phone->isRow() ? $entry->phone : $entry->dock;

            if ($placement->isRow() && $placement->group !== null) {
                $orders[$entry->id] = (($placement->group->order(MenuSurface::Phone) ?? 0) * 1000 + ($placement->position ?? 0)) * 100;
            }
        }

        foreach ($this->registry->all() as $entry) {
            if (!$entry->isSearchOnly()) {
                continue;
            }

            $parent = (string) $entry->phone->parent;
            $children[$parent] = ($children[$parent] ?? 0) + 1;
            $orders[$entry->id] = ($orders[$parent] ?? 0) + $children[$parent];
        }

        return $orders;
    }

    /**
     * Builds links in another locale: the router's context names the locale, so localized routes and `{_locale}` paths
     * take it and routes without a locale stay as they are (a `_locale` parameter would become a query string there).
     *
     * @template T
     * @param callable(): T $build
     * @return T
     */
    private function inLocale(string $locale, callable $build): mixed
    {
        $context = $this->urlGenerator->getContext();
        $previous = $context->getParameter('_locale');
        $context->setParameter('_locale', $locale);

        try {
            return $build();
        } finally {
            $context->setParameter('_locale', $previous);
        }
    }

    /**
     * The compiled container's build id - a runtime parameter of every dumped container, new with every deploy's build
     */
    private function buildId(): string
    {
        try {
            $buildId = $this->parameters->get('container.build_id');
        } catch (ParameterNotFoundException) {
            return '';
        }

        return is_scalar($buildId) ? (string) $buildId : '';
    }

    private static function hash(string $data): string
    {
        return substr(hash('xxh128', $data), 0, 12);
    }
}
