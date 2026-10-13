<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a viewer may do with a player, decided once for the player header and the player card
 * (PlayerActionsResolver, docs/features/navigation CARD-01c). Every value comes from the two profile rows - the viewer's
 * and the player's - except canMessage, which asks one question only when the player switched direct messages off.
 */
readonly final class PlayerActions
{
    public const string FAVORITE_ON = 'on';
    public const string FAVORITE_OFF = 'off';
    public const string SIGN_IN = 'sign_in';
    public const string COMPARE_ADD = 'add';
    public const string COMPARE_OPEN = 'open';

    public const string TIER = 'tier';
    public const string NO_TIER_YET = 'no_tier_yet';
    public const string TIER_LOCKED = 'locked';

    public function __construct(
        public bool $isOwn,
        // A private profile this viewer may not see (PrivateProfileAccess decided it in GetPlayerProfile)
        public bool $isHidden,
        // 'on' | 'off' | 'sign_in' (a guest) | null (own profile)
        public null|string $favorite,
        // 'add' (not in the viewer's Solo line-up) | 'open' (in it) | 'sign_in' (a guest) | null (own, hidden)
        public null|string $compare,
        // ComparisonSubjectRef::toString() of this player whenever compare is offered
        public null|string $comparisonRef,
        public bool $canMessage,
        // Block stays on the profile only (docs/features/navigation D12) - the card never offers it
        public bool $canBlock,
        // 'tier' | 'no_tier_yet' | 'locked' | null
        public null|string $tierChip,
        // The XP level: progression visible to this viewer, the player in the experience system, not hidden
        public bool $showLevel,
    ) {
    }

    public function isInViewersFavorites(): bool
    {
        return $this->favorite === self::FAVORITE_ON;
    }
}
