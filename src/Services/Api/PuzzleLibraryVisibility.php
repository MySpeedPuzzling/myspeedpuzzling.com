<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Api;

use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;

/**
 * Who may see which section of a player's puzzle library through the API -
 * the website's rule (PuzzleLibraryController and the list detail
 * controllers), in order: the owner always sees their own; a private profile
 * hides everything from anybody else (every /players/{id} endpoint zeroes a
 * private profile); otherwise the section's own visibility setting decides
 * (the wishlist, unsolved puzzles, lend/borrow list, solved puzzles and the
 * system collection each have one; the sell/swap list is always public).
 *
 * "Owner" is the player behind the token (ApiTokenOwner): a PAT owner or the
 * player of an authorization-code token - so they get their complete library
 * under /players/{their id} exactly as under /me. A client_credentials token
 * has no player and is never the owner.
 */
final readonly class PuzzleLibraryVisibility
{
    public function __construct(
        private ApiTokenOwner $tokenOwner,
    ) {
    }

    public function isOwnedByTokenOwner(PlayerProfile $owner): bool
    {
        return $this->tokenOwner->profile()?->playerId === $owner->playerId;
    }

    public function isVisibleToTokenOwner(PlayerProfile $owner, CollectionVisibility $sectionVisibility): bool
    {
        if ($this->isOwnedByTokenOwner($owner)) {
            return true;
        }

        if ($owner->isPrivate) {
            return false;
        }

        return $sectionVisibility === CollectionVisibility::Public;
    }

    /**
     * The visibility the API reports for a section: the owner's setting, except
     * that a private profile is "private" throughout for everybody but the owner
     * - the setting would otherwise promise a public list the token cannot see.
     */
    public function reportedVisibility(PlayerProfile $owner, CollectionVisibility $sectionVisibility): string
    {
        if ($owner->isPrivate && $this->isOwnedByTokenOwner($owner) === false) {
            return CollectionVisibility::Private->value;
        }

        return $sectionVisibility->value;
    }
}
