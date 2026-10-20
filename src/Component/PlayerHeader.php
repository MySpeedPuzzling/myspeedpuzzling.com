<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Query\HasExistingConversation;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\Insights\InsightsFeature;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\MspRatingTimeline;
use SpeedPuzzling\Web\Value\PlayerHeaderTab;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The header of every page of one player (docs/features/player-header.md): who it is, what can be done with them and
 * their pages as tabs - or, on the owner's own form and history pages ($compact), a one-line strip.
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

    /** 'tier' | 'no_tier_yet' | 'locked' | null - see tierChip() */
    public null|string $tierChip = null;

    /**
     * The player's place on the MSP Rating ladder, from their profile row (null = not on the ladder, or a hidden
     * private profile) - the competition chip.
     */
    public null|int $mspRatingRank = null;

    /**
     * Places gained (negative = lost) since the day the ladder's movement compares with - the chip's arrow, null when
     * there is nothing to compare (new on the ladder, no stored day) (docs/features/msp-rating-history.md)
     */
    public null|int $mspRatingPlacesGained = null;

    public null|DateTimeImmutable $mspRatingMoveSince = null;

    /**
     * The XP level ring around the avatar (docs/features/xp-levels/launch-2026-10.md): not for a player who opted out
     * of the experience system, nor for a private profile hidden from this viewer.
     */
    public bool $showLevelRing = false;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private HasExistingConversation $hasExistingConversation,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private InsightsFeature $insightsFeature,
        readonly private ClockInterface $clock,
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

        if ($this->compact) {
            return;
        }

        // A hidden private profile has nothing but its profile page
        if ($this->isHidden === false) {
            // Achievements: only members have them (a non-member owner gets the tab with the member tease), and only
            // once progression is live for this viewer (XpFeatureGate); none for a player who hid them, owner included
            $achievementsTab = $this->xpFeatureGate->isVisibleFor($viewer)
                && $player->achievementsOptedOut === false
                && ($player->activeMembership || $this->isOwnProfile);

            // Insights: the owner's own, while the INSIGHTS_PUBLIC flag (or admin access) shows them
            $insightsTab = $this->isOwnProfile && $this->insightsFeature->isVisibleTo($viewer);

            $this->tabs = array_values(array_filter(
                PlayerHeaderTab::cases(),
                fn (PlayerHeaderTab $tab): bool => ($tab->isOwnerOnly() === false || $this->isOwnProfile)
                    && ($tab !== PlayerHeaderTab::Achievements || $achievementsTab)
                    && ($tab !== PlayerHeaderTab::Insights || $insightsTab),
            ));
        }

        if ($this->isOwnProfile === false && $this->isHidden === false) {
            $ref = ComparisonSubjectRef::player($player->playerId);
            $this->comparisonRef = $ref->toString();
            $this->compare = match (true) {
                $viewer === null => 'sign_in',
                $viewer->comparisonLineUp->contains($ref) => 'open',
                default => 'add',
            };
        }

        if ($viewer !== null && $this->isOwnProfile === false) {
            $this->isInViewersFavorites = in_array($player->playerId, $viewer->favoritePlayers, true);

            // The query only for the few who switched direct messages off
            $this->canMessage = $player->allowDirectMessages
                || $this->hasExistingConversation->acceptedBetween($viewer->playerId, $player->playerId);
        }

        $this->tierChip = $this->tierChip($viewer?->activeMembership === true);
        $this->mspRatingRank = $this->isHidden || $player->rankingOptedOut ? null : $player->mspRatingRank;

        if ($this->mspRatingRank !== null && $player->mspRatingRankBefore !== null) {
            $this->mspRatingPlacesGained = $player->mspRatingRankBefore - $this->mspRatingRank;
            $this->mspRatingMoveSince = MspRatingTimeline::moveReferenceDay($this->clock->now(), GetPlayerRatingRanking::MOVE_DAYS);
        }
    }

    /**
     * Opted out of rankings = nothing at all; not ranked yet = a quiet "No tier yet" (members only, like the tier);
     * private profiles have no tiers, so they never get the "yet" or the lock either
     */
    private function tierChip(bool $viewerIsMember): null|string
    {
        $player = $this->player;
        assert($player !== null);

        if ($this->isHidden || $player->rankingOptedOut) {
            return null;
        }

        if ($viewerIsMember === false) {
            return $player->isPrivateProfile ? null : 'locked';
        }

        // The tier rides on the player's profile row (GetPlayerProfile::byId()) - no query of its own
        if ($player->skillTier !== null) {
            return 'tier';
        }

        return $player->isPrivateProfile ? null : 'no_tier_yet';
    }
}
