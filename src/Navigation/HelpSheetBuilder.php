<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;

/**
 * The link rows of the Help sheet and the `help` page (docs/features/navigation README §4.5): the registry entries with
 * a helpSheet position, in that order, that the viewer may use. Labels, icons and targets are the entries' own (one
 * name per destination). Decided from the viewer's profile row and the XP gate - no statement; no state.
 *
 * The feedback box and the per-page rows (the puzzle correction, the language row, Reload) are not registry links:
 * HELP-01b and HELP-02 render them around these rows.
 */
final readonly class HelpSheetBuilder
{
    /**
     * The audiences a Help sheet row may have (HelpSheetBuilderTest keeps the registry to them)
     */
    public const array AUDIENCES = [
        NavVisibility::Everyone,
        NavVisibility::GuestOnly,
        NavVisibility::SignedIn,
        NavVisibility::XpVisible,
    ];

    public function __construct(
        private NavigationRegistry $registry,
        private XpFeatureGate $xpFeatureGate,
    ) {
    }

    /**
     * @return list<NavEntry>
     */
    public function rows(null|PlayerProfile $viewer): array
    {
        return array_values(array_filter(
            $this->registry->helpSheet(),
            fn (NavEntry $entry): bool => $this->isFor($entry->visibility, $viewer),
        ));
    }

    private function isFor(NavVisibility $visibility, null|PlayerProfile $viewer): bool
    {
        return match ($visibility) {
            NavVisibility::Everyone => true,
            NavVisibility::GuestOnly => $viewer === null,
            NavVisibility::SignedIn => $viewer !== null,
            // Asked only for such a row: the gate's answer is cached, never a statement per page
            NavVisibility::XpVisible => $this->xpFeatureGate->isVisibleFor($viewer),
            // Not an audience of the Help sheet (AUDIENCES): never shown rather than shown to the wrong viewer
            default => false,
        };
    }
}
