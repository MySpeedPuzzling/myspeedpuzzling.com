<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Navigation\BarAction;
use SpeedPuzzling\Web\Navigation\NavigationLaunch;
use SpeedPuzzling\Web\Navigation\NavigationPlaceResolver;
use SpeedPuzzling\Web\Navigation\NavLocation;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The navigation's Twig functions:
 * - nav_location(): where the current page sits (place, section, Menu row, start page) - resolved once per request
 *   and kept on the request (attribute _nav_location), so the service holds no state between requests;
 * - bar_action(id): the data-bar-action value of a top-bar tool, checked against BarAction (a typo fails at render);
 * - nav_launched() / nav_new_badges_shown(): the launch date's switches (NavigationLaunch).
 */
final class NavigationTwigExtension extends AbstractExtension
{
    public const string REQUEST_ATTRIBUTE = '_nav_location';

    public function __construct(
        readonly private RequestStack $requestStack,
        readonly private NavigationPlaceResolver $placeResolver,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private NavigationLaunch $navigationLaunch,
    ) {
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
        ];
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

    public function barAction(string $id): string
    {
        return BarAction::from($id)->value;
    }
}
