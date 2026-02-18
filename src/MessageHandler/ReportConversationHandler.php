<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ConversationReport;
use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ReportConversation;
use SpeedPuzzling\Web\Repository\ConversationReportRepository;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\ReportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ReportConversationHandler
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private PlayerRepository $playerRepository,
        private ConversationReportRepository $conversationReportRepository,
    ) {
    }

    /**
     * @throws ConversationNotFound
     * @throws PlayerNotFound
     */
    public function __invoke(ReportConversation $message): void
    {
        $conversation = $this->conversationRepository->get($message->conversationId);
        $reporter = $this->playerRepository->get($message->reporterId);

        // Verify reporter is a participant
        $isParticipant = $conversation->initiator->id->toString() === $message->reporterId
            || $conversation->recipient->id->toString() === $message->reporterId;

        if (!$isParticipant) {
            throw new ConversationNotFound();
        }

        $report = new ConversationReport(
            id: Uuid::uuid7(),
            conversation: $conversation,
            reporter: $reporter,
            reason: $message->reason,
            status: ReportStatus::Pending,
            reportedAt: new DateTimeImmutable(),
        );

        $this->conversationReportRepository->save($report);
    }
}
