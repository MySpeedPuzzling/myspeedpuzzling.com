<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\Query\GetInboxBadge;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\InboxBadgeParts;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The Inbox badge (docs/features/navigation INB-02): every instance on a page - the header today, the phone and the
 * desktop top bar and the Menu's Inbox row later - reads the one memoised statement.
 *
 * - forViewer(): the signed-in player's parts, the hidden players taken from the profile row every page loads anyway
 *   (0 statements of its own besides the badge statement); null for guests. forYouMarkedRead() updates the memo after
 *   the notifications page marked everything read.
 * - forPlayer(): another player's parts, for a realtime push (MercureNotifier::notifyInboxBadgeChanged()) - their
 *   blocks looked up, never the request viewer's. Not memoised: a push wants the number after its own change.
 */
final class InboxBadge implements ResetInterface
{
    private bool $resolved = false;

    private null|InboxBadgeParts $viewerParts = null;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetInboxBadge $getInboxBadge,
        readonly private GetUserBlocks $getUserBlocks,
    ) {
    }

    public function forViewer(): null|InboxBadgeParts
    {
        if ($this->resolved === true) {
            return $this->viewerParts;
        }

        $this->resolved = true;
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile !== null) {
            $this->viewerParts = $this->getInboxBadge->forPlayer($profile->playerId, $profile->hiddenPlayerIds);
        }

        return $this->viewerParts;
    }

    /**
     * The notifications page marked every notification of the viewer read (NotificationsController) after it read
     * forViewer(): the rest of the page shows For you 0 and the Messages part it read - no second statement.
     */
    public function forYouMarkedRead(): void
    {
        if ($this->viewerParts === null) {
            return;
        }

        $this->viewerParts = new InboxBadgeParts(
            forYou: 0,
            unreadConversations: $this->viewerParts->unreadConversations,
            pendingRequests: $this->viewerParts->pendingRequests,
        );
    }

    public function forPlayer(string $playerId): InboxBadgeParts
    {
        return $this->getInboxBadge->forPlayer($playerId, $this->getUserBlocks->blockedIdsOf($playerId));
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->viewerParts = null;
    }
}
