<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Twig\AddEntryTwigExtension;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The five places as the bars link them (docs/features/navigation README §4.2, D1; implementation plan NAV-01) - from
 * the request and the viewer's profile row only, no statement:
 * - Home: the Hub (guests: the homepage, D5);
 * - Puzzles: your own library (guests: the puzzle database); PUZ-01 later opens the last used section;
 * - Add: add_entry_url() (a puzzle page's Add carries its puzzle; guests: the Add explainer);
 * - Community: its landing;
 * - Profile: your own profile with your avatar (guests: sign in, back to this page afterwards).
 * Labels and icons come from the registry's place entries - the same key and icon on every surface.
 * Variants: `bar` the phone tab bar (all five) - also the desktop rail (NAV-13, R9: #bottom-nav restyled, Add hidden,
 * a guest's Profile labelled "Sign in" through railLabel), `rail` the rail's four places alone (no Add), `compact` the
 * 768-1023 px top bar (NAV-04: Home, Puzzles, Community).
 * No state: the place and the current page come in with the call.
 */
final readonly class PlacesBuilder
{
    public const string VARIANT_BAR = 'bar';
    public const string VARIANT_RAIL = 'rail';
    public const string VARIANT_COMPACT = 'compact';

    // The guests' Profile in the rail (NAV-13): the top bar's Sign in word
    private const string GUEST_RAIL_LABEL = 'nav.bar.sign_in';

    // The registry's entry of each place, in bar order
    private const array ENTRIES = [
        'home' => 'home',
        'puzzles' => 'puzzles_place',
        'add' => 'add',
        'community' => 'community_place',
        'profile' => 'profile',
    ];

    private const array VARIANTS = [
        self::VARIANT_BAR => ['home', 'puzzles', 'add', 'community', 'profile'],
        self::VARIANT_RAIL => ['home', 'puzzles', 'community', 'profile'],
        self::VARIANT_COMPACT => ['home', 'puzzles', 'community'],
    ];

    public function __construct(
        private NavigationRegistry $registry,
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private AddEntryTwigExtension $addEntry,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @return list<PlaceLink>
     */
    public function build(NavLocation $location, string $variant = self::VARIANT_BAR): array
    {
        $places = self::VARIANTS[$variant] ?? throw new \InvalidArgumentException('Unknown places variant: ' . $variant);
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $request = $this->requestStack->getMainRequest();
        $currentPath = $request !== null ? rawurldecode($request->getBaseUrl() . $request->getPathInfo()) : null;

        $links = [];

        foreach ($places as $id) {
            $entry = $this->registry->byId(self::ENTRIES[$id]);
            assert($entry !== null, 'The registry has no entry for the place ' . $id);

            $place = NavPlace::from($id);
            $playerId = $profile?->playerId;

            $href = match ($place) {
                NavPlace::Home => $this->urlGenerator->generate($playerId !== null ? 'hub' : 'homepage'),
                NavPlace::Puzzles => $playerId !== null
                    ? $this->urlGenerator->generate('puzzle_library', ['playerId' => $playerId])
                    : $this->urlGenerator->generate('puzzles'),
                NavPlace::Add => $this->addEntry->addEntryUrl(),
                NavPlace::Community => $this->urlGenerator->generate('community'),
                NavPlace::Profile => $playerId !== null
                    ? $this->urlGenerator->generate('player_profile', ['playerId' => $playerId])
                    : SignInLink::href($this->urlGenerator, $request),
            };

            $current = null;

            if ($location->place === $place) {
                // Decision 27: "page" on the exact page the link leads to, "true" deeper inside the place
                $current = $currentPath !== null && rawurldecode((string) parse_url($href, PHP_URL_PATH)) === $currentPath ? 'page' : 'true';
            }

            $links[] = new PlaceLink(
                place: $place,
                href: $href,
                label: $entry->label,
                icon: $entry->icon,
                current: $current,
                avatar: $place === NavPlace::Profile ? $profile : null,
                nofollow: $place === NavPlace::Profile || ($place === NavPlace::Puzzles && $playerId !== null),
                // The rail (NAV-13, README §4.3): "Sign in" where a guest's tab bar says Profile
                railLabel: $place === NavPlace::Profile && $playerId === null && $variant !== self::VARIANT_COMPACT ? self::GUEST_RAIL_LABEL : null,
            );
        }

        return $links;
    }
}
