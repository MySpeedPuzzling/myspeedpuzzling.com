<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPlayerSkill;
use SpeedPuzzling\Web\Query\HasExistingConversation;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\PlayerSkillResult;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
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

    /** 'tier' | 'no_tier_yet' | 'locked' | null - see tierChip() */
    public null|string $tierChip = null;

    public null|PlayerSkillResult $primarySkill = null;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerSkill $getPlayerSkill,
        readonly private HasExistingConversation $hasExistingConversation,
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

        if ($this->compact) {
            return;
        }

        // A hidden private profile has nothing but its profile page
        if ($this->isHidden === false) {
            $this->tabs = array_values(array_filter(
                PlayerHeaderTab::cases(),
                fn (PlayerHeaderTab $tab): bool => $tab->isOwnerOnly() === false || $this->isOwnProfile,
            ));
        }

        if ($viewer !== null && $this->isOwnProfile === false) {
            $this->isInViewersFavorites = in_array($player->playerId, $viewer->favoritePlayers, true);

            // The query only for the few who switched direct messages off
            $this->canMessage = $player->allowDirectMessages
                || $this->hasExistingConversation->acceptedBetween($viewer->playerId, $player->playerId);
        }

        $this->tierChip = $this->tierChip($viewer?->activeMembership === true);
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

        $this->primarySkill = $this->getPlayerSkill->byPlayerIdAndPiecesCount($player->playerId, self::TIER_PIECES_COUNT);

        if ($this->primarySkill !== null) {
            return 'tier';
        }

        return $player->isPrivateProfile ? null : 'no_tier_yet';
    }
}
