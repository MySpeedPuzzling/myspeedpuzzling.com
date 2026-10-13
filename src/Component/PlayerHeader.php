<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\PlayerActionsResolver;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\PlayerHeaderTab;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The header of every page of one player (docs/features/player-header.md): who it is, what can be done with them and
 * their pages as tabs - or, on the owner's own form and history pages ($compact), a one-line strip.
 *
 * What can be done (Favorite, Compare, Message, Block, the tier chip) is decided by PlayerActionsResolver - the same
 * service the player card asks (docs/features/navigation CARD-01c); this component only copies it into its props.
 */
#[AsTwigComponent]
final class PlayerHeader
{
    public const int TIER_PIECES_COUNT = 500;

    public null|PlayerProfile $player = null;

    /** One of PlayerHeaderTab's values: the tab shown as the current one */
    public null|string $tab = null;

    /** The owner's form and history pages: a one-line strip instead of the header */
    public bool $compact = false;

    /** The player's name is the page's <h1> - on the pages without one of their own */
    public bool $heading = false;

    public null|PlayerHeaderTab $currentTab = null;

    /** @var list<PlayerHeaderTab> */
    public array $tabs = [];

    public bool $isOwnProfile = false;

    /** A private profile this viewer may not see (PrivateProfileAccess decided it in GetPlayerProfile) */
    public bool $isHidden = false;

    public bool $isInViewersFavorites = false;

    public bool $canMessage = false;

    /**
     * The compare button and its ⋯ menu items (docs/features/player-comparison.md, D11): 'add' (not in the viewer's
     * Solo line-up - a POST form), 'open' (in it - a link to the comparison), 'sign_in' (a guest) or null (own profile,
     * hidden private profile). Decided from the viewer's own profile row - no query.
     */
    public null|string $compare = null;

    /** ComparisonSubjectRef::toString() of this player - what the add/remove forms send */
    public null|string $comparisonRef = null;

    /** 'tier' | 'no_tier_yet' | 'locked' | null - see PlayerActionsResolver */
    public null|string $tierChip = null;

    /**
     * The player's place on the MSP Rating ladder, from their profile row (null = not on the ladder, or a hidden
     * private profile) - the competition chip.
     */
    public null|int $mspRatingRank = null;

    /**
     * The XP level ring around the avatar (docs/features/xp-levels/launch-2026-10.md): not for a player who opted out
     * of the experience system, nor for a private profile hidden from this viewer.
     */
    public bool $showLevelRing = false;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlayerActionsResolver $playerActionsResolver,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[PostMount]
    public function prepare(): void
    {
        $player = $this->player;
        assert($player !== null);

        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        $this->currentTab = $this->tab !== null ? PlayerHeaderTab::from($this->tab) : null;
        $this->isOwnProfile = $viewer !== null && $viewer->playerId === $player->playerId;
        $this->isHidden = $player->isPrivate && $this->isOwnProfile === false;
        $this->showLevelRing = $this->isHidden === false && $player->experienceSystemOptedOut === false;

        // The strip offers no actions: nothing to decide (and no statement to spend)
        if ($this->compact) {
            return;
        }

        $actions = $this->playerActionsResolver->resolve($player, $viewer);

        // A hidden private profile has nothing but its profile page
        if ($this->isHidden === false) {
            // Achievements: only members have them (a non-member owner gets the tab with the member tease), and only
            // once progression is live for this viewer (XpFeatureGate); none for a player who hid them, owner included
            $achievementsTab = $this->xpFeatureGate->isVisibleFor($viewer)
                && $player->achievementsOptedOut === false
                && ($player->activeMembership || $this->isOwnProfile);

            $this->tabs = array_values(array_filter(
                PlayerHeaderTab::cases(),
                fn (PlayerHeaderTab $tab): bool => ($tab->isOwnerOnly() === false || $this->isOwnProfile)
                    && ($tab !== PlayerHeaderTab::Achievements || $achievementsTab),
            ));
        }

        $this->comparisonRef = $actions->comparisonRef;
        $this->compare = $actions->compare;
        $this->isInViewersFavorites = $actions->isInViewersFavorites();
        $this->canMessage = $actions->canMessage;
        $this->tierChip = $actions->tierChip;
        $this->mspRatingRank = $this->isHidden || $player->rankingOptedOut ? null : $player->mspRatingRank;
    }
}
