<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * One row of "Where did it go?" (LAUNCH-02) for the current viewer: the old name linking its page, and where it is now
 * on a phone, at 768-1023 px and on a computer (one text when they all say the same).
 */
final readonly class WhereDidItGoRow
{
    public function __construct(
        // Anchor id of the row (wdig-<surface>-<n>)
        public string $id,
        public string $oldName,
        public string $href,
        // e.g. "☰ › Account › Your data", "Bottom bar › Home", "Top bar › Inbox"
        public string $now,
        // 768-1023 px (the compact top bar, no left menu yet) when it differs from the computer's text ("Top bar ›
        // Home"), else null; never without $nowDesktop
        public null|string $nowTablet,
        // The computer's place when it differs from the phone's ("Left menu › Home", "Top bar › Add"), else null
        public null|string $nowDesktop,
        // The row in the Menu page (`menu#menu-row-<id>`) when the new place is a Menu row, else null
        public null|string $nowHref,
        // Members-only for this viewer (lock + "Members", no modal)
        public bool $members,
        // A guest finds it there after signing in
        public bool $afterSignIn,
        // The row's text folded like SearchText::fold() - what the filter box searches (data-search): the old name, the
        // new places and, for the top bar's tools, their Menu rows too
        public string $search,
        // The old name alone, folded - the filter's text in the native app, which shows no new places
        public string $nameSearch,
        // data-nav of the old name's link (page/none/wdig_<the linked entry>) and of the "Now" link
        // (page/none/wdig_now_<the new home's entry>) - Q3 of the LAUNCH review
        public string $navId,
        public string $nowNavId,
    ) {
    }
}
