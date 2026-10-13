<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The section chips of a place (docs/features/navigation README §4.9, D6, D8; COM-02, PUZ-01; decision 75):
 * - Community: Leaderboards · Puzzlers · Events on every page the resolver puts in one of them (NavLocation::$section)
 *   - not on the landing, others' player pages or team pages, which have no section;
 * - Puzzles: My library (signed-in, first) · All puzzles · Marketplace · What next? on the four start pages only (your
 *   own library, the catalogue, the marketplace, the picker) - your own list pages have the player header's tabs and
 *   only record "My library" (PUZ-01), object pages record nothing.
 *
 * Every chip's label and target are its registry entry's (one name per destination), except My library: README D6
 * names the Puzzles section "My library" where the Menu row and the player tab say "Library" (README §4.12, §4.7).
 * puzzlesSections() is also what the Puzzles place links carry (data-puzzles-sections, PUZ-01): the place opens the
 * last used section. From the URL generator and the request only - no statement, no state.
 */
final readonly class SectionChipsBuilder
{
    /**
     * Each place's sections in chip order (NavPlace::sections()) => the registry entry naming it
     */
    public const array ENTRIES = [
        'leaderboards' => 'leaderboards',
        'puzzlers' => 'puzzlers',
        'events' => 'events',
        'library' => 'library',
        'all' => 'all_puzzles',
        'marketplace' => 'marketplace',
        'next' => 'what_next',
    ];

    public const string MY_LIBRARY_LABEL = 'nav.chips.my_library';

    // The rows' accessible names
    private const string COMMUNITY_LABEL = 'nav.chips.community';
    private const string PUZZLES_LABEL = 'nav.chips.puzzles';

    public function __construct(
        private NavigationRegistry $registry,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
    ) {
    }

    public function build(NavLocation $location, null|string $viewerPlayerId): null|SectionChips
    {
        $section = $location->section;

        if ($section === null) {
            return null;
        }

        if ($location->place === NavPlace::Community) {
            $sections = $this->communitySections();
            $label = self::COMMUNITY_LABEL;
        } elseif ($location->place === NavPlace::Puzzles && $location->isStartPage) {
            // Decision 75: the four start pages only
            $sections = $this->puzzlesSections($viewerPlayerId);
            $label = self::PUZZLES_LABEL;
        } else {
            return null;
        }

        if (!isset($sections[$section])) {
            return null;
        }

        $request = $this->requestStack->getMainRequest();
        $currentPath = $request !== null ? rawurldecode($request->getBaseUrl() . $request->getPathInfo()) : null;
        $chips = [];

        foreach ($sections as $id => $href) {
            $current = null;

            if ($id === $section) {
                $current = $currentPath !== null && rawurldecode((string) parse_url($href, PHP_URL_PATH)) === $currentPath ? 'page' : 'true';
            }

            $chips[] = new SectionChip($id, $href, $this->label($id), $current);
        }

        return new SectionChips($location->place, $label, $chips);
    }

    /**
     * Section => href of the Puzzles place: My library for a signed-in viewer (their own puzzle_library) - first - then
     * All puzzles, Marketplace, What next?
     *
     * @return array<string, string>
     */
    public function puzzlesSections(null|string $viewerPlayerId): array
    {
        $sections = [];

        foreach (NavPlace::Puzzles->sections() as $id) {
            if ($id === 'library') {
                if ($viewerPlayerId !== null) {
                    $sections[$id] = $this->urlGenerator->generate('puzzle_library', ['playerId' => $viewerPlayerId]);
                }

                continue;
            }

            $sections[$id] = $this->href($id);
        }

        return $sections;
    }

    /**
     * @return array<string, string>
     */
    private function communitySections(): array
    {
        $sections = [];

        foreach (NavPlace::Community->sections() as $id) {
            $sections[$id] = $this->href($id);
        }

        return $sections;
    }

    private function href(string $section): string
    {
        $target = $this->entry($section)->target;
        assert($target->route !== null && $target->dynamicParams === [], 'The section ' . $section . ' links a fixed route');

        return $this->urlGenerator->generate($target->route, $target->params);
    }

    private function label(string $section): string
    {
        return $section === 'library' ? self::MY_LIBRARY_LABEL : $this->entry($section)->label;
    }

    private function entry(string $section): NavEntry
    {
        $entry = $this->registry->byId(self::ENTRIES[$section]);
        assert($entry !== null, 'The registry has no entry for the section ' . $section);

        return $entry;
    }
}
