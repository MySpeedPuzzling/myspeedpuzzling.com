<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Navigation\NavPlace;
use SpeedPuzzling\Web\Navigation\SectionChips;
use SpeedPuzzling\Web\Navigation\SectionChipsBuilder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The places' sections (docs/features/navigation COM-02, PUZ-01) - from nav_location() and the profile row, no
 * statement, no state:
 * - nav_section(): the current page's section (NavLocation::$section), null outside one;
 * - nav_section_chips(): the chip row of the page (base.html.twig's section_chips block) - null where it has none;
 * - nav_puzzles_section(): the Puzzles section this page records for the Puzzles place (<main data-puzzles-section>):
 *   the four start pages and your own library's list pages, nothing on object pages;
 * - nav_puzzles_sections(): section => href of the Puzzles place for this viewer, the JSON the place links carry
 *   (data-puzzles-sections; assets/puzzles_section.js opens the last used one);
 * - nav_puzzles_sections_carrier(): true for the page's first Puzzles place link only - it carries the JSON, and
 *   assets/puzzles_section.js points every place link of the page from it (the chrome's weight, README §4.12; the same
 *   rule as inbox_link_strings()). The top bar's places render first, then the tab bar, then the Menu's place row; the
 *   first of them on the page carries it. Kept on the request, so the service holds no state.
 */
final class NavigationSectionsTwigExtension extends AbstractExtension
{
    public const string SECTIONS_CARRIED_ATTRIBUTE = '_nav_puzzles_sections_carried';

    public function __construct(
        readonly private NavigationTwigExtension $navigation,
        readonly private SectionChipsBuilder $sectionChipsBuilder,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private RequestStack $requestStack,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nav_section', $this->section(...)),
            new TwigFunction('nav_section_chips', $this->sectionChips(...)),
            new TwigFunction('nav_puzzles_section', $this->puzzlesSection(...)),
            new TwigFunction('nav_puzzles_sections', $this->puzzlesSections(...)),
            new TwigFunction('nav_puzzles_sections_carrier', $this->puzzlesSectionsCarrier(...)),
        ];
    }

    public function section(): null|string
    {
        return $this->navigation->navLocation()->section;
    }

    public function sectionChips(): null|SectionChips
    {
        return $this->sectionChipsBuilder->build($this->navigation->navLocation(), $this->viewerPlayerId());
    }

    public function puzzlesSection(): null|string
    {
        $location = $this->navigation->navLocation();

        if ($location->place !== NavPlace::Puzzles || $location->section === null) {
            return null;
        }

        return in_array($location->section, NavPlace::Puzzles->sections(), true) ? $location->section : null;
    }

    /**
     * @return array<string, string>
     */
    public function puzzlesSections(): array
    {
        return $this->sectionChipsBuilder->puzzlesSections($this->viewerPlayerId());
    }

    public function puzzlesSectionsCarrier(): bool
    {
        $request = $this->requestStack->getMainRequest();

        if ($request === null) {
            return true;
        }

        if ($request->attributes->get(self::SECTIONS_CARRIED_ATTRIBUTE) === true) {
            return false;
        }

        $request->attributes->set(self::SECTIONS_CARRIED_ATTRIBUTE, true);

        return true;
    }

    private function viewerPlayerId(): null|string
    {
        return $this->retrieveLoggedUserProfile->getProfile()?->playerId;
    }
}
