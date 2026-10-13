<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\HasExistingConversation;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\PlayerActions;

/**
 * The one place that decides Favorite, Compare, Message, Block, the tier chip and the level for a viewer looking at a
 * player - shared by the player header (PlayerHeader) and the player card (PlayerCardController), so the two never
 * disagree (docs/features/navigation CARD-01c, tests/Services/PlayerActionsParityTest.php).
 *
 * Stateless: everything rides on the two profile rows. The one statement is HasExistingConversation, asked only when
 * the player switched direct messages off. The other side's blocks are never consulted - a player blocked by the viewer
 * is 404 before anyone asks (GetPlayerProfile::byId()), and the blocked side sees a stranger's actions.
 */
readonly final class PlayerActionsResolver
{
    public function __construct(
        private HasExistingConversation $hasExistingConversation,
        private XpFeatureGate $xpFeatureGate,
    ) {
    }

    public function resolve(PlayerProfile $player, null|PlayerProfile $viewer): PlayerActions
    {
        $isOwn = $viewer !== null && $viewer->playerId === $player->playerId;
        $isHidden = $player->isPrivate && $isOwn === false;

        $favorite = match (true) {
            $isOwn => null,
            $viewer === null => PlayerActions::SIGN_IN,
            in_array($player->playerId, $viewer->favoritePlayers, true) => PlayerActions::FAVORITE_ON,
            default => PlayerActions::FAVORITE_OFF,
        };

        $compare = null;
        $comparisonRef = null;

        if ($isOwn === false && $isHidden === false) {
            $ref = ComparisonSubjectRef::player($player->playerId);
            $comparisonRef = $ref->toString();
            $compare = match (true) {
                $viewer === null => PlayerActions::SIGN_IN,
                $viewer->comparisonLineUp->contains($ref) => PlayerActions::COMPARE_OPEN,
                default => PlayerActions::COMPARE_ADD,
            };
        }

        // The query only for the few who switched direct messages off
        $canMessage = $viewer !== null
            && $isOwn === false
            && ($player->allowDirectMessages || $this->hasExistingConversation->acceptedBetween($viewer->playerId, $player->playerId));

        return new PlayerActions(
            isOwn: $isOwn,
            isHidden: $isHidden,
            favorite: $favorite,
            compare: $compare,
            comparisonRef: $comparisonRef,
            canMessage: $canMessage,
            canBlock: $viewer !== null && $isOwn === false,
            tierChip: self::tierChip($player, $isHidden, $viewer?->activeMembership === true),
            showLevel: $isHidden === false
                && $player->experienceSystemOptedOut === false
                && $this->xpFeatureGate->isVisibleFor($viewer),
        );
    }

    /**
     * Opted out of rankings = nothing at all; not ranked yet = a quiet "No tier yet" (members only, like the tier);
     * private profiles have no tiers, so they never get the "yet" or the lock either. The tier rides on the player's
     * profile row (GetPlayerProfile::byId(), PlayerHeader::TIER_PIECES_COUNT pieces) - no query of its own.
     */
    private static function tierChip(PlayerProfile $player, bool $isHidden, bool $viewerIsMember): null|string
    {
        if ($isHidden || $player->rankingOptedOut) {
            return null;
        }

        if ($viewerIsMember === false) {
            return $player->isPrivateProfile ? null : PlayerActions::TIER_LOCKED;
        }

        if ($player->skillTier !== null) {
            return PlayerActions::TIER;
        }

        return $player->isPrivateProfile ? null : PlayerActions::NO_TIER_YET;
    }
}
