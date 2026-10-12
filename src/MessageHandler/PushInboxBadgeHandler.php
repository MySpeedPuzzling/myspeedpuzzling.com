<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\PushInboxBadge;
use SpeedPuzzling\Web\Navigation\InboxBadge;
use SpeedPuzzling\Web\Services\MercureNotifier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The player's other open tabs get the badge they would render now (INB-02 review Q2) - with the player's own blocks,
 * never the request viewer's (InboxBadge::forPlayer()). A failed push only means the old number until the next page.
 */
#[AsMessageHandler]
readonly final class PushInboxBadgeHandler
{
    public function __construct(
        private InboxBadge $inboxBadge,
        private MercureNotifier $mercureNotifier,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PushInboxBadge $message): void
    {
        try {
            $this->mercureNotifier->notifyInboxBadgeChanged($message->playerId, $this->inboxBadge->forPlayer($message->playerId));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to push the Inbox badge', [
                'playerId' => $message->playerId,
                'exception' => $e,
            ]);
        }
    }
}
