<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ModerationAction;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\WarnUser;
use SpeedPuzzling\Web\Repository\ConversationReportRepository;
use SpeedPuzzling\Web\Repository\ModerationActionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\ModerationActionType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class WarnUserHandler
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
    public function __invoke(WarnUser $message): void
    {
        $targetPlayer = $this->playerRepository->get($message->targetPlayerId);
        $admin = $this->playerRepository->get($message->adminId);

        $report = null;
        if ($message->reportId !== null) {
            $report = $this->conversationReportRepository->get($message->reportId);
        }

        $action = new ModerationAction(
            id: Uuid::uuid7(),
            targetPlayer: $targetPlayer,
            admin: $admin,
            actionType: ModerationActionType::Warning,
            performedAt: new DateTimeImmutable(),
            report: $report,
            reason: $message->reason,
        );

        $this->moderationActionRepository->save($action);
    }
}
