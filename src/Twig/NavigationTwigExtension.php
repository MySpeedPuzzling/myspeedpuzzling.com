<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Navigation\BarAction;
use SpeedPuzzling\Web\Navigation\LegacyChromeSwitch;
use SpeedPuzzling\Web\Navigation\MenuBuilder;
use SpeedPuzzling\Web\Navigation\MenuView;
use SpeedPuzzling\Web\Navigation\NavigationLaunch;
use SpeedPuzzling\Web\Navigation\NavigationPlaceResolver;
use SpeedPuzzling\Web\Navigation\NavigationPointers;
use SpeedPuzzling\Web\Navigation\NavigationViewerFactory;
use SpeedPuzzling\Web\Navigation\NavLocation;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * The navigation's Twig functions:
 * - nav_location(): where the current page sits (place, section, Menu row, start page) - resolved once per request
 *   and kept on the request (attribute _nav_location), so the service holds no state between requests;
 * - bar_action(id): the data-bar-action value of a top-bar tool, checked against BarAction (a typo fails at render);
 * - nav_launched() / nav_new_badges_shown(): the launch date's switches (NavigationLaunch);
 * - navigation_pointer(name): whether the signed-in viewer gets that 30-day pointer (NavigationPointers; 'inbox' =
 *   INB-05) - read from the profile row, no query;
 * - nav_menu(surface): the Menu for the viewer ('slot' = the page's one #site-menu, 'page' = the `menu` page; MENU-01);
 * - nav_viewer_audience(): the viewer's audiences for `<body data-viewer-audience>` (page search, MENU-03);
 * - nav_viewer(): the current viewer as the navigation sees it (NavigationViewer: audiences, trial days left);
 * - language_switch_urls(): the current page in the other five languages (decision 36) - the Menu's Language row, the
 *   top bar's and the footer's language menus;
 * - global nav_omit_legacy_chrome: the weight probe's test-only switch (LegacyChromeSwitch, decision 34).
 */
final class NavigationTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public const string REQUEST_ATTRIBUTE = '_nav_location';

    // The switch's order of the other languages (as _language_menu.html.twig always listed them)
    private const array LOCALES = ['en', 'de', 'cs', 'fr', 'es', 'ja'];

    public function __construct(
        readonly private RequestStack $requestStack,
        readonly private NavigationPlaceResolver $placeResolver,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private NavigationLaunch $navigationLaunch,
        readonly private NavigationPointers $navigationPointers,
        readonly private MenuBuilder $menuBuilder,
        readonly private NavigationViewerFactory $viewerFactory,
        readonly private UrlGeneratorInterface $urlGenerator,
        readonly private LegacyChromeSwitch $legacyChromeSwitch,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        return [
            'nav_omit_legacy_chrome' => $this->legacyChromeSwitch->omitsLegacyChrome(),
        ];
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nav_location', $this->navLocation(...)),
            new TwigFunction('bar_action', $this->barAction(...)),
            new TwigFunction('nav_launched', $this->navigationLaunch->hasLaunched(...)),
            new TwigFunction('nav_new_badges_shown', $this->navigationLaunch->newBadgesVisible(...)),
            new TwigFunction('navigation_pointer', $this->navigationPointer(...)),
            new TwigFunction('nav_menu', $this->menu(...)),
            new TwigFunction('nav_viewer_audience', $this->viewerAudience(...)),
            new TwigFunction('nav_viewer', $this->viewerFactory->current(...)),
            new TwigFunction('language_switch_urls', $this->languageSwitchUrls(...)),
        ];
    }

    public function menu(string $surface = MenuView::SURFACE_SLOT): MenuView
    {
        return $this->menuBuilder->build($surface, $this->navLocation()->menuRowId);
    }

    /**
     * Space-separated: "everyone guest" (+ "xp_visible" once XP is launched) for every guest - guest HTML stays
     * shared-cacheable; a player's own audiences without the role ones (NavigationViewer::pageAudience())
     */
    public function viewerAudience(): string
    {
        return implode(' ', $this->viewerFactory->current()->pageAudience());
    }

    /**
     * The current page in each other language, locale => URL, by the language switch's rules: the route with its
     * parameters and query string (filters survive a switch; ?return=, ?return_url= and ?return_title= are dropped,
     * except on the locale-less auth pages, which keep their destination across the switch - `_auth_page`); the
     * homepage of that language when the page has no route.
     *
     * @return array<string, string>
     */
    public function languageSwitchUrls(): array
    {
        $request = $this->requestStack->getMainRequest();

        if ($request === null) {
            return [];
        }

        $route = $request->attributes->get('_route');
        $routeParams = $request->attributes->get('_route_params');
        $query = $request->query->all();

        if ($request->attributes->get('_auth_page') !== true) {
            unset($query['return'], $query['return_url'], $query['return_title']);
        }

        $urls = [];

        foreach (self::LOCALES as $locale) {
            if ($locale === $request->getLocale()) {
                continue;
            }

            $urls[$locale] = is_string($route) && $route !== ''
                ? $this->urlGenerator->generate($route, [...(is_array($routeParams) ? $routeParams : []), ...$query, '_locale' => $locale])
                : $this->urlGenerator->generate('homepage', ['_locale' => $locale]);
        }

        return $urls;
    }

    public function navLocation(): NavLocation
    {
        $request = $this->requestStack->getMainRequest();

        if ($request === null) {
            return NavLocation::none();
        }

        $memo = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($memo instanceof NavLocation) {
            return $memo;
        }

        $route = $request->attributes->get('_canonical_route') ?? $request->attributes->get('_route');
        $params = $request->attributes->get('_route_params');
        // Pages whose URL has no playerId name their player themselves (e.g. somebody else's collection)
        $pageOwner = $request->attributes->get(NavigationPlaceResolver::PAGE_OWNER_ATTRIBUTE);

        $location = $this->placeResolver->resolve(
            is_string($route) ? $route : null,
            is_array($params) ? $params : [],
            $this->retrieveLoggedUserProfile->getProfile()?->playerId,
            is_string($pageOwner) ? $pageOwner : null,
        );

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $location);

        return $location;
    }

    public function navigationPointer(string $name): bool
    {
        return $this->navigationPointers->isActive(
            $this->retrieveLoggedUserProfile->getProfile(),
            NavigationPointers::byName($name),
        );
    }

    public function barAction(string $id): string
    {
        return BarAction::from($id)->value;
    }
}
