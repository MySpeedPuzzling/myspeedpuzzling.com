<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ModerationAction;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\AdminRemoveListing;
use SpeedPuzzling\Web\Repository\ConversationReportRepository;
use SpeedPuzzling\Web\Repository\ModerationActionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Value\ModerationActionType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AdminRemoveListingHandler
{
    public function __construct(
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private PlayerRepository $playerRepository,
        private ModerationActionRepository $moderationActionRepository,
        private ConversationReportRepository $conversationReportRepository,
    ) {
    }

    /**
     * @throws SellSwapListItemNotFound
     * @throws PlayerNotFound
     */
    public function __invoke(AdminRemoveListing $message): void
    {
        $item = $this->sellSwapListItemRepository->get($message->sellSwapListItemId);
        $admin = $this->playerRepository->get($message->adminId);

        $report = null;
        if ($message->reportId !== null) {
            $report = $this->conversationReportRepository->get($message->reportId);
        }

        $action = new ModerationAction(
            id: Uuid::uuid7(),
            targetPlayer: $item->player,
            admin: $admin,
            actionType: ModerationActionType::ListingRemoved,
            performedAt: new DateTimeImmutable(),
            report: $report,
            reason: $message->reason,
        );

        $this->moderationActionRepository->save($action);
        $this->sellSwapListItemRepository->delete($item);
    }
}
