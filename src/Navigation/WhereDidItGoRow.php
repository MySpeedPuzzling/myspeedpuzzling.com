<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * One row of "Where did it go?" (LAUNCH-02) for the current viewer: the old name linking its page, and where it is now
 * on a phone and on a computer (one text when both say the same).
 */
final readonly class WhereDidItGoRow
{
    public function __construct(
        // Anchor id of the row (wdig-<surface>-<n>)
        public string $id,
        public string $oldName,
        public string $href,
        // e.g. "☰ › Account › Your data", "Bottom bar › Home"
        public string $now,
        // The computer's place when it differs from the phone's ("Left menu › Home", "Top bar › Inbox"), else null
        public null|string $nowDesktop,
        // The row in the Menu page (`menu#menu-row-<id>`) when the new place is a Menu row, else null
        public null|string $nowHref,
        // Members-only for this viewer (lock + "Members", no modal)
        public bool $members,
        // A guest finds it there after signing in
        public bool $afterSignIn,
        // The row's text folded like SearchText::fold() - what the filter box searches (data-search)
        public string $search,
    ) {
    }
}
