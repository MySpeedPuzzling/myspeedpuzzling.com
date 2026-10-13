<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Navigation\NavPlace;
use SpeedPuzzling\Web\Navigation\SectionChips;
use SpeedPuzzling\Web\Navigation\SectionChipsBuilder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
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
 *   (data-puzzles-sections; assets/puzzles_section.js opens the last used one).
 */
final class NavigationSectionsTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private NavigationTwigExtension $navigation,
        readonly private SectionChipsBuilder $sectionChipsBuilder,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
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

    private function viewerPlayerId(): null|string
    {
        return $this->retrieveLoggedUserProfile->getProfile()?->playerId;
    }
}
