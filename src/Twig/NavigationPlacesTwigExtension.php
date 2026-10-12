<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Navigation\NavLocation;
use SpeedPuzzling\Web\Navigation\PlaceLink;
use SpeedPuzzling\Web\Navigation\PlacesBuilder;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * nav_places(location, variant): the places as a bar links them (PlacesBuilder; templates/navigation/_places.html.twig)
 * - the phone tab bar (NAV-01), later the rail (NAV-13) and the compact desktop bar (NAV-04). No state, no statement.
 */
final class NavigationPlacesTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private PlacesBuilder $placesBuilder,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nav_places', $this->places(...)),
        ];
    }

    /**
     * @return list<PlaceLink>
     */
    public function places(NavLocation $location, string $variant = PlacesBuilder::VARIANT_BAR): array
    {
        return $this->placesBuilder->build($location, $variant);
    }
}
