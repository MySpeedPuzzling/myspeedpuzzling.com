<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\ConversationReportNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ResolveReport;
use SpeedPuzzling\Web\Repository\ConversationReportRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\ReportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ResolveReportHandler
{
    public function __construct(
        private ConversationReportRepository $conversationReportRepository,
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws ConversationReportNotFound
     * @throws PlayerNotFound
     */
    public function __invoke(ResolveReport $message): void
    {
        $report = $this->conversationReportRepository->get($message->reportId);
        $admin = $this->playerRepository->get($message->adminId);

        if ($message->status === ReportStatus::Dismissed) {
            $report->dismiss($admin, $message->adminNote);
        } else {
            $report->resolve($admin, $message->status, $message->adminNote);
        }
    }
}
