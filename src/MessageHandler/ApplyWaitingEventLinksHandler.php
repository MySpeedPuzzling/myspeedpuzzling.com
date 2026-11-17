<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\WaitingEventLink;
use SpeedPuzzling\Web\Message\ApplyWaitingEventLinks;
use SpeedPuzzling\Web\Query\GetWaitingEventLinks;
use SpeedPuzzling\Web\Repository\WaitingEventLinkRepository;
use SpeedPuzzling\Web\Services\EventWizard\WaitingEventLinksApplier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The safety net of WaitingEventLinksApplier (which runs right at every approval and publish): the waiting links whose
 * event or series is public now, or was rejected, found by SQL and applied / dropped by the same rule.
 */
#[AsMessageHandler]
readonly final class ApplyWaitingEventLinksHandler
{
    public function __construct(
        private GetWaitingEventLinks $getWaitingEventLinks,
        private WaitingEventLinkRepository $waitingEventLinkRepository,
        private WaitingEventLinksApplier $applier,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{applied: int, dropped: int}
     */
    public function __invoke(ApplyWaitingEventLinks $message): array
    {
        $rows = $this->getWaitingEventLinks->dueAndRejected();
        $links = array_values(array_filter(array_map(
            $this->waitingEventLinkRepository->get(...),
            [...$rows['rejected'], ...$rows['due']],
        ), static fn (null|WaitingEventLink $link): bool => $link !== null));

        $counts = $this->applier->apply($links);

        if ($counts['applied'] > 0 || $counts['dropped'] > 0) {
            $this->logger->info('Waiting event links applied by the cron', $counts);
        }

        return $counts;
    }
}
