<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ModerationAction;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\BanFromMarketplace;
use SpeedPuzzling\Web\Repository\ConversationReportRepository;
use SpeedPuzzling\Web\Repository\ModerationActionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\ModerationActionType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BanFromMarketplaceHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ModerationActionRepository $moderationActionRepository,
        private ConversationReportRepository $conversationReportRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(BanFromMarketplace $message): void
    {
        $targetPlayer = $this->playerRepository->get($message->targetPlayerId);
        $admin = $this->playerRepository->get($message->adminId);

        $targetPlayer->banFromMarketplace();

        $report = null;
        if ($message->reportId !== null) {
            $report = $this->conversationReportRepository->get($message->reportId);
        }

        $action = new ModerationAction(
            id: Uuid::uuid7(),
            targetPlayer: $targetPlayer,
            admin: $admin,
            actionType: ModerationActionType::MarketplaceBan,
            performedAt: new DateTimeImmutable(),
            report: $report,
            reason: $message->reason,
        );

        $this->moderationActionRepository->save($action);
    }
}
