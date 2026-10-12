<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;

/**
 * The Help sheet and the `help` page in their order (docs/features/navigation README §4.5, HELP-02 / HELP-03):
 * the feedback box (signed in; guests: "Sign in to send feedback") · "Suggest a correction to this puzzle" (puzzle pages
 * only) · the registry's Help sheet links (rows(): Ideas & feature requests · FAQ · Getting started · How progress works
 * · Where did it go?) · Language · Reload page (installed app only). No "What's new" / "Thanks & resources" until their
 * pages exist. Labels, icons and descriptions are the registry entries' own (one name per destination). Decided from the
 * viewer's profile row, the page context and the XP gate - no statement; no state.
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

    // The registry entries of the per-page rows (labels and icons)
    public const string CORRECTION_ENTRY = 'suggest_correction';
    public const string LANGUAGE_ENTRY = 'language';

    public function __construct(
        private NavigationRegistry $registry,
        private XpFeatureGate $xpFeatureGate,
    ) {
    }

    /**
     * Every item of the sheet for this viewer on this page, in order; its id is the item's data-help-item
     *
     * @return list<HelpSheetItem>
     */
    public function items(null|PlayerProfile $viewer, HelpPageContext $context): array
    {
        $items = [
            $viewer !== null
                ? new HelpSheetItem('feedback', HelpSheetItemKind::Feedback)
                : new HelpSheetItem('feedback_sign_in', HelpSheetItemKind::SignInToSend),
        ];

        $correction = $this->registry->byId(self::CORRECTION_ENTRY);

        if ($context->puzzleId !== null && $correction !== null) {
            $items[] = new HelpSheetItem($correction->id, HelpSheetItemKind::Correction, $correction);
        }

        foreach ($this->rows($viewer) as $entry) {
            $items[] = new HelpSheetItem($entry->id, HelpSheetItemKind::Link, $entry);
        }

        $items[] = new HelpSheetItem('language', HelpSheetItemKind::Language, $this->registry->byId(self::LANGUAGE_ENTRY));
        $items[] = new HelpSheetItem('reload', HelpSheetItemKind::Reload);

        return $items;
    }

    /**
     * The registry's Help sheet links the viewer may use, in their helpSheet order
     *
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
