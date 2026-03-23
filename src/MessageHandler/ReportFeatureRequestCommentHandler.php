<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FeatureRequestCommentReport;
use SpeedPuzzling\Web\Exceptions\FeatureRequestCommentNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ReportFeatureRequestComment;
use SpeedPuzzling\Web\Repository\FeatureRequestCommentReportRepository;
use SpeedPuzzling\Web\Repository\FeatureRequestCommentRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\ReportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ReportFeatureRequestCommentHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private FeatureRequestCommentRepository $featureRequestCommentRepository,
        private FeatureRequestCommentReportRepository $featureRequestCommentReportRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws FeatureRequestCommentNotFound
     */
    public function __invoke(ReportFeatureRequestComment $message): void
    {
        $reporter = $this->playerRepository->get($message->reporterId);
        $comment = $this->featureRequestCommentRepository->get($message->commentId);

        $report = new FeatureRequestCommentReport(
            id: Uuid::uuid7(),
            comment: $comment,
            reporter: $reporter,
            reason: 'Reported by community member',
            status: ReportStatus::Pending,
            reportedAt: new DateTimeImmutable(),
        );

        $this->featureRequestCommentReportRepository->save($report);
    }
}
